<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Mupy\MailListeners\Facades\MailListeners;

beforeEach(function (): void {
    $this->providersFile = $this->app->getBootstrapProvidersPath();
    $this->providersBackup = File::get($this->providersFile);
});

afterEach(function (): void {
    File::put($this->providersFile, $this->providersBackup);
    File::delete([app_path('Providers/MailListenersServiceProvider.php'), config_path('mail-listeners.php')]);
});

it('publishes the config and the service provider, and registers the provider', function (): void {
    $this->artisan('mail-listeners:install')->assertSuccessful();

    expect(config_path('mail-listeners.php'))->toBeFile()
        ->and(app_path('Providers/MailListenersServiceProvider.php'))->toBeFile()
        ->and(require $this->providersFile)->toContain('App\Providers\MailListenersServiceProvider');
});

it('publishes a service provider that boots without defining any mailbox', function (): void {
    $this->artisan('mail-listeners:install')->assertSuccessful();

    require_once app_path('Providers/MailListenersServiceProvider.php');

    $this->app->register('App\Providers\MailListenersServiceProvider');

    expect(MailListeners::all())->toBe([]);
});

it('keeps the mailboxes of an already published service provider', function (): void {
    $this->artisan('mail-listeners:install')->assertSuccessful();
    File::put(app_path('Providers/MailListenersServiceProvider.php'), '<?php // mailboxes');

    $this->artisan('mail-listeners:install')->assertSuccessful();

    expect(File::get(app_path('Providers/MailListenersServiceProvider.php')))->toBe('<?php // mailboxes')
        ->and(array_count_values(require $this->providersFile)['App\Providers\MailListenersServiceProvider'])->toBe(1);
});
