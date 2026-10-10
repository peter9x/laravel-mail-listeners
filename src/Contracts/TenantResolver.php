<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Contracts;

use Closure;

/**
 * Tells the package about the tenants of a multi-tenant app (`mail-listeners.tenancy.enabled`). Implement it with
 * your tenancy library (or your own code) and bind it in a service provider:
 * `$this->app->singleton(TenantResolver::class, AppTenantResolver::class)`.
 */
interface TenantResolver
{
    /**
     * Key of the current tenant, or null outside of a tenant (e.g. the scheduler or a central request).
     * Called on every query of the package models: it must not query the database.
     */
    public function current(): int|string|null;

    /**
     * Keys of every tenant, whose mailboxes defined in code are synced by `mail-listeners:sync`.
     *
     * @return iterable<int|string>
     */
    public function all(): iterable;

    /**
     * Run the callback in the context of the tenant, restoring the previous context afterwards.
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(int|string $tenant, Closure $callback): mixed;
}
