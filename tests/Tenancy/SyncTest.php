<?php

declare(strict_types=1);

use Illuminate\Support\Facades\DB;
use Mupy\MailListeners\Contracts\TenantResolver;
use Mupy\MailListeners\Facades\MailListeners;
use Mupy\MailListeners\MailboxDefinitions;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;

/**
 * Number of queries on the accounts table run by the callback.
 */
function tenantAccountQueries(callable $callback): int
{
    $count = 0;
    DB::listen(function ($query) use (&$count): void {
        if (str_contains($query->sql, 'mail_listener_accounts')) {
            $count++;
        }
    });

    $callback();

    return $count;
}

beforeEach(function (): void {
    $this->tenants()->tenants = [1, 2];
    $this->shared = [1 => true, 2 => true];
    $this->seenTenants = [];

    MailListeners::mailbox('central@example.com')
        ->connector('microsoft_graph')
        ->events(SupplierEmailReceived::class);

    MailListeners::tenantMailboxes(function (MailboxDefinitions $mailboxes, int|string $tenant): void {
        $this->seenTenants[] = [$tenant, app(TenantResolver::class)->current()];

        $mailboxes->mailbox("invoices@tenant{$tenant}.example.com")
            ->connector('microsoft_graph', ['connection' => 'default'])
            ->events(SupplierEmailReceived::class);

        if ($this->shared[$tenant]) {
            $mailboxes->mailbox('shared@example.com')
                ->connector('microsoft_graph')
                ->events(SupplierEmailReceived::class);
        }
    });
});

/**
 * @return list<string>
 */
function activeEmailsOf(int|string|null $tenant): array
{
    return MailAccount::query()->forTenant($tenant)->active()->orderBy('email')->pluck('email')->all();
}

it('syncs the central mailboxes and those of every tenant, in the context of each tenant', function (): void {
    $this->artisan('mail-listeners:sync')
        ->expectsOutputToContain('1 mail accounts defined in code: 1 created, 0 updated, 0 deactivated, 0 invalid.')
        ->expectsOutputToContain('[tenant 1] 2 mail accounts defined in code: 2 created, 0 updated, 0 deactivated, 0 invalid.')
        ->expectsOutputToContain('[tenant 2] 2 mail accounts defined in code: 2 created, 0 updated, 0 deactivated, 0 invalid.')
        ->assertSuccessful();

    expect(activeEmailsOf(null))->toBe(['central@example.com'])
        // The same email may be read by several tenants.
        ->and(activeEmailsOf(1))->toBe(['invoices@tenant1.example.com', 'shared@example.com'])
        ->and(activeEmailsOf(2))->toBe(['invoices@tenant2.example.com', 'shared@example.com'])
        ->and(MailAccount::query()->where('managed', true)->count())->toBe(5)
        ->and(collect($this->seenTenants)->unique(fn (array $seen): string => implode(',', $seen))->values()->all())->toBe([[1, 1], [2, 2]]);
});

it('syncs only the given tenant, deactivating only its mailboxes no longer defined', function (): void {
    MailListeners::syncAll();
    $this->shared[1] = false;

    $this->artisan('mail-listeners:sync', ['--tenant' => '1'])
        ->expectsOutputToContain('[tenant 1] 1 mail accounts defined in code: 0 created, 0 updated, 1 deactivated, 0 invalid.')
        ->assertSuccessful();

    expect(activeEmailsOf(1))->toBe(['invoices@tenant1.example.com'])
        ->and(activeEmailsOf(2))->toBe(['invoices@tenant2.example.com', 'shared@example.com'])
        ->and(activeEmailsOf(null))->toBe(['central@example.com']);
});

it('syncs a tenant only when its definitions changed', function (): void {
    expect(MailListeners::syncTenant(1)['created'])->toBe(['invoices@tenant1.example.com', 'shared@example.com'])
        ->and(tenantAccountQueries(fn () => expect(MailListeners::syncTenant(1))->toBeNull()))->toBe(0);

    $this->shared[1] = false;

    expect(MailListeners::syncTenant(1)['deactivated'])->toBe(['shared@example.com']);
});

it('keeps the central mailboxes central when synced inside a tenant', function (): void {
    $this->tenants()->run(1, fn () => MailListeners::sync());

    expect(activeEmailsOf(null))->toBe(['central@example.com'])
        ->and(activeEmailsOf(1))->toBe([]);
});

it('never evaluates the mailboxes of the tenants on poll', function (): void {
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    expect($this->seenTenants)->toBe([])
        ->and(activeEmailsOf(null))->toBe(['central@example.com'])
        ->and(MailAccount::query()->whereNotNull('tenant_id')->count())->toBe(0);
});

it('refuses --tenant without multi-tenancy', function (): void {
    config(['mail-listeners.tenancy.enabled' => false]);

    $this->artisan('mail-listeners:sync', ['--tenant' => '1'])
        ->expectsOutputToContain('The --tenant option needs multi-tenancy (mail-listeners.tenancy.enabled).')
        ->assertFailed();
});
