<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Illuminate\Support\ServiceProvider;
use Mupy\MailListeners\Console\PollCommand;
use Mupy\MailListeners\Console\ReadCommand;

/**
 * Registers the base of the mail listeners system: config, database, connectors, events registry and commands.
 * Scheduling `mail-listeners:poll` (every minute) and `model:prune` (MailMessage) is left to the app.
 */
class MailListenersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/mail-listeners.php', 'mail-listeners');

        $this->app->singleton(ConnectorManager::class);
        $this->app->singleton(EventRegistry::class);
    }

    public function boot(): void
    {
        if (config('mail-listeners.run_migrations', true)) {
            $this->loadMigrationsFrom(__DIR__.'/../database/migrations');
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/mail-listeners.php' => config_path('mail-listeners.php'),
            ], 'mail-listeners-config');

            $this->publishesMigrations([
                __DIR__.'/../database/migrations' => database_path('migrations'),
            ], 'mail-listeners-migrations');

            $this->commands([
                PollCommand::class,
                ReadCommand::class,
            ]);
        }
    }
}
