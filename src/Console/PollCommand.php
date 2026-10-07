<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Illuminate\Console\Command;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\Models\MailAccount;

/**
 * Queues the reading of the active accounts whose polling interval has elapsed. Schedule it every minute.
 */
final class PollCommand extends Command
{
    protected $signature = 'mail-listeners:poll
        {--account= : ID of an account to read now, even when it is not due}';

    protected $description = 'Queue the reading of the active mail accounts whose polling interval has elapsed';

    public function handle(): int
    {
        if ($this->option('account') !== null) {
            $account = MailAccount::query()->find($this->option('account'));

            if (! $account instanceof MailAccount) {
                $this->error('Mail account not found.');

                return self::FAILURE;
            }

            PollMailAccount::dispatch($account);

            return self::SUCCESS;
        }

        MailAccount::query()
            ->active()
            ->get()
            ->filter(fn (MailAccount $account): bool => $account->isDueForPolling())
            ->each(fn (MailAccount $account) => PollMailAccount::dispatch($account));

        return self::SUCCESS;
    }
}
