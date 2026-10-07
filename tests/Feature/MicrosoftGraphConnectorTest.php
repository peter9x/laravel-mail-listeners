<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use GuzzleHttp\Psr7\Utils;
use Microsoft\Graph\Generated\Models\Attachment;
use Microsoft\Graph\Generated\Models\BodyType;
use Microsoft\Graph\Generated\Models\EmailAddress;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\ItemBody;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Kiota\Abstractions\ApiException;
use Mockery\MockInterface;
use Mupy\MailListeners\Connectors\MicrosoftGraph\GraphMailClient;
use Mupy\MailListeners\Connectors\MicrosoftGraphConnector;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Exceptions\ConnectorException;
use Mupy\MailListeners\Models\MailAccount;

function graphRecipient(string $address, ?string $name = null): Recipient
{
    $emailAddress = new EmailAddress();
    $emailAddress->setAddress($address);
    $emailAddress->setName($name);

    $recipient = new Recipient();
    $recipient->setEmailAddress($emailAddress);

    return $recipient;
}

/**
 * @param  list<Attachment>|null  $attachments  attachments listed with the message ($expand), null when not expanded
 */
function graphMessage(string $id, string $subject, bool $isDraft = false, ?array $attachments = null): Message
{
    $body = new ItemBody();
    $body->setContentType(new BodyType('html'));
    $body->setContent('<p>Corpo</p>');

    $message = new Message();
    $message->setId($id);
    $message->setInternetMessageId("<{$id}@example.com>");
    $message->setSubject(" {$subject} ");
    $message->setFrom(graphRecipient(' Fornecedor@Example.com ', 'Fornecedor'));
    $message->setToRecipients([graphRecipient('Fornecedores@OneVetGroup.pt')]);
    $message->setReceivedDateTime(new DateTime('2026-10-06T10:00:00Z'));
    $message->setBody($body);
    $message->setHasAttachments(true);
    $message->setIsDraft($isDraft);

    if ($attachments !== null) {
        $message->setAttachments($attachments);
    }

    return $message;
}

function graphFileAttachment(string $name, string $contentType, bool $isInline = false): FileAttachment
{
    $attachment = new FileAttachment();
    $attachment->setName($name);
    $attachment->setContentType($contentType);
    $attachment->setIsInline($isInline);

    return $attachment;
}

it('reads the configured folder and tenant within the given period, maps the messages and skips drafts', function (): void {
    $account = MailAccount::factory()->create([
        'email' => 'fornecedores@onevetgroup.pt',
        'connector_settings' => ['tenant' => 'other', 'folder' => 'Faturas'],
    ]);
    $from = CarbonImmutable::parse('2026-10-06 09:00:00');
    $to = CarbonImmutable::parse('2026-10-06 11:00:00');

    $this->mock(GraphMailClient::class, function (MockInterface $mock) use ($from, $to): void {
        $mock->shouldReceive('auth')->once()->with('other')->andReturnSelf();
        $mock->shouldReceive('eachMessageReceivedBetween')
            ->once()
            ->withArgs(fn (string $mailbox, DateTimeInterface $readFrom, DateTimeInterface $readTo, callable $callback, array $select, ?string $mailFolder, array $expand): bool => $mailbox === 'fornecedores@onevetgroup.pt'
                && $expand === ['attachments($select=name,contentType,isInline,size)']
                && CarbonImmutable::instance($readFrom)->equalTo($from)
                && CarbonImmutable::instance($readTo)->equalTo($to)
                && $mailFolder === 'Faturas')
            ->andReturnUsing(function (string $mailbox, DateTimeInterface $from, DateTimeInterface $to, callable $callback): void {
                $callback(graphMessage('m-1', 'Fatura 1'));
                $callback(graphMessage('m-2', 'Rascunho', isDraft: true));
            });
    });

    $emails = [];
    app(MicrosoftGraphConnector::class)->eachMessageBetween($account, $from, $to, function (InboundEmail $email) use (&$emails): void {
        $emails[] = $email;
    });

    expect($emails)->toHaveCount(1)
        ->and($emails[0]->accountId)->toBe($account->id)
        ->and($emails[0]->providerMessageId)->toBe('m-1')
        ->and($emails[0]->internetMessageId)->toBe('<m-1@example.com>')
        ->and($emails[0]->subject)->toBe('Fatura 1')
        ->and($emails[0]->fromEmail)->toBe('fornecedor@example.com')
        ->and($emails[0]->fromName)->toBe('Fornecedor')
        ->and($emails[0]->to)->toBe(['fornecedores@onevetgroup.pt'])
        ->and($emails[0]->receivedAt->toIso8601ZuluString())->toBe('2026-10-06T10:00:00Z')
        ->and($emails[0]->bodyContentType)->toBe('html')
        ->and($emails[0]->bodyText())->toBe('Corpo')
        ->and($emails[0]->hasAttachments)->toBeTrue();
});

