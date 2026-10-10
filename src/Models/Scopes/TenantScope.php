<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Mupy\MailListeners\Tenancy\Tenancy;
use Mupy\MailListeners\Tenancy\TenantColumn;

/**
 * Limits the queries to the current tenant. Without a current tenant (e.g. the scheduler, the queue workers, a central
 * request) or with tenancy disabled, nothing is filtered.
 */
final class TenantScope implements Scope
{
    /**
     * @param  Builder<Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $tenant = app(Tenancy::class)->current();

        if ($tenant !== null) {
            $builder->where($model->qualifyColumn(TenantColumn::NAME), $tenant);
        }
    }
}
