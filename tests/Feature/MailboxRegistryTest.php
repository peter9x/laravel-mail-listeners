<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mupy\MailListeners\Facades\MailListeners;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\MailboxRegistry;
use Mupy\MailListeners\MailReader;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Testing\FakeConnector;
use Mupy\MailListeners\Tests\Support\Events\HrEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;

beforeEach(function (): void {
    Queue::fake();
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
});

/**
 * Number of queries on the accounts table run by the callback.
 */
function accountQueries(callable $callback): int
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

it('creates the accounts defined in code on poll and queues their reading', function (): void {
    MailListeners::mailbox(' Invoices@Example.com ')
        ->name('Invoices')
        ->connector('microsoft_graph', ['tenant' => 'other', 'folder' => 'invoices'])
        ->events(SupplierEmailReceived::class, 'hr')
        ->pollEvery(10)
        ->readFrom('2026-10-01');

    $this->artisan('mail-listeners:poll')->assertSuccessful();
    // A new account waits for its first run time.
    Queue::assertNothingPushed();

    $this->travel(10)->minutes();
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    $account = MailAccount::query()->sole();

    expect($account->email)->toBe('invoices@example.com')
        ->and($account->name)->toBe('Invoices')
        ->and($account->connector)->toBe('microsoft_graph')
        ->and($account->connector_settings)->toBe(['tenant' => 'other', 'folder' => 'invoices'])
        ->and($account->events)->toBe([SupplierEmailReceived::class, 'hr'])
        ->and($account->poll_cron)->toBe('*/10 * * * *')
        ->and($account->read_from->toDateString())->toBe('2026-10-01')
        ->and($account->active)->toBeTrue()
        ->and($account->isManaged())->toBeTrue()
        ->and($account->eventClasses())->toBe([SupplierEmailReceived::class, HrEmailReceived::class]);

    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($account));
});

it('runs no query to sync when the definitions did not change', function (): void {
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier');
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    // Only the poll's own query on the active accounts.
    expect(accountQueries(fn () => $this->artisan('mail-listeners:poll')->assertSuccessful()))->toBe(1);
});

