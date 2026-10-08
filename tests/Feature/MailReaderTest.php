<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Event;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Enums\ListenerRunStatus;
use Mupy\MailListeners\Enums\MessageStatus;
use Mupy\MailListeners\Jobs\PollMailAccount;
use Mupy\MailListeners\MailReader;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailListenerRun;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Testing\FakeConnector;
use Mupy\MailListeners\Tests\Support\Events\HrEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupportEmailReceived;
use Mupy\MailListeners\Tests\Support\RecordingListener;

beforeEach(function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-10-06 12:00:00'));
    $this->connector = FakeConnector::swap();
    RecordingListener::reset();
});

it('records the new emails and fires only the events configured on the account', function (): void {
    Event::fake([SupplierEmailReceived::class, SupportEmailReceived::class, HrEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier', 'hr'])->create();
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['subject' => 'Fatura 1']),
        FakeConnector::makeEmail($account, ['subject' => 'Fatura 2']),
    ];

    $newMessages = app(MailReader::class)->poll($account);

    expect($newMessages)->toBe(2)
        ->and(MailMessage::query()->where('status', MessageStatus::DISPATCHED)->pluck('subject')->sort()->values()->all())
        ->toBe(['Fatura 1', 'Fatura 2']);

    Event::assertDispatchedTimes(SupplierEmailReceived::class, 2);
    Event::assertDispatchedTimes(HrEmailReceived::class, 2);
    Event::assertNotDispatched(SupportEmailReceived::class);
    Event::assertDispatched(SupplierEmailReceived::class, fn (SupplierEmailReceived $event): bool => $event->email->messageId === MailMessage::query()->where('subject', $event->email->subject)->value('id'));
});

it('never fires the events twice for the same email', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier'])->create();
    $this->connector->messages = [FakeConnector::makeEmail($account)];

    app(MailReader::class)->poll($account);
    $newMessages = app(MailReader::class)->poll($account->refresh());

    expect($newMessages)->toBe(0)
        ->and(MailMessage::query()->count())->toBe(1);
    Event::assertDispatchedTimes(SupplierEmailReceived::class, 1);
});

it('reads from the configured start date first, then from the last received email minus an overlap', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier'])->create(['read_from' => '2026-10-01 00:00:00', 'last_received_at' => null]);
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['receivedAt' => CarbonImmutable::parse('2026-10-06 10:00:00')]),
        FakeConnector::makeEmail($account, ['receivedAt' => CarbonImmutable::parse('2026-10-06 11:30:00')]),
    ];

    app(MailReader::class)->poll($account);
    $account->refresh();
    app(MailReader::class)->poll($account);

    expect($this->connector->rangesRequested[0][0]->toDateTimeString())->toBe('2026-10-01 00:00:00')
        ->and($this->connector->rangesRequested[0][1]->toDateTimeString())->toBe('2026-10-06 12:00:00')
        ->and($account->last_received_at->toDateTimeString())->toBe('2026-10-06 11:30:00')
        ->and($account->last_polled_at->toDateTimeString())->toBe('2026-10-06 12:00:00')
        ->and($this->connector->rangesRequested[1][0]->toDateTimeString())->toBe('2026-10-06 11:25:00');
});

it('records the error on the account when it cannot be read, keeping the emails already read', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier'])->create(['last_error' => null]);
    $this->connector->messages = [FakeConnector::makeEmail($account)];
    $this->connector->failWith = new RuntimeException('Graph unavailable');

    expect(fn () => app(MailReader::class)->poll($account))->toThrow(RuntimeException::class, 'Graph unavailable');

    $account->refresh();
    expect($account->last_error)->toBe('Graph unavailable')
        ->and($account->last_error_at)->not->toBeNull()
        ->and($account->last_polled_at)->not->toBeNull()
        ->and(MailMessage::query()->count())->toBe(1);
    Event::assertDispatchedTimes(SupplierEmailReceived::class, 1);
});

it('clears the previous error after a successful read', function (): void {
    $account = MailAccount::factory()->withEvents(['supplier'])->create(['last_error' => 'Old error', 'last_error_at' => now()->subDay()]);

    app(MailReader::class)->poll($account);

    expect($account->refresh()->last_error)->toBeNull()
        ->and($account->last_error_at)->toBeNull();
});

it('marks an email as failed when an event cannot be dispatched, and keeps reading the next ones', function (): void {
    Event::listen(SupplierEmailReceived::class, function (SupplierEmailReceived $event): void {
        if ($event->email->subject === 'boom') {
            throw new RuntimeException('Dispatch failed');
        }
    });
    $account = MailAccount::factory()->withEvents(['supplier'])->create();
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['subject' => 'boom', 'receivedAt' => CarbonImmutable::now()->subMinutes(20)]),
        FakeConnector::makeEmail($account, ['subject' => 'ok', 'receivedAt' => CarbonImmutable::now()->subMinutes(10)]),
    ];

    app(MailReader::class)->poll($account);

    $failed = MailMessage::query()->where('subject', 'boom')->sole();
    expect($failed->status)->toBe(MessageStatus::FAILED)
        ->and($failed->error)->toContain('Dispatch failed')
        ->and(MailMessage::query()->where('subject', 'ok')->sole()->status)->toBe(MessageStatus::DISPATCHED);
});

