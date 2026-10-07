<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use Mupy\MailListeners\MailReader;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Testing\FakeConnector;
use Psr\Log\LoggerInterface;

it('registers its own daily log channel by default', function (): void {
    expect(config('mail-listeners.logging.channel'))->toBe('mail-listeners')
        ->and(config('logging.channels.mail-listeners'))->toMatchArray([
            'driver' => 'daily',
            'path' => storage_path('logs/mail-listeners.log'),
            'level' => 'debug',
            'days' => 14,
        ]);
});

it('logs to the configured channel', function (): void {
    config(['mail-listeners.logging.channel' => 'custom']);
    $connector = FakeConnector::swap();
    $connector->failWith = new RuntimeException('Graph unavailable');
    $account = MailAccount::factory()->create();

    $logger = Mockery::mock(LoggerInterface::class);
    $logger->shouldReceive('error')->once()->with('Mail listeners: failed to read the account.', Mockery::on(
        fn (array $context): bool => $context['email'] === $account->email && $context['error'] === 'Graph unavailable',
    ));
    Log::shouldReceive('channel')->with('custom')->andReturn($logger);

    expect(fn () => app(MailReader::class)->poll($account))->toThrow(RuntimeException::class, 'Graph unavailable');
});
