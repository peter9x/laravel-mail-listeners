<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Illuminate\Console\Command;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;

/**
 * Publishes the config and the app `MailListenersServiceProvider` (where the mailboxes are defined),
 * and registers the provider in `bootstrap/providers.php`.
 */
final class InstallCommand extends Command
{
    protected $signature = 'mail-listeners:install';

    protected $description = 'Publish the mail listeners config and service provider';

    public function handle(): int
    {
        $this->callSilently('vendor:publish', ['--tag' => 'mail-listeners-config']);
        $this->callSilently('vendor:publish', ['--tag' => 'mail-listeners-provider']);

        $namespace = Str::replaceLast('\\', '', $this->laravel->getNamespace());
        $path = app_path('Providers/MailListenersServiceProvider.php');

        file_put_contents($path, str_replace(
            'namespace App\Providers;',
            "namespace {$namespace}\\Providers;",
            (string) file_get_contents($path),
        ));

        $provider = "{$namespace}\\Providers\\MailListenersServiceProvider";

        if (! ServiceProvider::addProviderToBootstrapFile($provider, $this->laravel->getBootstrapProvidersPath())) {
            $this->warn("Register [{$provider}] in your application providers.");
        }

        $this->info('Mail listeners installed. Define your mailboxes in app/Providers/MailListenersServiceProvider.php.');

        return self::SUCCESS;
    }
}
