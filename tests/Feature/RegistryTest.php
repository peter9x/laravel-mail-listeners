<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Event;
use Mupy\MailListeners\ConnectorManager;
use Mupy\MailListeners\Connectors\ImapConnector;
use Mupy\MailListeners\Connectors\MicrosoftGraphConnector;
use Mupy\MailListeners\EventRegistry;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Testing\FakeConnector;
use Mupy\MailListeners\Tests\Support\Events\HrEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;
use Mupy\MailListeners\Tests\Support\RecordingListener;

it('lists the configured connectors with their labels and default settings', function (): void {
    $connectors = app(ConnectorManager::class);

    expect($connectors->keys())->toBe(['microsoft_graph', 'imap'])
        ->and($connectors->connectorClass('imap'))->toBe(ImapConnector::class)
        ->and($connectors->connector('microsoft_graph'))->toBeInstanceOf(MicrosoftGraphConnector::class)
        ->and($connectors->options()[1])->toMatchArray(['value' => 'imap', 'label' => 'IMAP'])
        ->and($connectors->options()[1]['defaults']['port'])->toBe(993);
});

it('rejects connectors that are not registered', function (): void {
    expect(fn () => app(ConnectorManager::class)->connector('pop3'))
        ->toThrow(InvalidArgumentException::class, 'Mail connector [pop3] is not registered.');
});

it('lists the events registered by the app with the listeners of each one', function (): void {
    Event::listen(SupplierEmailReceived::class, RecordingListener::class);

    expect(app(EventRegistry::class)->options())->toBe([
        ['value' => 'supplier', 'label' => 'Suppliers', 'listeners' => ['RecordingListener']],
        ['value' => 'support', 'label' => 'Support', 'listeners' => []],
        ['value' => 'hr', 'label' => 'Human resources', 'listeners' => []],
    ]);
});

it('ignores the events of an account that are not registered (anymore)', function (): void {
    $account = MailAccount::factory()->withEvents(['hr', 'removed', 'supplier'])->create();

    expect($account->eventKeys())->toBe(['hr', 'supplier'])
        ->and($account->eventClasses())->toBe([HrEmailReceived::class, SupplierEmailReceived::class]);
});

it('swaps a connector for a fake in tests', function (): void {
    $fake = FakeConnector::swap('imap');
    $account = MailAccount::factory()->imap()->create();

    expect($account->mailConnector())->toBe($fake);
});
