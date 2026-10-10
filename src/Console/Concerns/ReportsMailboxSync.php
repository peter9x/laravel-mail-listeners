<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console\Concerns;

use Mupy\MailListeners\Tenancy\Tenancy;

/**
 * Prints the invalid mail accounts defined in code found by a sync ({@see \Mupy\MailListeners\MailboxRegistry::sync()}),
 * and reads the `--tenant` option of the commands.
 */
trait ReportsMailboxSync
{
    /**
     * @param  array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}|null  $result
     */
    protected function reportSyncErrors(?array $result, int|string|null $tenant = null): void
    {
        foreach ($result['errors'] ?? [] as $email => $errors) {
            $this->error("{$this->tenantPrefix($tenant)}The mail account {$email} defined in code is invalid and was skipped: ".implode(' ', $errors));
        }
    }

    protected function tenantPrefix(int|string|null $tenant): string
    {
        return $tenant !== null ? "[tenant {$tenant}] " : '';
    }

    /**
     * The `--tenant` option (a numeric key as an integer with integer tenant keys), or false (with an error printed)
     * when it is given without multi-tenancy.
     */
    protected function tenantOption(): int|string|false|null
    {
        $tenant = $this->option('tenant');

        if ($tenant === null || $tenant === '') {
            return null;
        }

        if (! Tenancy::enabled()) {
            $this->error('The --tenant option needs multi-tenancy (mail-listeners.tenancy.enabled).');

            return false;
        }

        return Tenancy::columnType() === 'unsignedBigInteger' && ctype_digit((string) $tenant) ? (int) $tenant : (string) $tenant;
    }
}
