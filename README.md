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

Register the context events your app fires, and the Microsoft Graph tenants (application credentials, `Mail.Read`):

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

3. Create a `Mupy\MailListeners\Models\MailAccount` (connector `microsoft_graph` or `imap`, its settings and the
   event keys it fires), and schedule the polling:

```php
Schedule::command('mail-listeners:poll')->everyMinute()->withoutOverlapping()->onOneServer();
Schedule::command('model:prune', ['--model' => [Mupy\MailListeners\Models\MailMessage::class]])->daily();
```

Read a past period with `php artisan mail-listeners:read mailbox@example.com --from=01-01-2026 --to=31-01-2026`.

## Testing

In your tests, replace a connector with an in-memory fake:

```php
use Mupy\MailListeners\Testing\FakeConnector;

$fake = FakeConnector::swap('microsoft_graph');
$fake->withMessages(FakeConnector::makeEmail($account, ['subject' => 'Invoice 42']));
```

Run the package tests with `composer test`.
