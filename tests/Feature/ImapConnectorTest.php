<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Mockery\MockInterface;
use Mupy\MailListeners\Connectors\ImapConnector;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Exceptions\ConnectorException;
use Mupy\MailListeners\Models\MailAccount;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Attribute;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Exceptions\AuthFailedException;
use Webklex\PHPIMAP\Exceptions\ConnectionFailedException;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Query\WhereQuery;
use Webklex\PHPIMAP\Support\AttachmentCollection;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * @param  array<string, mixed>  $overrides
 */
function imapMessage(array $overrides = []): Message
{
    $data = array_merge([
        'uid' => 42,
        'message_id' => 'abc@example.com',
        'subject' => ' Fatura outubro ',
        'from' => ['mail' => 'Fornecedor@Example.com', 'personal' => 'Fornecedor'],
        'to' => ['fornecedores@onevetgroup.pt'],
        'date' => '2026-10-06 10:00:00',
        'html' => '<p>Corpo</p>',
        'text' => 'Corpo',
        'attachments' => [],
    ], $overrides);

    /** @var Message&MockInterface $message */
    $message = Mockery::mock(Message::class);
    $message->shouldReceive('getUid')->andReturn($data['uid']);
    $message->shouldReceive('getMessageId')->andReturn(new Attribute('message_id', $data['message_id']));
    $message->shouldReceive('getSubject')->andReturn(new Attribute('subject', $data['subject']));
    $message->shouldReceive('getFrom')->andReturn(new Attribute('from', [new Address((object) $data['from'])]));
    $message->shouldReceive('getTo')->andReturn(new Attribute('to', array_map(fn (string $mail): Address => new Address((object) ['mail' => $mail]), $data['to'])));
    $message->shouldReceive('getDate')->andReturn(new Attribute('date', Carbon::parse($data['date'], 'UTC')));
    $message->shouldReceive('hasHTMLBody')->andReturn($data['html'] !== '');
    $message->shouldReceive('getHTMLBody')->andReturn($data['html']);
    $message->shouldReceive('getTextBody')->andReturn($data['text']);
    $message->shouldReceive('hasAttachments')->andReturn($data['attachments'] !== []);
    $message->shouldReceive('getAttachments')->andReturn(AttachmentCollection::make($data['attachments']));

    return $message;
}

function imapAttachment(string $name, string $mimeType, string $content, ?string $disposition = 'attachment'): Attachment
{
    /** @var Attachment&MockInterface $attachment */
    $attachment = Mockery::mock(Attachment::class);
    $attachment->shouldReceive('getName')->andReturn($name);
    $attachment->shouldReceive('getMimeType')->andReturn($mimeType);
    $attachment->shouldReceive('getContentType')->andReturn($mimeType);
    $attachment->shouldReceive('getContent')->andReturn($content);
    $attachment->shouldReceive('getDisposition')->andReturn($disposition);

    return $attachment;
}

/**
 * Fakes the IMAP server: the facade returns a client whose folder query returns the given messages.
 *
 * @param  list<Message>  $messages
 */
function fakeImapServer(array $messages = [], ?Folder $folder = null, ?Throwable $connectFailure = null): object
{
    $calls = new ArrayObject();

    /** @var WhereQuery&MockInterface $query */
    $query = Mockery::mock(WhereQuery::class);
    $query->shouldReceive('leaveUnread')->andReturnUsing(function () use ($query, $calls) {
        $calls['leaveUnread'] = ($calls['leaveUnread'] ?? 0) + 1;

        return $query;
    });
    $query->shouldReceive('setFetchOrder')->andReturnSelf();
    $query->shouldReceive('whereSince')->andReturnUsing(function ($date) use ($query, $calls) {
        $calls['since'] = $date;

        return $query;
    });
    $query->shouldReceive('whereBefore')->andReturnUsing(function ($date) use ($query, $calls) {
        $calls['before'] = $date;

        return $query;
    });
    $query->shouldReceive('chunked')->andReturnUsing(function (callable $callback) use ($messages): void {
        $callback(MessageCollection::make($messages), 1);
    });
    $query->shouldReceive('getMessageByUid')->andReturnUsing(function (int $uid) use ($messages): Message {
        foreach ($messages as $message) {
            if ($message->getUid() === $uid) {
                return $message;
            }
        }

        throw new RuntimeException('Not found');
    });

    if (! $folder instanceof Folder) {
        /** @var Folder&MockInterface $folder */
        $folder = Mockery::mock(Folder::class);
        $folder->shouldReceive('query')->andReturn($query);
    }

    /** @var ImapClient&MockInterface $client */
    $client = Mockery::mock(ImapClient::class);
    $client->shouldReceive('connect')->andReturnUsing(function () use ($client, $connectFailure) {
        if ($connectFailure instanceof Throwable) {
            throw $connectFailure;
        }

        return $client;
    });
    $client->shouldReceive('getFolderByPath')->andReturnUsing(function (string $path) use ($folder, $calls) {
        $calls['folder'] = $path;

        return $path === 'missing' ? null : $folder;
    });
    $client->shouldReceive('isConnected')->andReturn(true);
    $client->shouldReceive('disconnect')->andReturnUsing(function () use ($client, $calls) {
        $calls['disconnected'] = true;

        return $client;
    });

    Client::shouldReceive('make')->andReturnUsing(function (array $config) use ($client, $calls) {
        $calls['config'] = $config;

        return $client;
    });

    return $calls;
}

