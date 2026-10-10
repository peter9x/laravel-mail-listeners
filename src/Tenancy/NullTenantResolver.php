<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tenancy;

use Closure;
use Mupy\MailListeners\Contracts\TenantResolver;

/**
 * Resolver of apps without tenants (the default): there is never a current tenant.
 */
final class NullTenantResolver implements TenantResolver
{
    public function current(): int|string|null
    {
        return null;
    }

    public function all(): iterable
    {
        return [];
    }

    public function run(int|string $tenant, Closure $callback): mixed
    {
        return $callback();
    }
}