it('defaults to the inbox of the default tenant', function (): void {
    $account = MailAccount::factory()->create(['connector_settings' => null]);

    $this->mock(GraphMailClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('auth')->once()->with('default')->andReturnSelf();
        $mock->shouldReceive('eachMessageReceivedBetween')
            ->once()
            ->withArgs(fn (...$arguments): bool => $arguments[5] === 'inbox');
    });

    app(MicrosoftGraphConnector::class)->eachMessageBetween($account, CarbonImmutable::now()->subHour(), CarbonImmutable::now(), fn () => null);
});

it('reads every folder of the mailbox when the folder is "*"', function (): void {
    $account = MailAccount::factory()->create(['connector_settings' => ['folder' => MicrosoftGraphConnector::ALL_FOLDERS]]);

    $this->mock(GraphMailClient::class, function (MockInterface $mock): void {
        $mock->shouldReceive('auth')->twice()->andReturnSelf();
        $mock->shouldReceive('eachMessageReceivedBetween')
            ->twice()
            ->withArgs(fn (...$arguments): bool => $arguments[5] === null);
    });

    app(MicrosoftGraphConnector::class)->eachMessageBetween($account, CarbonImmutable::now()->subHour(), CarbonImmutable::now(), fn () => null);
    app(MicrosoftGraphConnector::class)->testConnection($account);
});

it('downloads the file attachments of a message, decoded', function (): void {
    $account = MailAccount::factory()->create();
    $attachment = new FileAttachment();
    $attachment->setName('passagens.csv');
    $attachment->setContentType('text/csv');
    $attachment->setSize(7);
    $attachment->setContentBytes(Utils::streamFor(base64_encode("a;b\n1;2")));

    $this->mock(GraphMailClient::class, function (MockInterface $mock) use ($account, $attachment): void {
        $mock->shouldReceive('auth')->andReturnSelf();
        $mock->shouldReceive('getMessageFileAttachments')->once()->with($account->email, 'm-1')->andReturn([$attachment]);
    });

    $attachments = app(MicrosoftGraphConnector::class)->attachments($account, 'm-1');

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->name)->toBe('passagens.csv')
        ->and($attachments[0]->contentType)->toBe('text/csv')
        ->and($attachments[0]->size)->toBe(7)
        ->and($attachments[0]->content)->toBe("a;b\n1;2");
});

it('fetches a single message again', function (): void {
    $account = MailAccount::factory()->create();

    $this->mock(GraphMailClient::class, function (MockInterface $mock) use ($account): void {
        $mock->shouldReceive('auth')->andReturnSelf();
        $mock->shouldReceive('getMessage')->once()->withArgs(fn (string $mailbox, string $id): bool => $mailbox === $account->email && $id === 'm-1')->andReturn(graphMessage('m-1', 'Fatura 1'));
    });

    expect(app(MicrosoftGraphConnector::class)->message($account, 'm-1')->subject)->toBe('Fatura 1');
});

