<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tests;

use Mupy\MailListeners\MailListenersServiceProvider;
use Mupy\MailListeners\Tests\Support\Events\HrEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupportEmailReceived;
use Orchestra\Testbench\TestCase as Orchestra;
use Webklex\IMAP\Providers\LaravelServiceProvider as ImapServiceProvider;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [
            ImapServiceProvider::class,
            MailListenersServiceProvider::class,
        ];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'testing');
        $app['config']->set('mail-listeners.events', [
            'supplier' => ['class' => SupplierEmailReceived::class, 'label' => 'Suppliers'],
            'support' => ['class' => SupportEmailReceived::class, 'label' => 'Support'],
            'hr' => ['class' => HrEmailReceived::class, 'label' => 'Human resources'],
        ]);
        $app['config']->set('mail-listeners.microsoft_graph.tenants', [
            'default' => ['tenant' => 'tenant-id', 'client_id' => 'client-id', 'client_secret' => 'secret'],
            'other' => ['tenant' => 'other-tenant-id', 'client_id' => 'other-client-id', 'client_secret' => 'other-secret'],
        ]);
    }
}
