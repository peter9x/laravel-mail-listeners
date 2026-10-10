<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Facades;

use Illuminate\Support\Facades\Facade;
use Mupy\MailListeners\MailboxRegistry;

/**
 * @method static \Mupy\MailListeners\MailboxDefinition mailbox(string $email)
 * @method static array<string, \Mupy\MailListeners\MailboxDefinition> all()
 * @method static bool has(string $email)
 * @method static void tenantMailboxes(\Closure(\Mupy\MailListeners\MailboxDefinitions $mailboxes, int|string $tenant): void $define)
 * @method static \Mupy\MailListeners\MailboxDefinitions definitions(int|string|null $tenant = null)
 * @method static array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}|null syncIfChanged(int|string|null $tenant = null)
 * @method static array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}|null syncTenant(int|string $tenant)
 * @method static array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>} sync(int|string|null $tenant = null)
 * @method static list<array{tenant: int|string|null, result: array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}}> syncAll()
 *
 * @see MailboxRegistry
 */
final class MailListeners extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return MailboxRegistry::class;
    }
}
