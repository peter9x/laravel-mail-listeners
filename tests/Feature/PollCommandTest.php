<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Queue;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\Models\MailAccount;

beforeEach(function (): void {
    Queue::fake();
});

it('queues the reading of the active accounts whose polling interval has elapsed', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 11:58:00'));
    $neverPolled = MailAccount::factory()->create(['last_polled_at' => null]);
    $due = MailAccount::factory()->create(['last_polled_at' => '2026-10-06 11:55:30']);
    MailAccount::factory()->inactive()->create(['last_polled_at' => null]);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:01:00'));
    MailAccount::factory()->create(['last_polled_at' => null]);
    MailAccount::factory()->create(['last_polled_at' => '2026-10-06 12:00:30']);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:03:00'));

    $this->artisan('mail-listeners:poll')->assertSuccessful();

    Queue::assertPushed(PollMailAccount::class, 2);
    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($neverPolled));
    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($due));
});

it('queues the reading of a given account even when it is not due', function (): void {
    $account = MailAccount::factory()->create(['poll_cron' => '0 * * * *', 'last_polled_at' => now()]);

    $this->artisan('mail-listeners:poll', ['--account' => $account->id])->assertSuccessful();

    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($account));
});

it('fails for an unknown account', function (): void {
    $this->artisan('mail-listeners:poll', ['--account' => 999])->assertFailed();

    Queue::assertNothingPushed();
});

it('polls an account with a cron expression once its last run time is past the last poll', function (string $now, ?string $lastPolledAt, bool $due): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
    $account = MailAccount::factory()->create(['poll_cron' => '0 6 * * *', 'last_polled_at' => $lastPolledAt]);
    $this->travelTo(CarbonImmutable::parse($now));

    expect($account->isDueForPolling())->toBe($due);
})->with([
    'never polled, run time after creation' => ['2026-10-06 12:00:00', null, true],
    'never polled, no run time since creation' => ['2026-10-05 23:00:00', null, false],
    'already polled after today\'s run time' => ['2026-10-06 12:00:00', '2026-10-06 06:01:00', false],
    'exactly at the run time' => ['2026-10-06 06:00:00', '2026-10-05 06:01:00', true],
    'missed run caught up later' => ['2026-10-06 15:00:00', '2026-10-04 06:01:00', true],
]);

it('evaluates the cron expression in the schedule timezone', function (): void {
    config(['app.schedule_timezone' => 'Europe/Lisbon']);
    $account = MailAccount::factory()->create(['poll_cron' => '0 6 * * *', 'last_polled_at' => '2026-10-05 06:00:00']);

    // 05:30 UTC is 06:30 in Lisbon (summer time).
    $this->travelTo(CarbonImmutable::parse('2026-10-06 05:30:00', 'UTC'));
    expect($account->isDueForPolling())->toBeTrue();

    $this->travelTo(CarbonImmutable::parse('2026-10-06 04:30:00', 'UTC'));
    expect($account->isDueForPolling())->toBeFalse();
});

it('never polls an account with an invalid cron expression', function (): void {
    $account = MailAccount::factory()->create(['poll_cron' => 'every morning', 'last_polled_at' => null]);

    expect($account->isDueForPolling())->toBeFalse();
});

it('queues only the cron accounts that are due', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-05 10:00:00'));
    $due = MailAccount::factory()->create(['poll_cron' => '0 6 * * *', 'last_polled_at' => '2026-10-05 06:01:00']);
    MailAccount::factory()->create(['poll_cron' => '0 6 * * *', 'last_polled_at' => '2026-10-06 06:01:00']);
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));

    $this->artisan('mail-listeners:poll')->assertSuccessful();

    Queue::assertPushed(PollMailAccount::class, 1);
    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($due));
});
