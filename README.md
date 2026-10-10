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
- Works in normal apps and in [multi-tenant apps](#multi-tenant-apps) (optional, each app decides).

## Installation

> **Multi-tenant app?** Read [Multi-tenant apps](#multi-tenant-apps) first: tenancy must be enabled before migrating.

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

Configure the Microsoft Graph connections (application credentials, `Mail.Read`) and, optionally, register your context
events under a key (needed only to refer to them by key, e.g. from an admin UI; mailboxes can use the classes directly):

```php
'events' => [
    'supplier' => ['class' => App\Events\SupplierEmailReceived::class, 'label' => 'Suppliers'],
],

'microsoft_graph' => [
    'connections' => [
        // `tenant` is the Azure tenant id.
        'default' => ['tenant' => env('...'), 'client_id' => env('...'), 'client_secret' => env('...')],
    ],
],
```

The package tables use the default database connection; set `MAIL_LISTENERS_DB_CONNECTION` to use another one. The
`tenancy` options are only for [multi-tenant apps](#multi-tenant-apps).

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
    // Office 365: the credentials come from `mail-listeners.microsoft_graph.connections`.
    MailListeners::mailbox('invoices@example.com')
        ->name('Invoices')                                  // optional, default: the email
        ->connector('microsoft_graph', ['connection' => 'default', 'folder' => 'inbox'])
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
| `microsoft_graph` | `connection` (a key of `microsoft_graph.connections`, or one [resolved in code](#microsoft-graph-credentials-in-code), default `default`), `folder` (well-known name or id, default `inbox`; `*` = all folders) |
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
    'connector_settings' => ['connection' => 'default'],
    'events' => ['supplier'],
    'poll_cron' => '*/5 * * * *',     // every 5 minutes (the default), or e.g. '0 6 * * *'
]);
```

They are read by the same [commands](#commands).

### Microsoft Graph credentials in code

Instead of the config, the credentials of a connection can come from your code, e.g. from a settings table (of the
tenant of the account, with [multi-tenancy](#multi-tenancy)). Register a resolver in a service provider; returning
`null` falls back to `mail-listeners.microsoft_graph.connections`:

```php
use Mupy\MailListeners\Connectors\MicrosoftGraphConnector;
use Mupy\MailListeners\Models\MailAccount;

MicrosoftGraphConnector::resolveCredentialsUsing(
    fn (string $connection, MailAccount $account): ?array => GraphSetting::query()
        ->where('name', $connection)
        ->first()
        ?->only(['tenant', 'client_id', 'client_secret']),
);
```

With a resolver, any connection name is accepted when validating the settings (they cannot be listed); a connection
without credentials fails when the mailbox is read.

## Commands

Dates are `dd-mm-yyyy`, optionally with a time (`"dd-mm-yyyy HH:MM"`, quoted).

| Command                                                                 | Does                                                                                   |
|-------------------------------------------------------------------------|----------------------------------------------------------------------------------------|
| `mail-listeners:install`                                                | Publishes the config and the service provider, and registers the provider             |
| `mail-listeners:poll`                                                   | Queues the reading of the active mailboxes that are due (schedule it every minute)    |
| `mail-listeners:poll --account={id}`                                    | Queues the reading of one active mailbox now, even when it is not due                 |
| `mail-listeners:read {email} --from=dd-mm-yyyy --to=dd-mm-yyyy`         | Reads a mailbox within a period now and fires the events of the emails not read yet   |
| `mail-listeners:sync`                                                   | Syncs the mailboxes defined in code with the database now and lists the invalid ones  |
| `--tenant={tenant}` (poll, read, sync)                                  | With [multi-tenancy](#multi-tenancy): only the mailboxes of a tenant                  |

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

#### Progress

The number of emails in the period is only known at the end, so the progress is a counter, not a percentage:

```
Reading invoices@example.com from 01-01-2026 00:00 to 01-10-2026 00:00...
Connecting to invoices@example.com and searching messages...
  137 emails [new: 12 | already read: 125] 00:42 18 MiB — 14-03-2026 09:12 Extracto Via Verde...
```

- "Connecting…" shows up right away: the first batch of a large IMAP mailbox can take a while.
- The counter then shows the emails found so far (new / already read), elapsed time, memory, and the received date
  and subject of the current email.
- With `-v`, each new email is also listed (received date, sender, subject) above the counter.
- Without an interactive terminal (cron, `> read.log`, `--no-ansi`) there is no counter. Instead, a plain line is
  written every 25 emails and once at the end, e.g. `[00:42] 125 emails read (12 new)...`.

#### Resuming after a failure

If the mailbox fails partway (e.g. the connection drops), the command exits with a failure and shows how far it got:

```
The mail account could not be read: Connection lost
Read before the failure: 137 emails found, 12 new (events fired), 125 already read.
Last email processed: received at 14-03-2026 09:12.
Resume with: php artisan mail-listeners:read invoices@example.com --from="14-03-2026 09:12" --to="01-10-2026 00:00"
```

Run the `Resume with:` command to carry on from the last email processed. This is safe: the emails already read are
skipped, so none of their events fire twice.

## Multi-tenant apps

The package works in a normal app (nothing to configure) and in a multi-tenant app. In a multi-tenant app, the package
tables are shared by every tenant: the accounts, read emails and listener runs get a `tenant_id` column and the models
only see the rows of the current tenant. The package does not depend on any tenancy package: you tell it about your
tenants with a small class, using [stancl/tenancy](https://tenancyforlaravel.com),
[spatie/laravel-multitenancy](https://spatie.be/docs/laravel-multitenancy) or your own code.

Everything else in this README (events, listeners, connectors, commands) works the same way. The steps below are only
what changes.

### 1. Enable it before migrating

In `.env`, **before `php artisan migrate`** (the `tenant_id` column is only created when enabled):

```dotenv
MAIL_LISTENERS_TENANCY=true

# Type of your tenant keys: unsignedBigInteger (default), uuid, ulid or string.
MAIL_LISTENERS_TENANT_COLUMN_TYPE=unsignedBigInteger

# Only when your tenancy package uses a database per tenant: the central connection, where the package tables live
# (e.g. `landlord` with spatie/laravel-multitenancy, your central connection with stancl/tenancy).
MAIL_LISTENERS_DB_CONNECTION=landlord
```

Then install as usual (`mail-listeners:install`, `migrate`). The package tables are central tables: run them with your
central migrations, not with your tenant migrations.

<details>
<summary>Enabling it on an app that already ran the package migrations</summary>

Add the column with a migration of your app (after setting `MAIL_LISTENERS_TENANCY=true`):

```php
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Mupy\MailListeners\Tenancy\TenantColumn;

Schema::table('mail_listener_accounts', fn (Blueprint $table) => TenantColumn::add($table, ['email']));
Schema::table('mail_listener_messages', fn (Blueprint $table) => TenantColumn::add($table));
Schema::table('mail_listener_runs', fn (Blueprint $table) => TenantColumn::add($table));
```

The existing rows keep `tenant_id = null`: they become central accounts. Set their tenant to move them to one.

</details>

### 2. Tell the package about your tenants

Implement `Mupy\MailListeners\Contracts\TenantResolver`:

| Method                     | Must                                                                                                     |
|----------------------------|----------------------------------------------------------------------------------------------------------|
| `current()`                | Return the key of the current tenant, or `null` outside of a tenant. It is called on every query of the package models: never query the database here. |
| `all()`                    | Return the keys of every tenant (used by `mail-listeners:sync` for the [mailboxes of each tenant defined in code](#4-define-the-mailboxes-of-each-tenant)). |
| `run($tenant, $callback)`  | Run the callback in the context of the tenant and **restore the previous context** afterwards (no tenant, or another tenant). |

With stancl/tenancy (v3):

```php
namespace App\Support;

use App\Models\Tenant;
use Closure;
use Mupy\MailListeners\Contracts\TenantResolver;

final class MailListenersTenantResolver implements TenantResolver
{
    public function current(): int|string|null
    {
        return tenant()?->getTenantKey();
    }

    public function all(): iterable
    {
        return Tenant::query()->pluck('id');
    }

    public function run(int|string $tenant, Closure $callback): mixed
    {
        return tenancy()->run(Tenant::query()->findOrFail($tenant), fn () => $callback());
    }
}
```

With spatie/laravel-multitenancy (`IsTenant` resolves the `multitenancy.tenant_model` of your config):

```php
namespace App\Support;

use Closure;
use Mupy\MailListeners\Contracts\TenantResolver;
use Spatie\Multitenancy\Contracts\IsTenant;

final class MailListenersTenantResolver implements TenantResolver
{
    public function current(): int|string|null
    {
        return app(IsTenant::class)::current()?->getKey();
    }

    public function all(): iterable
    {
        return app(IsTenant::class)::query()->pluck('id');
    }

    public function run(int|string $tenant, Closure $callback): mixed
    {
        return app(IsTenant::class)::query()->findOrFail($tenant)->execute(fn () => $callback());
    }
}
```

Bind it in the `register()` method of `App\Providers\MailListenersServiceProvider` (published by
`mail-listeners:install`):

```php
public function register(): void
{
    $this->app->singleton(\Mupy\MailListeners\Contracts\TenantResolver::class, \App\Support\MailListenersTenantResolver::class);
}
```

### 3. Schedule and queues

- Schedule `mail-listeners:poll` exactly as in a normal app: **once, as a central command**. Do not run it per tenant
  (no `tenants:run` / `tenants:artisan`): it reads the due accounts of every tenant with a single query, and each
  account is then read in the context of its tenant.
- Run the queue workers as usual. The reading job (`PollMailAccount`) is dispatched without a tenant and switches to
  the tenant of its account itself; the listeners are dispatched inside the tenant, and each one switches to the tenant
  of its email again before running, so it never depends on your tenancy package restoring it on the worker.
- spatie/laravel-multitenancy with `queues_are_tenant_aware_by_default` enabled (its default): the reading job is
  dispatched without a tenant, and spatie deletes a tenant-aware job that has none. Add it to `not_tenant_aware_jobs`
  in `config/multitenancy.php` (the queued listeners stay tenant aware):

```php
'not_tenant_aware_jobs' => [
    \Mupy\MailListeners\Jobs\PollMailAccount::class,
],
```

### 4. Define the mailboxes of each tenant

Pick one or both ways, as in a normal app.

**From an admin UI of the tenant** (in the database): create `MailAccount` rows inside the tenant (e.g. in a tenant
request). They belong to the current tenant automatically and every query of the UI only sees the accounts of that
tenant:

```php
MailAccount::create([
    'name' => 'Invoices',
    'email' => 'invoices@acme.com',
    'connector' => 'microsoft_graph',
    'connector_settings' => ['connection' => 'default'],
    'events' => ['supplier'],
]);

MailAccount::query()->get(); // only the accounts of the current tenant
```

**In code**, e.g. from the settings of each tenant, in the `tenantMailboxes()` method of
`App\Providers\MailListenersServiceProvider`:

```php
use Mupy\MailListeners\Facades\MailListeners;
use Mupy\MailListeners\MailboxDefinitions;

protected function tenantMailboxes(): void
{
    MailListeners::tenantMailboxes(function (MailboxDefinitions $mailboxes, int|string $tenant): void {
        // Runs in the context of the tenant: tenant models and settings can be read here.
        $settings = MailSettings::query()->first();

        if ($settings?->invoices_email === null) {
            return;
        }

        $mailboxes->mailbox($settings->invoices_email)
            ->connector('microsoft_graph', ['connection' => 'default', 'folder' => 'inbox'])
            ->events(SupplierEmailReceived::class)
            ->pollEvery(5);
    });
}
```

Unlike the central mailboxes, they are **not synced by the poll** (that would load every tenant every minute). Sync
them:

- on deploy: `php artisan mail-listeners:sync` (the central mailboxes and those of every tenant);
- when the data they come from changes: `MailListeners::syncTenant($tenant)`, e.g. in an observer of the tenant
  settings (it only writes when the definitions changed);
- for one tenant from the terminal: `php artisan mail-listeners:sync --tenant=1`.

A mailbox removed from the code of a tenant is deactivated in that tenant only. The same email can be read by several
tenants.

`MailListeners::mailbox()` still works and defines **central** mailboxes (of no tenant): their events and listeners run
outside of any tenant.

### 5. Microsoft Graph credentials of each tenant

Keep shared credentials in `mail-listeners.microsoft_graph.connections`, or give each tenant its own with a
[resolver](#microsoft-graph-credentials-in-code). It is called in the context of the tenant of the account, so it can
read the tenant settings:

```php
MicrosoftGraphConnector::resolveCredentialsUsing(function (string $connection, MailAccount $account): ?array {
    $settings = MailSettings::query()->first(); // settings of the current tenant ($account->tenantKey())

    return $settings?->graph_client_id === null ? null : [
        'tenant' => $settings->graph_azure_tenant,
        'client_id' => $settings->graph_client_id,
        'client_secret' => $settings->graph_client_secret,
    ];
});
```

IMAP credentials are already stored (encrypted) on each account.

### 6. Listeners

Nothing changes: listeners run in the context of the tenant of the email, so they can use the tenant models directly.
`$email->tenantId` holds the tenant and `$email->account()` the account.

### Central admin and tenant lifecycle

- Outside of a tenant (e.g. a central admin), queries see every tenant. Choose explicitly with
  `MailAccount::query()->forTenant($tenant)` (`null`: the central accounts) or `->allTenants()`;
  `$account->tenantKey()` returns the tenant of a row.
- The tenant of a row is never mass assignable: it is set from the current tenant when the row is created.
- When a tenant is deleted, delete its accounts (their read emails and listener runs are deleted with them):
  `MailAccount::query()->forTenant($tenant)->delete();`
- Commands: `mail-listeners:poll --tenant=1` and `mail-listeners:sync --tenant=1` only handle a tenant;
  `mail-listeners:read` asks for `--tenant` when several tenants read the email.
- `model:prune` (read emails retention) prunes every tenant: schedule it once, as a central command.

### Testing a multi-tenant app

Use the same [fake connector](#testing), and create the accounts inside a tenant (or with the factory state
`MailAccount::factory()->forTenant($tenant)`). To sync the mailboxes of a tenant defined in code:
`MailListeners::sync($tenant)`.

## Upgrading

- Microsoft Graph: the `microsoft_graph.tenants` config is now `microsoft_graph.connections` (and `default_tenant` is
  now `default_connection`), and the `tenant` connector setting of the accounts is now `connection`: mailboxes defined
  in code are updated by the next sync, rename it yourself on the accounts created in the database.
- `InboundEmail::withMessageId($id)` is now `InboundEmail::withMessage($message)`.

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
