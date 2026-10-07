<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Facades;

use Illuminate\Support\Facades\Facade;
use Mupy\MailListeners\MailboxRegistry;

/**
 * @method static \Mupy\MailListeners\MailboxDefinition mailbox(string $email)
 * @method static array<string, \Mupy\MailListeners\MailboxDefinition> all()
 * @method static bool has(string $email)
 * @method static array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}|null syncIfChanged()
 * @method static array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>} sync()
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
