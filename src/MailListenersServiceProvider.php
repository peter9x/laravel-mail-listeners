<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Illuminate\Support\ServiceProvider;
use Mupy\MailListeners\Console\PollCommand;
use Mupy\MailListeners\Console\ReadCommand;
use Mupy\MailListeners\Console\SyncCommand;

/**
 * Registers the base of the mail listeners system: config, log channel, database, connectors, events registry,
 * mail accounts defined in code and commands.
 * Scheduling `mail-listeners:poll` (every minute) and `model:prune` (MailMessage) is left to the app.
 */
class MailListenersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mail-listeners.php', 'mail-listeners');

        $this->app->singleton(ConnectorManager::class);
        $this->app->singleton(EventRegistry::class);
        $this->app->singleton(MailboxRegistry::class);

        $this->registerLogChannel();
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mail-listeners.php' => config_path('mail-listeners.php'),
            ], 'mail-listeners-config');

            $this->commands([
                PollCommand::class,
                ReadCommand::class,
                SyncCommand::class,
            ]);
        }
    }

    /**
     * The package own `mail-listeners` log channel (a daily file), unless the app already defines it.
     */
    private function registerLogChannel(): void
    {
        $config = $this->app->make('config');

        if ($config->has('logging.channels.mail-listeners')) {
            return;
        }

        $config->set('logging.channels.mail-listeners', [
            'driver' => 'daily',
            'path' => storage_path('logs/mail-listeners.log'),
            'level' => $config->get('mail-listeners.logging.level', 'debug'),
            'days' => $config->get('mail-listeners.logging.days', 14),
            'replace_placeholders' => true,
        ]);
    }
}
