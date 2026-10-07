<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Illuminate\Console\Command;
use Mupy\MailListeners\Console\Concerns\ReportsMailboxSync;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\MailboxRegistry;
use Mupy\MailListeners\Models\MailAccount;

/**
 * Queues the reading of the active accounts whose polling interval has elapsed. Schedule it every minute.
 * The mail accounts defined in code are synced first, only when their definitions changed.
 */
final class PollCommand extends Command
{
    use ReportsMailboxSync;

    protected $signature = 'mail-listeners:poll
        {--account= : ID of an account to read now, even when it is not due}';

    protected $description = 'Queue the reading of the active mail accounts whose polling interval has elapsed';

    public function handle(MailboxRegistry $mailboxes): int
    {
        $this->reportSyncErrors($mailboxes->syncIfChanged());

        if ($this->option('account') !== null) {
            $account = MailAccount::query()->find($this->option('account'));

            if (! $account instanceof MailAccount) {
                $this->error('Mail account not found.');

                return self::FAILURE;
            }

            PollMailAccount::dispatch($account);

            return self::SUCCESS;
        }

        $accounts = MailAccount::query()->active()->get();

        if ($mailboxes->missingFrom($accounts) !== []) {
            $this->reportSyncErrors($mailboxes->sync());

            $accounts = MailAccount::query()->active()->get();
        }

        $accounts
            ->filter(fn (MailAccount $account): bool => $account->isDueForPolling())
            ->each(fn (MailAccount $account) => PollMailAccount::dispatch($account));

        return self::SUCCESS;
    }
}
