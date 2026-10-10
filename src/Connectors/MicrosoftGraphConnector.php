<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Connectors;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Validation\Rule;
use Microsoft\Graph\Generated\Models\FileAttachment;
use Microsoft\Graph\Generated\Models\Message;
use Microsoft\Graph\Generated\Models\Recipient;
use Microsoft\Kiota\Abstractions\ApiException;
use Mupy\MailListeners\Connectors\MicrosoftGraph\GraphMailClient;
use Mupy\MailListeners\Contracts\MailConnector;
use Mupy\MailListeners\Data\EmailAttachment;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Exceptions\ConnectorException;
use Mupy\MailListeners\Models\MailAccount;

/**
 * Reads an Office 365 mailbox through Microsoft Graph with application credentials (Mail.Read).
 *
 * Connector settings: `connection` (the credentials: a connection of `mail-listeners.microsoft_graph.connections`,
 * or one known to {@see resolveCredentialsUsing()}; default `mail-listeners.microsoft_graph.default_connection`) and
 * `folder` (well-known folder name or id, default "inbox"; `*` reads every folder of the mailbox).
 */
final class MicrosoftGraphConnector implements MailConnector
{
    /**
     * `folder` value that reads the messages of every folder (Graph `/users/{id}/messages`).
     */
    public const string ALL_FOLDERS = '*';

    /**
     * @var list<string>
     */
    private const array MESSAGE_SELECT = [
        'id',
        'subject',
        'body',
        'from',
        'toRecipients',
        'receivedDateTime',
        'hasAttachments',
        'internetMessageId',
        'isDraft',
    ];

    /**
     * Attachments metadata (no content) listed with each message, to tell real files from embedded images.
     *
     * @var list<string>
     */
    private const array MESSAGE_EXPAND = ['attachments($select=name,contentType,isInline,size)'];

    /**
     * @var (Closure(string, MailAccount): ?array<string, mixed>)|null
     */
    private static ?Closure $credentialsResolver = null;

    public function __construct(private readonly GraphMailClient $graph) {}

    /**
     * Resolve the credentials of the connections in code, e.g. from a settings table of the tenant of the account:
     * the callback returns `['tenant' => ..., 'client_id' => ..., 'client_secret' => ...]`, or null to use the
     * `mail-listeners.microsoft_graph.connections` config. Pass null to remove it.
     *
     * @param  (Closure(string $connection, MailAccount $account): ?array<string, mixed>)|null  $resolver
     */
    public static function resolveCredentialsUsing(?Closure $resolver): void
    {
        self::$credentialsResolver = $resolver;
    }

    /**
     * Credentials of the connection: from the resolver when it returns them, from the config otherwise.
     *
     * @return array<string, mixed>|null
     */
    public static function credentials(string $connection, MailAccount $account): ?array
    {
        $credentials = self::$credentialsResolver instanceof Closure ? (self::$credentialsResolver)($connection, $account) : null;

        return $credentials ?? ((array) config('mail-listeners.microsoft_graph.connections', []))[$connection] ?? null;
    }

    public static function label(): string
    {
        return __('Office 365 (Microsoft Graph)');
    }