it('delivers the emails to the interested module listeners', function (): void {
    Event::listen(SupportEmailReceived::class, RecordingListener::class);
    RecordingListener::$subjectKeyword = 'pedido';
    $account = MailAccount::factory()->withEvents(['support'])->create();
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['subject' => 'Novo pedido de suporte']),
        FakeConnector::makeEmail($account, ['subject' => 'Newsletter']),
    ];

    PollMailAccount::dispatchSync($account);

    $message = MailMessage::query()->where('subject', 'Novo pedido de suporte')->sole();
    expect(RecordingListener::$processed)->toHaveCount(1)
        ->and(RecordingListener::$processed[0]->subject)->toBe('Novo pedido de suporte')
        ->and(MailListenerRun::query()->sole()->only(['mail_message_id', 'listener', 'status']))->toBe([
            'mail_message_id' => $message->id,
            'listener' => RecordingListener::class,
            'status' => ListenerRunStatus::PROCESSED,
        ]);
});

it('does not read inactive accounts', function (): void {
    $account = MailAccount::factory()->inactive()->create();
    $this->connector->messages = [FakeConnector::makeEmail($account)];

    PollMailAccount::dispatchSync($account);

    expect($this->connector->rangesRequested)->toBe([])
        ->and(MailMessage::query()->count())->toBe(0);
});

it('is unique per account', function (): void {
    $account = MailAccount::factory()->withEvents(['supplier'])->create();

    expect((new PollMailAccount($account))->uniqueId())->toBe((string) $account->id)
        ->and((new PollMailAccount($account))->queue)->toBe('mail-listeners');
});

it('fires the account events for a given email, recording it when new and even when it was already read', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier'])->create();
    $email = FakeConnector::makeEmail($account, ['subject' => 'Extrato Via Verde']);

    $first = app(MailReader::class)->dispatchEmail($account, $email);
    $second = app(MailReader::class)->dispatchEmail($account, $email);

    expect($first->id)->toBe($second->id)
        ->and($first->subject)->toBe('Extrato Via Verde')
        ->and(MailMessage::query()->count())->toBe(1);
    Event::assertDispatchedTimes(SupplierEmailReceived::class, 2);
    Event::assertDispatched(SupplierEmailReceived::class, fn (SupplierEmailReceived $event): bool => $event->email->messageId === $first->id);
});

it('reports each email read within a period, with the counters so far', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier'])->create();
    $alreadyRead = FakeConnector::makeEmail($account, ['subject' => 'Já lido', 'receivedAt' => CarbonImmutable::parse('2026-01-02 10:00')]);
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['subject' => 'Primeiro', 'receivedAt' => CarbonImmutable::parse('2026-01-01 10:00')]),
        $alreadyRead,
        FakeConnector::makeEmail($account, ['subject' => 'Terceiro', 'receivedAt' => CarbonImmutable::parse('2026-01-03 10:00')]),
    ];
    MailMessage::factory()->for($account, 'account')->create(['dedup_key' => $alreadyRead->dedupKey()]);
    $calls = [];

    $result = app(MailReader::class)->readBetween(
        $account,
        CarbonImmutable::parse('2026-01-01'),
        CarbonImmutable::parse('2026-02-01'),
        function (InboundEmail $email, bool $isNew, array $result) use (&$calls): void {
            $calls[] = [$email->subject, $isNew, $result];
        },
    );

    expect($calls)->toBe([
        ['Primeiro', true, ['found' => 1, 'new' => 1]],
        ['Já lido', false, ['found' => 2, 'new' => 1]],
        ['Terceiro', true, ['found' => 3, 'new' => 2]],
    ])->and($result)->toBe(['found' => 3, 'new' => 2]);
    Event::assertDispatchedTimes(SupplierEmailReceived::class, 2);
});

it('reads a period the same way without a callback', function (): void {
    Event::fake([SupplierEmailReceived::class]);
    $account = MailAccount::factory()->withEvents(['supplier'])->create();
    $this->connector->messages = [
        FakeConnector::makeEmail($account, ['receivedAt' => CarbonImmutable::parse('2026-01-01 10:00')]),
        FakeConnector::makeEmail($account, ['receivedAt' => CarbonImmutable::parse('2026-01-02 10:00')]),
    ];

    $result = app(MailReader::class)->readBetween($account, CarbonImmutable::parse('2026-01-01'), CarbonImmutable::parse('2026-02-01'));

    expect($result)->toBe(['found' => 2, 'new' => 2])
        ->and(MailMessage::query()->count())->toBe(2);
    Event::assertDispatchedTimes(SupplierEmailReceived::class, 2);
});