it('connects with the account settings and maps the messages within the exact period', function (): void {
    $account = MailAccount::factory()->imap()->create(['connector_settings' => [
        'host' => 'imap.example.com',
        'port' => 143,
        'encryption' => 'none',
        'validate_cert' => false,
        'username' => 'user@example.com',
        'password' => 'super-secret',
        'folder' => 'Faturas',
    ]]);
    $calls = fakeImapServer([
        imapMessage(['uid' => 1, 'subject' => 'Antes', 'date' => '2026-10-06 08:59:59']),
        imapMessage(['uid' => 2, 'attachments' => [imapAttachment('a.csv', 'text/csv', 'x')]]),
        imapMessage(['uid' => 3, 'subject' => 'Depois', 'date' => '2026-10-06 11:00:00']),
    ]);

    $emails = [];
    app(ImapConnector::class)->eachMessageBetween(
        $account,
        CarbonImmutable::parse('2026-10-06 09:00:00', 'UTC'),
        CarbonImmutable::parse('2026-10-06 11:00:00', 'UTC'),
        function (InboundEmail $email) use (&$emails): void {
            $emails[] = $email;
        },
    );

    expect($calls['config'])->toMatchArray([
        'host' => 'imap.example.com',
        'port' => 143,
        'protocol' => 'imap',
        'encryption' => 'notls',
        'validate_cert' => false,
        'username' => 'user@example.com',
        'password' => 'super-secret',
        'authentication' => null,
    ])
        ->and($calls['folder'])->toBe('Faturas')
        ->and($calls['leaveUnread'])->toBe(1)
        ->and($calls['disconnected'])->toBeTrue()
        ->and($emails)->toHaveCount(1)
        ->and($emails[0]->accountId)->toBe($account->id)
        ->and($emails[0]->providerMessageId)->toBe('2')
        ->and($emails[0]->internetMessageId)->toBe('abc@example.com')
        ->and($emails[0]->subject)->toBe('Fatura outubro')
        ->and($emails[0]->subjectDecoded)->toBe('Fatura outubro')
        ->and($emails[0]->fromEmail)->toBe('fornecedor@example.com')
        ->and($emails[0]->fromName)->toBe('Fornecedor')
        ->and($emails[0]->to)->toBe(['fornecedores@onevetgroup.pt'])
        ->and($emails[0]->receivedAt->toIso8601ZuluString())->toBe('2026-10-06T10:00:00Z')
        ->and($emails[0]->bodyContentType)->toBe('html')
        ->and($emails[0]->body)->toBe('<p>Corpo</p>')
        ->and($emails[0]->hasAttachments)->toBeTrue();
});

it('uses the text body when there is no HTML body, and the INBOX by default', function (): void {
    $account = MailAccount::factory()->imap()->create();
    $calls = fakeImapServer([imapMessage(['html' => '', 'text' => 'Só texto'])]);

    $emails = [];
    app(ImapConnector::class)->eachMessageBetween($account, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-07'), function (InboundEmail $email) use (&$emails): void {
        $emails[] = $email;
    });

    expect($calls['folder'])->toBe('INBOX')
        ->and($calls['config']['encryption'])->toBe('ssl')
        ->and($emails[0]->bodyContentType)->toBe('text')
        ->and($emails[0]->body)->toBe('Só texto');
});

it('downloads the attachments of a message in memory, without marking it as read', function (): void {
    $account = MailAccount::factory()->imap()->create();
    $calls = fakeImapServer([imapMessage(['uid' => 7, 'attachments' => [imapAttachment('passagens.csv', 'text/csv', "a;b\n1;2")]])]);

    $attachments = app(ImapConnector::class)->attachments($account, '7');

    expect($attachments)->toHaveCount(1)
        ->and($attachments[0]->name)->toBe('passagens.csv')
        ->and($attachments[0]->contentType)->toBe('text/csv')
        ->and($attachments[0]->size)->toBe(7)
        ->and($attachments[0]->content)->toBe("a;b\n1;2")
        ->and($calls['leaveUnread'])->toBe(1)
        ->and($calls['disconnected'])->toBeTrue();
});

it('fetches a single message again by its UID', function (): void {
    $account = MailAccount::factory()->imap()->create();
    fakeImapServer([imapMessage(['uid' => 9, 'subject' => 'Reprocessar'])]);

    expect(app(ImapConnector::class)->message($account, '9')->subject)->toBe('Reprocessar');
});

