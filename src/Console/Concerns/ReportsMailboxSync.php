<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console\Concerns;

/**
 * Prints the invalid mail accounts defined in code found by a sync ({@see \Mupy\MailListeners\MailboxRegistry::sync()}).
 */
trait ReportsMailboxSync
{
    /**
     * @param  array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}|null  $result
     */
    protected function reportSyncErrors(?array $result): void
    {
        foreach ($result['errors'] ?? [] as $email => $errors) {
            $this->error("The mail account {$email} defined in code is invalid and was skipped: ".implode(' ', $errors));
        }
    }
}
