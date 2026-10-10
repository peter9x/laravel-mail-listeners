<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tests\Support;

use Closure;
use Mupy\MailListeners\Contracts\TenantResolver;

/**
 * In-memory tenancy: the current tenant is a property, and every run() is recorded.
 */
final class ArrayTenantResolver implements TenantResolver
{
    public int|string|null $current = null;

    /**
     * @var list<int|string>
     */
    public array $tenants = [];

    /**
     * Tenants run() was called with, in order.
     *
     * @var list<int|string>
     */
    public array $ran = [];

    public function current(): int|string|null
    {
        return $this->current;
    }

    public function all(): iterable
    {
        return $this->tenants;
    }

    public function run(int|string $tenant, Closure $callback): mixed
    {
        $this->ran[] = $tenant;
        $previous = $this->current;
        $this->current = $tenant;

        try {
            return $callback();
        } finally {
            $this->current = $previous;
        }
    }
}
