<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Mupy\MailListeners\Console\Concerns\ReportsMailboxSync;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\MailboxRegistry;
use Mupy\MailListeners\Models\MailAccount;

/**
 * Queues the reading of the active accounts whose polling interval has elapsed. Schedule it every minute.
 * The central mail accounts defined in code are synced first, only when their definitions changed.
 * With multi-tenancy, the accounts of every tenant are read with a single query (or only those of --tenant).
 */
final class PollCommand extends Command
{
    use ReportsMailboxSync;

    protected $signature = 'mail-listeners:poll
        {--account= : ID of an account to read now, even when it is not due}
        {--tenant= : Only the accounts of this tenant (multi-tenancy)}';

    protected $description = 'Queue the reading of the active mail accounts whose polling interval has elapsed';

    public function handle(MailboxRegistry $mailboxes): int
    {
        $tenant = $this->tenantOption();

        if ($tenant === false) {
            return self::FAILURE;
        }

        $this->reportSyncErrors($mailboxes->syncIfChanged());

        if ($this->option('account') !== null) {
            $account = MailAccount::query()->allTenants()->find($this->option('account'));

            if (! $account instanceof MailAccount) {
                $this->error('Mail account not found.');

                return self::FAILURE;
            }

            PollMailAccount::dispatch($account);

            return self::SUCCESS;
        }

        $activeAccounts = fn (): Builder => MailAccount::query()
            ->active()
            ->when($tenant !== null, fn (Builder $query) => $query->forTenant($tenant), fn (Builder $query) => $query->allTenants());

        $accounts = $activeAccounts()->get();

        if ($tenant === null && $mailboxes->missingFrom($accounts) !== []) {
            $this->reportSyncErrors($mailboxes->sync());

            $accounts = $activeAccounts()->get();
        }

        $accounts
            ->filter(fn (MailAccount $account): bool => $account->isDueForPolling())
            ->each(fn (MailAccount $account) => PollMailAccount::dispatch($account));

        return self::SUCCESS;
    }
}
