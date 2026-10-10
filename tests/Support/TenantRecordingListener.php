<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tests\Support;

use Mupy\MailListeners\Contracts\TenantResolver;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\MailListener;

/**
 * Listener recording the current tenant while it processes each email.
 */
final class TenantRecordingListener extends MailListener
{
    /**
     * @var list<int|string|null>
     */
    public static array $tenants = [];

    public static function label(): string
    {
        return 'Tenant recording';
    }

    public function interestedIn(InboundEmail $email): bool
    {
        return true;
    }

    protected function process(InboundEmail $email): void
    {
        self::$tenants[] = app(TenantResolver::class)->current();
    }
}