it('writes nothing on a forced sync when the definitions did not change', function (): void {
    MailListeners::mailbox('invoices@example.com')
        ->connector('imap', ['host' => 'imap.example.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'invoices', 'password' => 'secret'])
        ->events('supplier');
    app(MailboxRegistry::class)->sync();
    $updatedAt = MailAccount::query()->sole()->updated_at;

    $this->travel(1)->hours();
    $result = app(MailboxRegistry::class)->sync();

    expect($result['created'])->toBe([])
        ->and($result['updated'])->toBe([])
        ->and(MailAccount::query()->sole()->updated_at->equalTo($updatedAt))->toBeTrue();
});

it('updates the account on the next poll when its definition changes', function (): void {
    $definition = MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier');
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    $definition->connector('microsoft_graph', ['folder' => 'archive'])->events('supplier', 'hr');
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    $account = MailAccount::query()->sole();

    expect($account->connector_settings)->toBe(['folder' => 'archive'])
        ->and($account->events)->toBe(['supplier', 'hr']);
});

it('deactivates the accounts no longer defined in code, leaving the other accounts untouched', function (): void {
    $removed = MailAccount::factory()->managed()->create(['email' => 'old@example.com']);
    $manual = MailAccount::factory()->create(['email' => 'manual@example.com']);
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier');

    $result = app(MailboxRegistry::class)->sync();

    expect($result['deactivated'])->toBe(['old@example.com'])
        ->and($removed->refresh()->active)->toBeFalse()
        ->and($removed->managed)->toBeTrue()
        ->and($manual->refresh()->active)->toBeTrue()
        ->and($manual->managed)->toBeFalse();
});

it('takes over an existing account with the same email', function (): void {
    $existing = MailAccount::factory()->inactive()->create(['email' => 'invoices@example.com', 'last_received_at' => now()->subHour()]);
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('hr');

    $result = app(MailboxRegistry::class)->sync();

    expect($result['updated'])->toBe(['invoices@example.com'])
        ->and(MailAccount::query()->count())->toBe(1)
        ->and($existing->refresh()->managed)->toBeTrue()
        ->and($existing->active)->toBeTrue()
        ->and($existing->events)->toBe(['hr'])
        ->and($existing->last_received_at)->not->toBeNull();
});

it('syncs again when a defined account is missing from the database', function (): void {
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier');
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    MailAccount::query()->update(['active' => false]);
    $this->artisan('mail-listeners:poll')->assertSuccessful();

    expect(MailAccount::query()->sole()->active)->toBeTrue();
});

it('reports invalid definitions and syncs the valid ones', function (): void {
    MailListeners::mailbox('unknown-connector@example.com')->connector('pop3')->events('supplier');
    MailListeners::mailbox('no-host@example.com')->connector('imap', ['port' => 993, 'encryption' => 'ssl', 'username' => 'x', 'password' => 'y'])->events('supplier');
    MailListeners::mailbox('unknown-event@example.com')->connector('microsoft_graph')->events('nope');
    MailListeners::mailbox('no-events@example.com')->connector('microsoft_graph');
    MailListeners::mailbox('valid@example.com')->connector('microsoft_graph')->events('supplier');

    $this->artisan('mail-listeners:sync')
        ->expectsOutputToContain('1 created, 0 updated, 0 deactivated, 4 invalid')
        ->expectsOutputToContain('pop3')
        ->expectsOutputToContain('host')
        ->expectsOutputToContain('[nope]')
        ->expectsOutputToContain('No events configured')
        ->assertFailed();

    expect(MailAccount::query()->pluck('email')->all())->toBe(['valid@example.com']);
});

it('does not deactivate an account whose definition became invalid', function (): void {
    $definition = MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier');
    app(MailboxRegistry::class)->sync();

    $definition->events('nope');
    app(MailboxRegistry::class)->sync();

    expect(MailAccount::query()->sole()->active)->toBeTrue();
});

it('fires the event classes defined in code for every new email', function (): void {
    Event::fake([SupplierEmailReceived::class, HrEmailReceived::class]);
    $connector = FakeConnector::swap();
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events(SupplierEmailReceived::class)->readFrom(now()->subDay());
    app(MailboxRegistry::class)->sync();
    $account = MailAccount::query()->sole();
    $connector->messages = [FakeConnector::makeEmail($account)];

    app(MailReader::class)->poll($account);

    Event::assertDispatchedTimes(SupplierEmailReceived::class, 1);
    Event::assertNotDispatched(HrEmailReceived::class);
});

it('reads an account defined in code before it was ever polled', function (): void {
    FakeConnector::swap();
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier');

    $this->artisan('mail-listeners:read', ['email' => 'invoices@example.com'])
        ->expectsOutputToContain('0 emails found')
        ->assertSuccessful();

    expect(MailAccount::query()->sole()->isManaged())->toBeTrue();
});

it('returns the same definition when a mailbox is defined twice', function (): void {
    $first = MailListeners::mailbox('invoices@example.com');

    expect(MailListeners::mailbox('INVOICES@example.com'))->toBe($first)
        ->and(MailListeners::all())->toHaveCount(1)
        ->and(MailListeners::has('Invoices@Example.com'))->toBeTrue();
});

it('polls an account defined in code on a cron expression', function (): void {
    $definition = MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier')->cron('0 6 * * *');
    app(MailboxRegistry::class)->sync();

    expect(MailAccount::query()->sole()->poll_cron)->toBe('0 6 * * *');

    $definition->pollEvery(10);
    app(MailboxRegistry::class)->sync();

    expect(MailAccount::query()->sole()->poll_cron)->toBe('*/10 * * * *');
});

it('stores the polling period as a cron expression', function (int $minutes, string $cron): void {
    expect(MailListeners::mailbox('invoices@example.com')->pollEvery($minutes)->cronExpression())->toBe($cron);
})->with([
    [1, '* * * * *'],
    [5, '*/5 * * * *'],
    [30, '*/30 * * * *'],
    [60, '0 * * * *'],
    [120, '0 */2 * * *'],
    [720, '0 */12 * * *'],
]);

it('polls every 5 minutes by default', function (): void {
    expect(MailListeners::mailbox('invoices@example.com')->cronExpression())->toBe('*/5 * * * *');
});

it('rejects polling periods that are not aligned to the clock', function (int $minutes): void {
    MailListeners::mailbox('invoices@example.com')->pollEvery($minutes);
})->throws(InvalidArgumentException::class, 'Use cron() instead')->with([0, 7, 45, 90, 300, 1440]);

it('reports an invalid cron expression', function (): void {
    MailListeners::mailbox('invoices@example.com')->connector('microsoft_graph')->events('supplier')->cron('every morning');

    $result = app(MailboxRegistry::class)->sync();

    expect($result['errors']['invoices@example.com'])->toBe(['Invalid cron expression [every morning].'])
        ->and(MailAccount::query()->count())->toBe(0);
});
