<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Mupy\MailListeners\MailReader;
use Mupy\MailListeners\Models\MailAccount;

/**
 * Reads the new emails of one mail account and fires its events.
 * Unique per account, so accounts are read in parallel and an account is never read twice at the same time.
 */
final class PollMailAccount implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 600;

    public int $uniqueFor = 3600;

    public function __construct(public MailAccount $account)
    {
        $this->onQueue(config('mail-listeners.queues.poll'));
    }

    public function uniqueId(): string
    {
        return (string) $this->account->id;
    }

    public function displayName(): string
    {
        return "Read mail account {$this->account->email}";
    }

    /**
     * @return list<string>
     */
    public function tags(): array
    {
        return ['MailListeners', $this->account->email, class_basename(self::class)];
    }

    public function handle(MailReader $reader): void
    {
        if (! $this->account->active) {
            return;
        }

        $reader->poll($this->account);
    }
}