it('decodes MIME encoded subjects, sender names and attachment names', function (): void {
    $account = MailAccount::factory()->imap()->create();
    fakeImapServer([imapMessage([
        'uid' => 11,
        'subject' => '=?UTF-8?Q?Envio_de_Fatura_Eletr=C3=B3nica:_FT_A/874906386_de_2026-09-20?=',
        'from' => ['mail' => 'noreply@example.com', 'personal' => '=?ISO-8859-1?Q?Jo=E3o?='],
        'attachments' => [imapAttachment('=?UTF-8?B?ZmF0dXJhX27Cul8xLnBkZg==?=', 'application/pdf', '%PDF')],
    ])]);

    $email = app(ImapConnector::class)->message($account, '11');
    $attachments = app(ImapConnector::class)->attachments($account, '11');

    expect($email->subject)->toBe('=?UTF-8?Q?Envio_de_Fatura_Eletr=C3=B3nica:_FT_A/874906386_de_2026-09-20?=')
        ->and($email->subjectDecoded)->toBe('Envio de Fatura Eletrónica: FT A/874906386 de 2026-09-20')
        ->and($email->subjectContains('eletrónica'))->toBeTrue()
        ->and($email->fromName)->toBe('João')
        ->and($attachments[0]->name)->toBe('fatura_nº_1.pdf');
});

it('turns IMAP errors into readable connector errors, including their cause', function (): void {
    $account = MailAccount::factory()->imap()->create();
    fakeImapServer(connectFailure: new ConnectionFailedException('connection failed', 0, new AuthFailedException('[AUTHENTICATIONFAILED] Invalid credentials')));

    expect(fn () => app(ImapConnector::class)->testConnection($account))
        ->toThrow(ConnectorException::class, 'ConnectionFailedException: connection failed — [AUTHENTICATIONFAILED] Invalid credentials');
});

it('fails the connection test when the folder does not exist', function (): void {
    $account = MailAccount::factory()->imap()->create(['connector_settings' => [
        'host' => 'imap.example.com', 'port' => 993, 'encryption' => 'ssl', 'username' => 'u', 'password' => 'p', 'folder' => 'missing',
    ]]);
    fakeImapServer();

    expect(fn () => app(ImapConnector::class)->testConnection($account))
        ->toThrow(ConnectorException::class, 'The folder missing does not exist in the account.');
});

it('lets errors of the email processing through untouched', function (): void {
    $account = MailAccount::factory()->imap()->create();
    fakeImapServer([imapMessage()]);

    expect(fn () => app(ImapConnector::class)->eachMessageBetween(
        $account,
        CarbonImmutable::parse('2026-10-06'),
        CarbonImmutable::parse('2026-10-07'),
        fn () => throw new LogicException('Listener bug'),
    ))->toThrow(LogicException::class, 'Listener bug');
});

it('validates the IMAP settings and keeps the password secret', function (): void {
    expect(array_keys(ImapConnector::settingsRules(true)))->toBe(['host', 'port', 'encryption', 'validate_cert', 'username', 'password', 'folder'])
        ->and(ImapConnector::settingsRules(true)['password'][0])->toBe('required')
        ->and(ImapConnector::settingsRules(false)['password'][0])->toBe('nullable')
        ->and(ImapConnector::secretSettings())->toBe(['password']);
});

it('tells real attached files from images embedded in the body', function (array $attachments, bool $hasAttachments): void {
    $account = MailAccount::factory()->imap()->create();
    fakeImapServer([imapMessage(['attachments' => $attachments])]);

    $emails = [];
    app(ImapConnector::class)->eachMessageBetween($account, CarbonImmutable::parse('2026-10-06'), CarbonImmutable::parse('2026-10-07'), function (InboundEmail $email) use (&$emails): void {
        $emails[] = $email;
    });

    expect($emails[0]->hasAttachments)->toBe($hasAttachments);
})->with([
    'no attachments' => fn (): array => [[], false],
    'only a signature image' => fn (): array => [[imapAttachment('logo.png', 'image/png', 'png', 'inline')], false],
    'a regular file' => fn (): array => [[imapAttachment('passagens.csv', 'text/csv', 'a;b')], true],
    'a file sent inline (Apple Mail)' => fn (): array => [[imapAttachment('extrato.pdf', 'application/pdf', '%PDF', 'inline')], true],
    'an attached image' => fn (): array => [[imapAttachment('foto.jpg', 'image/jpeg', 'jpg')], true],
]);

it('marks the inline attachments', function (): void {
    $account = MailAccount::factory()->imap()->create();
    fakeImapServer([imapMessage(['uid' => 5, 'attachments' => [
        imapAttachment('extrato.pdf', 'application/pdf', '%PDF'),
        imapAttachment('logo.png', 'image/png', 'png', 'inline'),
    ]])]);

    $attachments = app(ImapConnector::class)->attachments($account, '5');

    expect(array_map(fn ($attachment): array => [$attachment->name, $attachment->isInline], $attachments))->toBe([
        ['extrato.pdf', false],
        ['logo.png', true],
    ]);
});