    public static function settingsRules(bool $creating): array
    {
        return [
            // Connections resolved in code cannot be listed: they are checked when connecting.
            'connection' => self::$credentialsResolver instanceof Closure
                ? ['nullable', 'string', 'max:100']
                : ['nullable', 'string', Rule::in(self::connections())],
            'folder' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function secretSettings(): array
    {
        return [];
    }

    public static function defaultSettings(): array
    {
        return ['connection' => self::defaultConnection(), 'folder' => null];
    }

    /**
     * Connections configured in `mail-listeners.microsoft_graph.connections`.
     *
     * @return list<string>
     */
    public static function connections(): array
    {
        return array_keys((array) config('mail-listeners.microsoft_graph.connections', []));
    }

    public static function defaultConnection(): string
    {
        return (string) config('mail-listeners.microsoft_graph.default_connection', 'default');
    }

    public static function defaultFolder(): string
    {
        return (string) config('mail-listeners.microsoft_graph.default_folder', 'inbox');
    }

    public function eachMessageBetween(MailAccount $account, CarbonImmutable $from, CarbonImmutable $to, callable $callback): void
    {
        $this->call(fn () => $this->client($account)->eachMessageReceivedBetween(
            $account->email,
            $from,
            $to,
            function (Message $message) use ($account, $callback): void {
                if ($message->getIsDraft() === true) {
                    return;
                }

                $callback($this->toInboundEmail($account, $message));
            },
            select: self::MESSAGE_SELECT,
            mailFolder: $this->mailFolder($account),
            expand: self::MESSAGE_EXPAND,
        ));
    }

    public function message(MailAccount $account, string $providerMessageId): InboundEmail
    {
        return $this->toInboundEmail(
            $account,
            $this->call(fn (): Message => $this->client($account)->getMessage($account->email, $providerMessageId, self::MESSAGE_SELECT, self::MESSAGE_EXPAND)),
        );
    }

    public function attachments(MailAccount $account, string $providerMessageId): array
    {
        return array_map(
            fn (FileAttachment $attachment): EmailAttachment => new EmailAttachment(
                name: (string) $attachment->getName(),
                contentType: $attachment->getContentType(),
                size: (int) $attachment->getSize(),
                content: (string) base64_decode((string) $attachment->getContentBytes(), true),
                isInline: $attachment->getIsInline() === true,
            ),
            $this->call(fn (): array => $this->client($account)->getMessageFileAttachments($account->email, $providerMessageId)),
        );
    }

    public function testConnection(MailAccount $account): void
    {
        $this->call(fn () => $this->client($account)->eachMessageReceivedBetween(
            $account->email,
            CarbonImmutable::now()->subDay(),
            CarbonImmutable::now(),
            fn (): bool => false,
            select: ['id'],
            mailFolder: $this->mailFolder($account),
        ));
    }

    /**
     * Run a Microsoft Graph call, turning its errors (which usually have an empty message) into readable ones.
     *
     * @template TResult
     *
     * @param  callable(): TResult  $graphCall
     * @return TResult
     *
     * @throws ConnectorException
     */
    private function call(callable $graphCall): mixed
    {
        try {
            return $graphCall();
        } catch (ApiException $exception) {
            throw ConnectorException::fromMicrosoftGraph($exception);
        }
    }

    private function client(MailAccount $account): GraphMailClient
    {
        $connection = (string) $account->connectorSetting('connection', self::defaultConnection());

        return $this->graph->auth(self::credentials($connection, $account), $connection);
    }

    /**
     * Folder to read, or null for every folder of the mailbox.
     */
    private function mailFolder(MailAccount $account): ?string
    {
        $folder = $account->connectorSetting('folder', self::defaultFolder());

        return $folder === self::ALL_FOLDERS ? null : $folder;
    }

    private function toInboundEmail(MailAccount $account, Message $message): InboundEmail
    {
        $from = $message->getFrom()?->getEmailAddress();
        $receivedAt = $message->getReceivedDateTime();

        return new InboundEmail(
            accountId: $account->id,
            providerMessageId: (string) $message->getId(),
            internetMessageId: $message->getInternetMessageId(),
            subject: mb_trim((string) $message->getSubject()),
            fromEmail: $from?->getAddress() !== null ? mb_strtolower(mb_trim($from->getAddress())) : null,
            fromName: $from?->getName() !== null ? mb_trim($from->getName()) : null,
            to: array_values(array_filter(array_map(
                fn (Recipient $recipient): ?string => $recipient->getEmailAddress()?->getAddress() !== null
                    ? mb_strtolower(mb_trim($recipient->getEmailAddress()->getAddress()))
                    : null,
                $message->getToRecipients() ?? [],
            ))),
            receivedAt: $receivedAt !== null ? CarbonImmutable::instance($receivedAt) : CarbonImmutable::now(),
            bodyContentType: $message->getBody()?->getContentType()?->value() ?? 'text',
            body: (string) $message->getBody()?->getContent(),
            hasAttachments: $this->hasRealAttachments($message),
        );
    }

    /**
     * Graph's own `hasAttachments` ignores inline attachments, so a file sent inline (e.g. a PDF from Apple Mail) would
     * be missed: decide from the attachments listed with the message, leaving out only images embedded in the body.
     */
    private function hasRealAttachments(Message $message): bool
    {
        $attachments = $message->getAttachments();

        if ($attachments === null) {
            return $message->getHasAttachments() === true;
        }

        foreach ($attachments as $attachment) {
            if ($attachment instanceof FileAttachment
                && ! EmailAttachment::isEmbeddedImageType($attachment->getIsInline() === true, $attachment->getContentType(), $attachment->getName())) {
                return true;
            }
        }

        return false;
    }
}
