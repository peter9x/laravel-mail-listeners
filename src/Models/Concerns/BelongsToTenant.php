<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Mupy\MailListeners\Models\Scopes\TenantScope;
use Mupy\MailListeners\Tenancy\Tenancy;
use Mupy\MailListeners\Tenancy\TenantColumn;

/**
 * Models scoped by the `tenant_id` column when `mail-listeners.tenancy.enabled` is set: queries are limited to the
 * current tenant, and new rows get it unless their tenant is set explicitly (even to null). Does nothing when tenancy
 * is disabled.
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope());

        static::creating(function (Model $model): void {
            // An explicit null (a central row) is kept.
            if (Tenancy::enabled() && ! array_key_exists(TenantColumn::NAME, $model->getAttributes())) {
                $model->setAttribute(TenantColumn::NAME, app(Tenancy::class)->current());
            }
        });
    }

    public function initializeBelongsToTenant(): void
    {
        if (Tenancy::enabled()) {
            $this->mergeCasts([TenantColumn::NAME => Tenancy::columnType() === 'unsignedBigInteger' ? 'integer' : 'string']);
        }
    }

    /**
     * Tenant of the row: null for a central row, and always null when tenancy is disabled (there is no column, so
     * reading `tenant_id` would fail with `Model::preventAccessingMissingAttributes()`).
     */
    public function tenantKey(): int|string|null
    {
        return Tenancy::enabled() ? $this->getAttribute(TenantColumn::NAME) : null;
    }

    /**
     * Rows of the given tenant (null: the central rows), whatever the current tenant.
     *
     * @param  Builder<static>  $query
     */
    public function scopeForTenant(Builder $query, int|string|null $tenant): void
    {
        $query->withoutGlobalScope(TenantScope::class);

        if (! Tenancy::enabled()) {
            return;
        }

        if ($tenant === null) {
            $query->whereNull($this->qualifyColumn(TenantColumn::NAME));
        } else {
            $query->where($this->qualifyColumn(TenantColumn::NAME), $tenant);
        }
    }

    /**
     * Rows of every tenant, whatever the current tenant.
     *
     * @param  Builder<static>  $query
     */
    public function scopeAllTenants(Builder $query): void
    {
        $query->withoutGlobalScope(TenantScope::class);
    }
}
