<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\Models\MailAccount;

beforeEach(function (): void {
    Queue::fake();
});

it('queues the reading of the active accounts whose polling interval has elapsed', function (): void {
    $neverPolled = MailAccount::factory()->create(['last_polled_at' => null]);
    $due = MailAccount::factory()->create(['poll_interval_minutes' => 5, 'last_polled_at' => now()->subMinutes(5)]);
    MailAccount::factory()->create(['poll_interval_minutes' => 5, 'last_polled_at' => now()->subMinutes(2)]);
    MailAccount::factory()->inactive()->create(['last_polled_at' => null]);

    $this->artisan('mail-listeners:poll')->assertSuccessful();

    Queue::assertPushed(PollMailAccount::class, 2);
    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($neverPolled));
    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($due));
});

it('queues the reading of a given account even when it is not due', function (): void {
    $account = MailAccount::factory()->create(['poll_interval_minutes' => 60, 'last_polled_at' => now()]);

    $this->artisan('mail-listeners:poll', ['--account' => $account->id])->assertSuccessful();

    Queue::assertPushed(PollMailAccount::class, fn (PollMailAccount $job): bool => $job->account->is($account));
});

it('fails for an unknown account', function (): void {
    $this->artisan('mail-listeners:poll', ['--account' => 999])->assertFailed();

    Queue::assertNothingPushed();
});
