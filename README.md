# Laravel Mail Listeners

Read mailboxes (Microsoft Graph / Office 365, IMAP) periodically and turn every new email into Laravel events,
handled by queued listeners that each decide whether the email matters to them.

The package provides the base: connectors, the reading system, the database (accounts, read emails, listener runs),
the base event and the base listener. Each app builds the rest on top of it: its own context events, listeners,
admin UI, permissions and schedule.

- Mailboxes are never changed (read-only, emails are not marked as read).
- Every listener runs in its own queued job: a slow or failing listener never delays the others.
- Read emails are tracked (metadata only), so an email is never processed twice by a listener.
- Attachments are never stored: listeners download them lazily, in memory.

## Installation

```bash
composer require peter9x/laravel-mail-listeners
php artisan vendor:publish --tag=mail-listeners-config
php artisan migrate
```

## Configuration (`config/mail-listeners.php`)

Configure the Microsoft Graph tenants (application credentials, `Mail.Read`) and, optionally, register your context
events under a key (needed only to refer to them by key, e.g. from an admin UI; mailboxes can use the classes directly):

```php
'events' => [
    'supplier' => ['class' => App\Events\SupplierEmailReceived::class, 'label' => 'Suppliers'],
],

'microsoft_graph' => [
    'tenants' => [
        'default' => ['tenant' => env('...'), 'client_id' => env('...'), 'client_secret' => env('...')],
    ],
],
```

## Usage

1. Create a context event:

```php
use Mupy\MailListeners\Events\EmailReceived;

final class SupplierEmailReceived extends EmailReceived {}
```

2. Create a listener and register it (e.g. in a service provider):

```php
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\MailListener;

final class InvoiceEmailListener extends MailListener
{
    public ?string $queue = 'long'; // optional

    public static function label(): string
    {
        return 'Invoices';
    }

    public function interestedIn(InboundEmail $email): bool
    {
        return $email->hasAttachments && $email->subjectContains('invoice');
    }

    protected function process(InboundEmail $email): void
    {
        foreach ($email->attachments() as $attachment) {
            // $attachment->name, $attachment->content...
        }
    }
}

Event::listen(SupplierEmailReceived::class, InvoiceEmailListener::class);
```

3. Define the mailboxes to read and the events each one fires: [in code](#mailboxes-defined-in-code) (no database
   rows to insert) or [in the database](#mailboxes-in-the-database) (e.g. managed from an admin UI).

4. Schedule the polling (e.g. in `routes/console.php`):

```php
Schedule::command('mail-listeners:poll')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [Mupy\MailListeners\Models\MailMessage::class]])->daily();
```

Every minute `mail-listeners:poll` queues the reading of the mailboxes whose polling interval has elapsed. For every new
email, each event of the mailbox is fired and each listener of the event runs in its own queued job. Run a queue worker
for the `mail-listeners` and `mail-listeners-listeners` queues (see `queues` in the config).

## Mailboxes defined in code

Define the mailboxes in the `boot()` of a service provider (e.g. `AppServiceProvider`): email, connector with its
settings, and the events fired for every new email. Nothing has to be inserted in the database.

```php
use App\Events\HrEmailReceived;
use App\Events\SupplierEmailReceived;
use Mupy\MailListeners\Facades\MailListeners;

public function boot(): void
{
    // Office 365: the tenant credentials come from `mail-listeners.microsoft_graph.tenants`.
    MailListeners::mailbox('invoices@example.com')
        ->name('Invoices')                                  // optional, default: the email
        ->connector('microsoft_graph', ['tenant' => 'default', 'folder' => 'inbox'])
        ->events(SupplierEmailReceived::class)              // event classes and/or keys of `mail-listeners.events`
        ->pollEvery(5)                                      // optional, minutes, default 5
        ->readFrom('2026-10-01')                            // optional, a fixed date (default: when first synced)
        ->active((bool) env('MAIL_INVOICES_ACTIVE', true)); // optional, default true

    // IMAP: user and password.
    MailListeners::mailbox('hr@example.com')
        ->connector('imap', [
            'host' => 'imap.example.com',
            'port' => 993,
            'encryption' => 'ssl',
            'username' => 'hr@example.com',
            'password' => env('MAIL_HR_PASSWORD'),
        ])
        ->events(HrEmailReceived::class, SupplierEmailReceived::class);
}
```

Event classes must extend `Mupy\MailListeners\Events\EmailReceived`; they do not have to be registered in the config.

Connector settings:

| Connector         | Settings                                                                                                                                                          |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `microsoft_graph` | `tenant` (a key of `microsoft_graph.tenants`, default `default`), `folder` (well-known name or id, default `inbox`)                                                 |
| `imap`            | `host`, `port` (993), `encryption` (`ssl`, `tls`, `starttls`, `none`), `validate_cert` (true), `username`, `password`, `folder` (default `INBOX`)                  |

Then read the mailboxes:

```bash
# Read the mailboxes now (also what the schedule runs every minute)
php artisan mail-listeners:poll

# Read a past period of a mailbox, firing the events of the emails not read yet
php artisan mail-listeners:read invoices@example.com --from=01-10-2026 --to=07-10-2026

# Sync the definitions with the database right away (e.g. on deploy) and list the invalid ones
php artisan mail-listeners:sync
```

How it works:

- The code is the source of truth; the `mail_listener_accounts` table only keeps the state of each mailbox (polling
  cursor, read emails, listener runs), so emails are never processed twice and failed listeners are retried.
- `mail-listeners:poll` and `mail-listeners:read` sync the definitions only when they change (a fingerprint is kept in
  the cache): a poll with unchanged definitions runs no extra query.
- Synced accounts are flagged `managed` (`$account->isManaged()`): their settings are overwritten on every sync, so
  admin UIs should show them read-only. Connector settings (e.g. the IMAP password) are stored encrypted.
- A mailbox removed from the code is deactivated (its history is kept) and reactivated if it is defined again.
- Invalid definitions (unknown connector or event, missing settings) are logged and reported by the commands, without
  blocking the other mailboxes.

## Mailboxes in the database

Mailboxes can also be created as `Mupy\MailListeners\Models\MailAccount` rows, e.g. from an admin UI
(`ConnectorManager::options()` and `EventRegistry::options()` feed the selects):

```php
MailAccount::create([
    'name' => 'Suppliers',
    'email' => 'suppliers@example.com',
    'connector' => 'microsoft_graph',
    'connector_settings' => ['tenant' => 'default'],
    'events' => ['supplier'],
    'poll_interval_minutes' => 5,
]);
```

They are read by the same commands. Read one now with `php artisan mail-listeners:poll --account={id}`.

## Logging

The package logs to its own `mail-listeners` channel (`storage/logs/mail-listeners-*.log`, daily, 14 days), registered
unless your `config/logging.php` already defines a `mail-listeners` channel. Use another channel with
`MAIL_LISTENERS_LOG_CHANNEL=stack`, and change the level with `MAIL_LISTENERS_LOG_LEVEL`.

## Testing

In your tests, replace a connector with an in-memory fake:

```php
use Mupy\MailListeners\Testing\FakeConnector;

$fake = FakeConnector::swap('microsoft_graph');
$fake->withMessages(FakeConnector::makeEmail($account, ['subject' => 'Invoice 42']));
```

For mailboxes defined in code, sync them first to get their account:

```php
MailListeners::sync();
$account = MailAccount::query()->where('email', 'invoices@example.com')->sole();
```

Run the package tests with `composer test`.
