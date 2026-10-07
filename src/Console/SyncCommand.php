<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Illuminate\Console\Command;
use Mupy\MailListeners\Console\Concerns\ReportsMailboxSync;
use Mupy\MailListeners\MailboxRegistry;

/**
 * Syncs the mail accounts defined in code now, even when the definitions did not change (e.g. on deploy).
 * `mail-listeners:poll` already syncs them whenever the definitions change.
 */
final class SyncCommand extends Command
{
    use ReportsMailboxSync;

    protected $signature = 'mail-listeners:sync';

    protected $description = 'Sync the mail accounts defined in code with the database';

    public function handle(MailboxRegistry $mailboxes): int
    {
        $result = $mailboxes->sync();

        $this->info(sprintf(
            '%d mail accounts defined in code: %d created, %d updated, %d deactivated, %d invalid.',
            count($mailboxes->all()),
            count($result['created']),
            count($result['updated']),
            count($result['deactivated']),
            count($result['errors']),
        ));

        $this->reportSyncErrors($result);

        return $result['errors'] === [] ? self::SUCCESS : self::FAILURE;
    }
}