it('turns Microsoft Graph errors, which have an empty message, into readable ones', function (ApiException $graphError, string $expectedMessage): void {
    $account = MailAccount::factory()->create();

    $this->mock(GraphMailClient::class, function (MockInterface $mock) use ($graphError): void {
        $mock->shouldReceive('auth')->andReturnSelf();
        $mock->shouldReceive('eachMessageReceivedBetween')->andThrow($graphError);
    });

    try {
        app(MicrosoftGraphConnector::class)->testConnection($account);
        $this->fail('The connection test should fail.');
    } catch (ConnectorException $exception) {
        expect($exception->getMessage())->toBe($expectedMessage)
            ->and($exception->getPrevious())->toBe($graphError);
    }
})->with([
    'odata error' => fn (): array => [graphODataError('ErrorInvalidUser', "The requested user 'x@onevetgroup.pt' is invalid.", 404), "ErrorInvalidUser: The requested user 'x@onevetgroup.pt' is invalid. (HTTP 404)"],
    'error without details' => fn (): array => [graphApiError(403), 'Microsoft Graph API error (HTTP 403)'],
]);

it('tells real attached files from images embedded in the body, from the attachments listed with the message', function (?array $attachments, bool $hasAttachments): void {
    $account = MailAccount::factory()->create();

    $this->mock(GraphMailClient::class, function (MockInterface $mock) use ($attachments): void {
        $mock->shouldReceive('auth')->andReturnSelf();
        $mock->shouldReceive('eachMessageReceivedBetween')->andReturnUsing(function (string $mailbox, DateTimeInterface $from, DateTimeInterface $to, callable $callback) use ($attachments): void {
            $message = graphMessage('m-1', 'Fatura', attachments: $attachments);
            $message->setHasAttachments(false);
            $callback($message);
        });
    });

    $emails = [];
    app(MicrosoftGraphConnector::class)->eachMessageBetween($account, CarbonImmutable::now()->subDay(), CarbonImmutable::now(), function (InboundEmail $email) use (&$emails): void {
        $emails[] = $email;
    });

    expect($emails[0]->hasAttachments)->toBe($hasAttachments);
})->with([
    'no attachments' => fn (): array => [[], false],
    'only signature images' => fn (): array => [[graphFileAttachment('logo.png', 'image/png', true), graphFileAttachment('icon.jpg', 'image/jpeg', true)], false],
    'a regular file and a signature' => fn (): array => [[graphFileAttachment('extrato.pdf', 'application/pdf'), graphFileAttachment('logo.png', 'image/png', true)], true],
    'a file sent inline (Apple Mail)' => fn (): array => [[graphFileAttachment('extrato.pdf', 'application/pdf', true)], true],
    'not expanded: falls back to Graph flag' => fn (): array => [null, false],
]);

it('marks the inline attachments it downloads', function (): void {
    $account = MailAccount::factory()->create();
    $logo = graphFileAttachment('logo.png', 'image/png', true);
    $logo->setContentBytes(Utils::streamFor(base64_encode('png')));

    $this->mock(GraphMailClient::class, function (MockInterface $mock) use ($logo): void {
        $mock->shouldReceive('auth')->andReturnSelf();
        $mock->shouldReceive('getMessageFileAttachments')->andReturn([$logo]);
    });

    expect(app(MicrosoftGraphConnector::class)->attachments($account, 'm-1')[0]->isInline)->toBeTrue();
});

it('lists the configured tenants and requires a known one', function (): void {
    expect(MicrosoftGraphConnector::tenants())->toBe(['default', 'other'])
        ->and(MicrosoftGraphConnector::defaultSettings())->toBe(['tenant' => 'default', 'folder' => null]);

    expect(fn () => (new GraphMailClient())->auth('unknown'))
        ->toThrow(ConnectorException::class, 'The Microsoft Graph tenant unknown is not configured.');
});
