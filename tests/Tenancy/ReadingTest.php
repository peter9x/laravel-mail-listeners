<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mupy\MailListeners\Contracts\TenantResolver;
use Mupy\MailListeners\Enums\ListenerRunStatus;
use Mupy\MailListeners\Facades\MailListeners;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailListenerRun;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Testing\FakeConnector;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;
use Mupy\MailListeners\Tests\Support\TenantRecordingListener;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
    $this->connector = FakeConnector::swap();
    TenantRecordingListener::$tenants = [];
});

it('queues the due accounts of every tenant with a single query', function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 11:50:00'));
    $central = MailAccount::factory()->create(['last_polled_at' => null]);
    $first = MailAccount::factory()->forTenant(1)->create(['last_polled_at' => null]);
    $second = MailAccount::factory()->forTenant(2)->create(['last_polled_at' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));

    // The central mailboxes defined in code were synced on a previous poll.
    MailListeners::sync();

    DB::enableQueryLog();
    $this->artisan('mail-listeners:poll')->assertSuccessful();
    $accountQueries = collect(DB::getQueryLog())->filter(fn (array $query): bool => str_contains($query['query'], 'mail_listener_accounts'));

    expect($accountQueries->pluck('query')->all())->toBe(['select * from "mail_listener_accounts" where "active" = ?']);
    Queue::assertPushed(PollMailAccount::class, 3);

    Queue::fake();
    $this->artisan('mail-listeners:poll', ['--tenant' => '2'])->assertSuccessful();

    Queue::assertPushed(PollMailAccount::class, 1);
    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($second));
    expect([$central->id, $first->id])->not->toContain($second->id);
});

it('reads an account in the context of its tenant, recording the tenant on the emails and listener runs', function (): void {
    Event::listen(SupplierEmailReceived::class, TenantRecordingListener::class);
    $seen = [];
    Event::listen(SupplierEmailReceived::class, function (SupplierEmailReceived $event) use (&$seen): void {
        $seen[] = [$event->email->tenantId, app(TenantResolver::class)->current()];
    });
    $account = MailAccount::factory()->forTenant(2)->withEvents(['supplier'])->create();
    $this->connector->messages = [FakeConnector::makeEmail($account)];

    PollMailAccount::dispatchSync($account);

    $message = MailMessage::query()->sole();
    $run = MailListenerRun::query()->sole();

    expect($seen)->toBe([[2, 2]])
        ->and(TenantRecordingListener::$tenants)->toBe([2])
        ->and($message->tenant_id)->toBe(2)
        ->and($run->tenant_id)->toBe(2)
        ->and($run->status)->toBe(ListenerRunStatus::PROCESSED)
        ->and($this->tenants()->current)->toBeNull();
});

it('runs a queued listener in the tenant of the email, even without a tenant on the worker', function (): void {
    $account = MailAccount::factory()->forTenant(5)->create();
    $email = FakeConnector::makeEmail($account);
    $message = MailMessage::factory()->for($account, 'account')->create(['tenant_id' => 5, 'dedup_key' => $email->dedupKey()]);

    (new TenantRecordingListener())->handle(new SupplierEmailReceived($email->withMessage($message)));

    expect(TenantRecordingListener::$tenants)->toBe([5])
        ->and(MailListenerRun::query()->sole()->tenant_id)->toBe(5);
});

it('asks for the tenant when several tenants read the email', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    MailAccount::factory()->forTenant(1)->withEvents(['supplier'])->create(['email' => 'shared@example.com']);
    $second = MailAccount::factory()->forTenant(2)->withEvents(['supplier'])->create(['email' => 'shared@example.com']);
    $this->connector->messages = [FakeConnector::makeEmail($second)];

    $this->artisan('mail-listeners:read', ['email' => 'shared@example.com'])
        ->expectsOutputToContain('The mail account shared@example.com exists in several tenants: choose one with --tenant.')
        ->assertFailed();

    $this->artisan('mail-listeners:read', ['email' => 'shared@example.com', '--tenant' => '2'])
        ->expectsOutputToContain('1 emails found: 1 new (events fired), 0 already read.')
        ->assertSuccessful();

    expect(MailMessage::query()->sole()->tenant_id)->toBe(2)
        ->and($this->tenants()->ran)->toContain(2)
        ->and($this->tenants()->ran)->not->toContain(1);
});

it('tells how to resume the reading of an account of a tenant', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->forTenant(7)->withEvents(['supplier'])->create(['email' => 'invoices@example.com']);
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['receivedAt' => CarbonImmutable::parse('2026-01-10 09:12')]),
        FakeConnector::makeEmail($account, ['receivedAt' => CarbonImmutable::parse('2026-01-11 09:12')]),
    ];
    $this->connector->failAfter = 1;

    $this->artisan('mail-listeners:read', ['email' => 'invoices@example.com', '--from' => '01-01-2026', '--to' => '31-01-2026'])
        ->expectsOutputToContain('Resume with: php artisan mail-listeners:read invoices@example.com --tenant=7 --from="10-01-2026 09:12" --to="01-02-2026 00:00"')
        ->assertFailed();
});
