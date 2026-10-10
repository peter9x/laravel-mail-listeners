<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tests;

use Mupy\MailListeners\Contracts\TenantResolver;
use Mupy\MailListeners\Tests\Support\ArrayTenantResolver;

/**
 * Tests of an app with multi-tenancy enabled: tenants are resolved by {@see ArrayTenantResolver}.
 */
abstract class TenancyTestCase extends TestCase
{
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('mail-listeners.tenancy.enabled', true);
        $app->singleton(TenantResolver::class, ArrayTenantResolver::class);
    }

    protected function tenants(): ArrayTenantResolver
    {
        return $this->app->make(TenantResolver::class);
    }
}
