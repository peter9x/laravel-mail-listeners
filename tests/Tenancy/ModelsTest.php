<?php

declare(strict_types=1);

use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailListenerRun;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Tenancy\TenantColumn;
use Mupy\MailListeners\Testing\FakeConnector;

it('adds the tenant column to the package tables', function (): void {
    expect(Schema::hasColumn('mail_listener_accounts', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('mail_listener_messages', 'tenant_id'))->toBeTrue()
        ->and(Schema::hasColumn('mail_listener_runs', 'tenant_id'))->toBeTrue()
        ->and(collect(Schema::getIndexes('mail_listener_accounts'))->pluck('columns')->all())->toContain(['tenant_id', 'email']);
});

it('creates the tenant column with the configured type', function (string $type, string $columnType): void {
    config(['mail-listeners.tenancy.column_type' => $type]);

    Schema::create('tenant_column_test', fn (Blueprint $table) => TenantColumn::add($table));

    $column = collect(Schema::getColumns('tenant_column_test'))->firstWhere('name', 'tenant_id');

    expect($column['type_name'])->toBe($columnType)
        ->and($column['nullable'])->toBeTrue();
})->with([
    'integer' => ['unsignedBigInteger', 'integer'],
    'uuid' => ['uuid', 'varchar'],
    'ulid' => ['ulid', 'varchar'],
    'string' => ['string', 'varchar'],
]);

it('rejects an unknown tenant column type', function (): void {
    config(['mail-listeners.tenancy.column_type' => 'json']);

    expect(fn () => Schema::create('tenant_column_test', fn (Blueprint $table) => TenantColumn::add($table)))
        ->toThrow(InvalidArgumentException::class, 'Invalid mail-listeners.tenancy.column_type [json]');
});

it('limits the queries to the current tenant and sets it on the new rows', function (): void {
    $central = MailAccount::factory()->create();
    $first = $this->tenants()->run(1, fn (): MailAccount => MailAccount::factory()->create());
    $second = $this->tenants()->run(2, fn (): MailAccount => MailAccount::factory()->create());

    expect($central->tenant_id)->toBeNull()
        ->and($first->refresh()->tenant_id)->toBe(1)
        ->and($second->refresh()->tenant_id)->toBe(2)
        // Without a current tenant (scheduler, workers, central requests) nothing is filtered.
        ->and(MailAccount::query()->count())->toBe(3)
        ->and($this->tenants()->run(1, fn () => MailAccount::query()->pluck('id')->all()))->toBe([$first->id])
        ->and($this->tenants()->run(1, fn () => MailAccount::query()->forTenant(2)->pluck('id')->all()))->toBe([$second->id])
        ->and($this->tenants()->run(1, fn () => MailAccount::query()->forTenant(null)->pluck('id')->all()))->toBe([$central->id])
        ->and($this->tenants()->run(1, fn () => MailAccount::query()->allTenants()->count()))->toBe(3);
});

it('keeps a tenant set explicitly, even null', function (): void {
    $account = $this->tenants()->run(1, fn (): MailAccount => MailAccount::factory()->forTenant(null)->create());
    $other = $this->tenants()->run(1, fn (): MailAccount => MailAccount::factory()->forTenant(2)->create());

    expect($account->refresh()->tenant_id)->toBeNull()
        ->and($other->refresh()->tenant_id)->toBe(2);
});

it('does not let the tenant of an account be mass assigned', function (): void {
    $account = MailAccount::factory()->forTenant(1)->create();

    // Strict mode (see TestCase) rejects it; without strict mode it is silently ignored.
    expect(fn () => $account->update(['tenant_id' => 2]))->toThrow(MassAssignmentException::class);

    Model::preventSilentlyDiscardingAttributes(false);
    $account->update(['tenant_id' => 2, 'name' => 'Renamed']);

    expect($account->refresh()->tenant_id)->toBe(1)
        ->and($account->name)->toBe('Renamed');
});

it('restores the account of a queued poll in any tenant context', function (): void {
    $account = MailAccount::factory()->forTenant(1)->create();
    $job = serialize(new PollMailAccount($account));

    $restored = $this->tenants()->run(2, fn (): PollMailAccount => unserialize($job));

    expect($restored->account->is($account))->toBeTrue()
        ->and($restored->tags())->toBe(['MailListeners', 'tenant:1', $account->email, 'PollMailAccount']);
});

it('keeps the tenant of an email in the queue payload', function (): void {
    $account = MailAccount::factory()->forTenant(3)->create();
    $email = FakeConnector::makeEmail($account);
    $message = MailMessage::factory()->for($account, 'account')->create(['tenant_id' => 3, 'dedup_key' => $email->dedupKey()]);

    $restored = unserialize(serialize($email->withMessage($message)));

    expect($restored)->toBeInstanceOf(InboundEmail::class)
        ->and($restored->tenantId)->toBe(3)
        ->and($restored->messageId)->toBe($message->id)
        ->and($this->tenants()->run(1, fn (): MailAccount => $restored->account())->is($account))->toBeTrue();
});

it('prunes the old emails of every tenant', function (): void {
    $account = MailAccount::factory()->forTenant(1)->create();
    MailMessage::factory()->for($account, 'account')->create(['tenant_id' => 1, 'received_at' => now()->subYear()]);
    MailMessage::factory()->for(MailAccount::factory()->forTenant(2), 'account')->create(['tenant_id' => 2, 'received_at' => now()->subYear()]);

    expect($this->tenants()->run(1, fn (): int => (new MailMessage())->prunable()->count()))->toBe(2)
        ->and(MailListenerRun::query()->count())->toBe(0);
});

it('keeps its connection when a tenant switches the default connection (a database per tenant)', function (): void {
    config([
        'database.connections.tenant_database' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        'mail-listeners.database.connection' => 'testing',
    ]);
    $account = MailAccount::factory()->forTenant(1)->create();

    $count = $this->tenants()->run(1, function (): int {
        DB::setDefaultConnection('tenant_database');

        try {
            return MailAccount::query()->count();
        } finally {
            DB::setDefaultConnection('testing');
        }
    });

    expect($count)->toBe(1)
        ->and($account->getConnectionName())->toBe('testing');
});
