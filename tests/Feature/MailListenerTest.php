<?php

declare(strict_types=1);

use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Mupy\MailListeners\Data\EmailAttachment;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Enums\ListenerRunStatus;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailListenerRun;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Testing\FakeConnector;
use Mupy\MailListeners\Tests\Support\Events\HrEmailReceived;
use Mupy\MailListeners\Tests\Support\Events\SupplierEmailReceived;
use Mupy\MailListeners\Tests\Support\OtherRecordingListener;
use Mupy\MailListeners\Tests\Support\RecordingListener;

beforeEach(function (): void {
    RecordingListener::reset();
    $this->connector = FakeConnector::swap();
    $this->account = MailAccount::factory()->create();
});

function recordedInboundEmail(MailAccount $account, array $overrides = []): InboundEmail
{
    $email = FakeConnector::makeEmail($account, $overrides);
    $message = MailMessage::factory()->for($account, 'account')->create(['dedup_key' => $email->dedupKey()]);

    return $email->withMessageId($message->id);
}

it('ignores the emails the listener is not interested in, without recording a run', function (): void {
    RecordingListener::$subjectKeyword = 'via verde';

    (new RecordingListener())->handle(new SupplierEmailReceived(recordedInboundEmail($this->account, ['subject' => 'Fatura'])));

    expect(RecordingListener::$processed)->toBe([])
        ->and(MailListenerRun::query()->count())->toBe(0);
});

it('records a processed run when the listener handles the email', function (): void {
    $email = recordedInboundEmail($this->account);

    (new RecordingListener())->handle(new SupplierEmailReceived($email));

    $run = MailListenerRun::query()->sole();
    expect(RecordingListener::$processed)->toHaveCount(1)
        ->and($run->mail_message_id)->toBe($email->messageId)
        ->and($run->listener)->toBe(RecordingListener::class)
        ->and($run->status)->toBe(ListenerRunStatus::PROCESSED)
        ->and($run->attempts)->toBe(1)
        ->and($run->finished_at)->not->toBeNull();
});

it('records a failed run and rethrows, then processes it on the next attempt', function (): void {
    $event = new SupplierEmailReceived(recordedInboundEmail($this->account));
    RecordingListener::$fail = true;

    expect(fn () => (new RecordingListener())->handle($event))->toThrow(RuntimeException::class, 'Listener failed');

    $run = MailListenerRun::query()->sole();
    expect($run->status)->toBe(ListenerRunStatus::FAILED)
        ->and($run->error)->toContain('Listener failed');

    RecordingListener::$fail = false;
    (new RecordingListener())->handle($event);

    expect($run->refresh()->status)->toBe(ListenerRunStatus::PROCESSED)
        ->and($run->attempts)->toBe(2)
        ->and($run->error)->toBeNull();
});

it('marks the run as failed when the queue gives up on it', function (): void {
    $event = new SupplierEmailReceived(recordedInboundEmail($this->account));
    MailListenerRun::query()->create([
        'mail_message_id' => $event->email->messageId,
        'listener' => RecordingListener::class,
        'status' => ListenerRunStatus::PROCESSING,
    ]);

    (new RecordingListener())->failed($event, new RuntimeException('Timed out'));

    expect(MailListenerRun::query()->sole()->status)->toBe(ListenerRunStatus::FAILED);
});

it('processes an email only once, even when listening to several events of the account', function (): void {
    $email = recordedInboundEmail($this->account);

    (new RecordingListener())->handle(new SupplierEmailReceived($email));
    (new RecordingListener())->handle(new HrEmailReceived($email));

    expect(RecordingListener::$processed)->toHaveCount(1)
        ->and(MailListenerRun::query()->count())->toBe(1);
});

it('keeps the runs of different listeners independent', function (): void {
    $email = recordedInboundEmail($this->account);

    (new RecordingListener())->handle(new SupplierEmailReceived($email));
    (new OtherRecordingListener())->handle(new SupplierEmailReceived($email));

    expect(MailListenerRun::query()->pluck('listener')->sort()->values()->all())->toBe([
        OtherRecordingListener::class,
        RecordingListener::class,
    ]);
});

it('queues every listener as its own job, on its own queue', function (): void {
    Queue::fake();
    Event::listen(SupplierEmailReceived::class, RecordingListener::class);
    Event::listen(SupplierEmailReceived::class, OtherRecordingListener::class);

    event(new SupplierEmailReceived(recordedInboundEmail($this->account)));

    Queue::assertPushedOn('mail-listeners-listeners', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === RecordingListener::class);
    Queue::assertPushedOn('long', CallQueuedListener::class, fn (CallQueuedListener $job): bool => $job->class === OtherRecordingListener::class);
});

