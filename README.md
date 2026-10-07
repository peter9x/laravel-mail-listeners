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
php artisan mail-listeners:install
php artisan migrate
```

`mail-listeners:install` publishes the config and `app/Providers/MailListenersServiceProvider.php` (where the
[mailboxes are defined](#mailboxes-defined-in-code)), and registers the provider in `bootstrap/providers.php`. Running it
again never overwrites a published provider. To publish them one by one:

```bash
php artisan vendor:publish --tag=mail-listeners-config
php artisan vendor:publish --tag=mail-listeners-provider
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

Define the mailboxes in the `mailboxes()` method of the published `App\Providers\MailListenersServiceProvider` (or in
the `boot()` of any other service provider): email, connector with its settings, and the events fired for every new
email. Nothing has to be inserted in the database.

```php
use App\Events\HrEmailReceived;
use App\Events\SupplierEmailReceived;
use Mupy\MailListeners\Facades\MailListeners;

protected function mailboxes(): void
{
    // Office 365: the tenant credentials come from `mail-listeners.microsoft_graph.tenants`.
    MailListeners::mailbox('invoices@example.com')
        ->name('Invoices')                                  // optional, default: the email
        ->connector('microsoft_graph', ['tenant' => 'default', 'folder' => 'inbox'])
        ->events(SupplierEmailReceived::class)              // event classes and/or keys of `mail-listeners.events`
        ->pollEvery(5)                                      // optional, every 5 minutes (the default)
        ->readFrom('01-10-2026')                            // optional, a fixed date (default: when first synced)
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
        ->events(HrEmailReceived::class, SupplierEmailReceived::class)
        ->cron('0 6 * * *');                                // every day at 06:00 instead of every N minutes
}
```

Event classes must extend `Mupy\MailListeners\Events\EmailReceived`; they do not have to be registered in the config.

When a mailbox is read is a single cron expression (the `poll_cron` column), set with either:

- `pollEvery(N)`: every N minutes, aligned to the clock (default 5, stored as `*/5 * * * *`). N must divide an hour
  (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30) or be whole hours dividing a day (60 → `0 * * * *`, 120 → `0 */2 * * *`...).
- `cron('0 6 * * *')`: any cron expression (here every day at 06:00; `0 6,18 * * 1-5` is 06:00 and 18:00 on weekdays).

The expression is evaluated in `app.schedule_timezone` (or `app.timezone`), like the Laravel scheduler, and relies on
`mail-listeners:poll` being scheduled every minute. A run missed while the scheduler was down is caught up on the next
poll, and a new mailbox waits for its first run time. The last of `pollEvery()` / `cron()` called wins.

Connector settings:

| Connector         | Settings                                                                                                                                                          |
|-------------------|-------------------------------------------------------------------------------------------------------------------------------------------------------------------|
| `microsoft_graph` | `tenant` (a key of `microsoft_graph.tenants`, default `default`), `folder` (well-known name or id, default `inbox`; `*` = all folders)                                          |
| `imap`            | `host`, `port` (993), `encryption` (`ssl`, `tls`, `starttls`, `none`), `validate_cert` (true), `username`, `password`, `folder` (default `INBOX`)                  |

The scheduled `mail-listeners:poll` then reads them; see [Commands](#commands) to read a mailbox now or a past period.

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
    'poll_cron' => '*/5 * * * *',     // every 5 minutes (the default), or e.g. '0 6 * * *'
]);
```

They are read by the same [commands](#commands).

## Commands

Dates are `dd-mm-yyyy`, optionally with a time (`"dd-mm-yyyy HH:MM"`, quoted).

| Command                                                                 | Does                                                                                   |
|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------|
| `mail-listeners:install`                                                | Publishes the config and the service provider, and registers the provider             |
| `mail-listeners:poll`                                                   | Queues the reading of the active mailboxes that are due (schedule it every minute)    |
| `mail-listeners:poll --account={id}`                                    | Queues the reading of one active mailbox now, even when it is not due                 |
| `mail-listeners:read {email} --from=dd-mm-yyyy --to=dd-mm-yyyy`         | Reads a mailbox within a period now and fires the events of the emails not read yet   |
| `mail-listeners:sync`                                                   | Syncs the mailboxes defined in code with the database now and lists the invalid ones  |

### Reading a period

```bash
# The whole of January: from 01-01-2026 00:00 to the end of 31-01-2026
php artisan mail-listeners:read invoices@example.com --from=01-01-2026 --to=31-01-2026

# With times
php artisan mail-listeners:read invoices@example.com --from="01-01-2026 08:00" --to="01-01-2026 18:00"

# The last 24 hours
php artisan mail-listeners:read invoices@example.com
```

- `--from`: a day starts at 00:00. Default: 24 hours before the end.
- `--to`: a day includes the whole day. Default: now.
- Works with mailboxes defined in code (synced first when needed) and in the database; an inactive mailbox is read
  anyway, with a warning.
- The mailbox is read right away by the command; the listeners of the fired events run on the queue as usual.
- Emails already read are skipped, so a period can be read again safely, and the polling cursor of the mailbox is left
  untouched: the scheduled polling goes on as before. `mail-listeners:poll --account={id}` instead reads from the
  cursor and moves it forward.
- Prints how many emails were found, how many were new (events fired) and how many were already read.

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
