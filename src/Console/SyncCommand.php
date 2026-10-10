<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Illuminate\Console\Command;
use Mupy\MailListeners\Console\Concerns\ReportsMailboxSync;
use Mupy\MailListeners\MailboxRegistry;

/**
 * Syncs the mail accounts defined in code now, even when the definitions did not change (e.g. on deploy): the central
 * ones and those of every tenant, or only those of --tenant. `mail-listeners:poll` already syncs the central ones
 * whenever their definitions change.
 */
final class SyncCommand extends Command
{
    use ReportsMailboxSync;

    protected $signature = 'mail-listeners:sync
        {--tenant= : Only the mail accounts of this tenant (multi-tenancy)}';

    protected $description = 'Sync the mail accounts defined in code with the database';

    public function handle(MailboxRegistry $mailboxes): int
    {
        $tenant = $this->tenantOption();

        if ($tenant === false) {
            return self::FAILURE;
        }

        $results = $tenant !== null
            ? [['tenant' => $tenant, 'result' => $mailboxes->sync($tenant)]]
            : $mailboxes->syncAll();

        $valid = true;

        foreach ($results as ['tenant' => $syncedTenant, 'result' => $result]) {
            $this->info(sprintf(
                '%s%d mail accounts defined in code: %d created, %d updated, %d deactivated, %d invalid.',
                $this->tenantPrefix($syncedTenant),
                count($mailboxes->definitions($syncedTenant)->all()),
                count($result['created']),
                count($result['updated']),
                count($result['deactivated']),
                count($result['errors']),
            ));

            $this->reportSyncErrors($result, $syncedTenant);

            $valid = $valid && $result['errors'] === [];
        }

        return $valid ? self::SUCCESS : self::FAILURE;
    }
}