it('downloads the attachments only when a listener asks for them, once per process', function (): void {
    $email = recordedInboundEmail($this->account, ['hasAttachments' => true]);
    $this->connector->attachmentsByMessage[$email->providerMessageId] = [
        new EmailAttachment('passagens.CSV', 'text/csv', 7, "a;b\n1;2"),
        new EmailAttachment('fatura.pdf', 'application/pdf', 3, '%PDF'),
    ];

    expect($this->connector->attachmentCalls)->toBe(0);

    $attachments = $email->attachments();
    $email->attachments();

    expect($this->connector->attachmentCalls)->toBe(1)
        ->and($attachments)->toHaveCount(2)
        ->and($attachments->first()->extension())->toBe('csv')
        ->and($attachments->first()->content)->toBe("a;b\n1;2");
});

it('never serializes the downloaded attachments into the queue payload', function (): void {
    $email = recordedInboundEmail($this->account, ['hasAttachments' => true]);
    $this->connector->attachmentsByMessage[$email->providerMessageId] = [new EmailAttachment('big.csv', 'text/csv', 12, 'secret-bytes')];
    $email->attachments();

    $payload = serialize(new SupplierEmailReceived($email));

    expect($payload)->not->toContain('secret-bytes')
        ->and(unserialize($payload)->email->subject)->toBe($email->subject);
});

it('does not call the connector for emails without attachments', function (): void {
    expect(recordedInboundEmail($this->account)->attachments())->toBeEmpty()
        ->and($this->connector->attachmentCalls)->toBe(0);
});

it('matches subjects and senders case-insensitively', function (): void {
    $email = FakeConnector::makeEmail($this->account, ['subject' => 'Extrato VIA VERDE outubro', 'fromEmail' => 'noreply@viaverde.pt']);

    expect($email->subjectContains('via verde'))->toBeTrue()
        ->and($email->subjectContains('portagens', ''))->toBeFalse()
        ->and($email->isFrom('@ViaVerde.pt'))->toBeTrue()
        ->and($email->isFrom('noreply@viaverde.pt'))->toBeTrue()
        ->and($email->isFrom('outro@viaverde.pt'))->toBeFalse();
});

it('exposes the account context events in the configured order', function (): void {
    $account = MailAccount::factory()->withEvents(['hr', 'supplier'])->create();

    expect($account->eventClasses())->toBe([HrEmailReceived::class, SupplierEmailReceived::class]);
});

it('leaves the images embedded in the body out of the attachments, unless asked for', function (): void {
    $email = recordedInboundEmail($this->account, ['hasAttachments' => true]);
    $this->connector->attachmentsByMessage[$email->providerMessageId] = [
        new EmailAttachment('extrato.pdf', 'application/pdf', 4, '%PDF'),
        new EmailAttachment('logo.png', 'image/png', 3, 'png', isInline: true),
        new EmailAttachment('scan.pdf', 'application/pdf', 4, '%PDF', isInline: true),
    ];

    expect($email->attachments()->pluck('name')->all())->toBe(['extrato.pdf', 'scan.pdf'])
        ->and($email->attachments(includeInline: true)->pluck('name')->all())->toBe(['extrato.pdf', 'logo.png', 'scan.pdf'])
        ->and($this->connector->attachmentCalls)->toBe(1);
});

it('still downloads the embedded images of an email without real attachments when asked for', function (): void {
    $email = recordedInboundEmail($this->account, ['hasAttachments' => false]);
    $this->connector->attachmentsByMessage[$email->providerMessageId] = [new EmailAttachment('logo.png', 'image/png', 3, 'png', isInline: true)];

    expect($email->attachments())->toBeEmpty()
        ->and($this->connector->attachmentCalls)->toBe(0)
        ->and($email->attachments(includeInline: true))->toHaveCount(1);
});

it('treats only inline images as embedded images', function (bool $isInline, ?string $contentType, string $name, bool $embedded): void {
    expect((new EmailAttachment($name, $contentType, 1, 'x', $isInline))->isEmbeddedImage())->toBe($embedded);
})->with([
    'inline png' => [true, 'image/png', 'logo.png', true],
    'inline image without content type' => [true, null, 'logo.JPG', true],
    'attached png' => [false, 'image/png', 'foto.png', false],
    'inline pdf' => [true, 'application/pdf', 'extrato.pdf', false],
    'attached csv' => [false, 'text/csv', 'passagens.csv', false],
]);
