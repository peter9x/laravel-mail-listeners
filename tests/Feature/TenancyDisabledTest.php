<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Schema;
use Mupy\MailListeners\Contracts\TenantResolver;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Tenancy\NullTenantResolver;
use Mupy\MailListeners\Tenancy\Tenancy;

it('keeps the schema without a tenant column', function (): void {
    expect(Schema::hasColumn('mail_listener_accounts', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('mail_listener_messages', 'tenant_id'))->toBeFalse()
        ->and(Schema::hasColumn('mail_listener_runs', 'tenant_id'))->toBeFalse();
});

it('has no tenant and runs the callbacks as they are', function (): void {
    $account = MailAccount::factory()->create();

    expect(app(TenantResolver::class))->toBeInstanceOf(NullTenantResolver::class)
        ->and(app(Tenancy::class)->current())->toBeNull()
        ->and(app(Tenancy::class)->run(1, fn (): string => 'ran'))->toBe('ran')
        ->and($account->refresh()->tenantKey())->toBeNull()
        ->and(MailAccount::query()->forTenant(null)->count())->toBe(1);
});

it('refuses --tenant', function (string $command): void {
    $this->artisan($command, ['--tenant' => '1'])
        ->expectsOutputToContain('The --tenant option needs multi-tenancy (mail-listeners.tenancy.enabled).')
        ->assertFailed();
})->with(['mail-listeners:poll', 'mail-listeners:sync']);
