<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Validation\Rule;
use Mupy\MailListeners\Contracts\MailConnector;
use Mupy\MailListeners\Data\EmailAttachment;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Exceptions\ConnectorException;
use Mupy\MailListeners\Models\MailAccount;
use Throwable;
use Webklex\IMAP\Facades\Client;
use Webklex\PHPIMAP\Address;
use Webklex\PHPIMAP\Attachment;
use Webklex\PHPIMAP\Client as ImapClient;
use Webklex\PHPIMAP\Folder;
use Webklex\PHPIMAP\Message;
use Webklex\PHPIMAP\Support\MessageCollection;

/**
 * Reads a mailbox through IMAP (user + password). Messages are always fetched with BODY.PEEK (leaveUnread),
 * so the mailbox is never changed.
 *
 * Connector settings: `host`, `port`, `encryption` (ssl, tls, starttls, none), `validate_cert`,
 * `username`, `password` (secret) and `folder` (default `mail-listeners.imap.default_folder`, "INBOX").
 */
final readonly class ImapConnector implements MailConnector
{
    public const int DEFAULT_PORT = 993;

    /**
     * @var list<string>
     */
    public const array ENCRYPTIONS = ['ssl', 'tls', 'starttls', 'none'];

    /**
     * Messages fetched (with their body) per IMAP round trip, to keep memory low on long periods.
     */
    private const int CHUNK_SIZE = 25;

    public static function label(): string
    {
        return __('IMAP');
    }

    public static function settingsRules(bool $creating): array
    {
        return [
            'host' => ['required', 'string', 'max:255'],
            'port' => ['required', 'integer', 'between:1,65535'],
            'encryption' => ['required', 'string', Rule::in(self::ENCRYPTIONS)],
            'validate_cert' => ['boolean'],
            'username' => ['required', 'string', 'max:255'],
            'password' => [$creating ? 'required' : 'nullable', 'string', 'max:1000'],
            'folder' => ['nullable', 'string', 'max:255'],
        ];
    }

    public static function secretSettings(): array
    {
        return ['password'];
    }

    public static function defaultSettings(): array
    {
        return [
            'host' => null,
            'port' => self::DEFAULT_PORT,
            'encryption' => 'ssl',
            'validate_cert' => true,
            'username' => null,
            'folder' => null,
        ];
    }

    public static function defaultFolder(): string
    {
        return (string) config('mail-listeners.imap.default_folder', 'INBOX');
    }

    public function eachMessageBetween(MailAccount $account, CarbonImmutable $from, CarbonImmutable $to, callable $callback): void
    {
        $this->withFolder($account, function (Folder $folder) use ($account, $from, $to, $callback): void {
            // IMAP SEARCH only filters by day (in the server timezone): search a wider window, filter exactly below.
            $folder->query()
                ->leaveUnread()
                ->setFetchOrder('asc')
                ->whereSince($from->subDay()->startOfDay())
                ->whereBefore($to->addDays(2)->startOfDay())
                ->chunked(function (MessageCollection $messages) use ($account, $from, $to, $callback): void {
                    foreach ($messages as $message) {
                        $email = $this->toInboundEmail($account, $message);

                        if ($email->receivedAt->greaterThanOrEqualTo($from) && $email->receivedAt->lessThan($to)) {
                            $callback($email);
                        }
                    }
                }, self::CHUNK_SIZE);
        });
    }

    public function message(MailAccount $account, string $providerMessageId): InboundEmail
    {
        return $this->withFolder(
            $account,
            fn (Folder $folder): InboundEmail => $this->toInboundEmail($account, $this->messageByUid($folder, $providerMessageId)),
        );
    }

    public function attachments(MailAccount $account, string $providerMessageId): array
    {
        return $this->withFolder($account, fn (Folder $folder): array => $this->messageByUid($folder, $providerMessageId)
            ->getAttachments()
            ->map(function (Attachment $attachment): EmailAttachment {
                $content = (string) $attachment->getContent();

                return new EmailAttachment(
                    name: (string) $attachment->getName(),
                    contentType: $attachment->getMimeType() ?? $attachment->getContentType(),
                    size: mb_strlen($content, '8bit'),
                    content: $content,
                    isInline: $this->isInline($attachment),
                );
            })
            ->values()
            ->all());
    }

    public function testConnection(MailAccount $account): void
    {
        $this->withFolder($account, fn (): null => null);
    }

    /**
     * Connect, open the configured folder and run the callback, always disconnecting.
     * IMAP library errors are turned into readable connector errors; other errors are rethrown as is.
     *
     * @template TResult
     *
     * @param  callable(Folder): TResult  $callback
     * @return TResult
     *
     * @throws ConnectorException
     */
    private function withFolder(MailAccount $account, callable $callback): mixed
    {
        $client = null;

        try {
            $client = Client::make($this->clientConfig($account));
            $client->connect();

            $folderPath = $account->connectorSetting('folder', self::defaultFolder());
            $folder = $client->getFolderByPath($folderPath);

            if (! $folder instanceof Folder) {
                throw new ConnectorException(__('The folder :folder does not exist in the account.', ['folder' => $folderPath]));
            }

            return $callback($folder);
        } catch (Throwable $exception) {
            throw str_starts_with($exception::class, 'Webklex\\') ? ConnectorException::fromImap($exception) : $exception;
        } finally {
            $this->disconnect($client);
        }
    }

    private function disconnect(?ImapClient $client): void
    {
        try {
            if ($client instanceof ImapClient && $client->isConnected()) {
                $client->disconnect();
            }
        } catch (Throwable) {
            // The connection is being dropped anyway.
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function clientConfig(MailAccount $account): array
    {
        $settings = $account->connector_settings ?? [];
        $encryption = $settings['encryption'] ?? 'ssl';

        return [
            'host' => (string) ($settings['host'] ?? ''),
            'port' => (int) ($settings['port'] ?? self::DEFAULT_PORT),
            'protocol' => 'imap',
            'encryption' => $encryption === 'none' ? 'notls' : $encryption,
            'validate_cert' => (bool) ($settings['validate_cert'] ?? true),
            'username' => (string) ($settings['username'] ?? ''),
            'password' => (string) ($settings['password'] ?? ''),
            'authentication' => null,
            'timeout' => 30,
        ];
    }

    private function messageByUid(Folder $folder, string $uid): Message
    {
        return $folder->query()->leaveUnread()->getMessageByUid((int) $uid);
    }

    private function toInboundEmail(MailAccount $account, Message $message): InboundEmail
    {
        $from = $message->getFrom()->first();
        $messageId = mb_trim((string) $message->getMessageId());

        try {
            $receivedAt = CarbonImmutable::instance($message->getDate()->toDate());
        } catch (Throwable) {
            $receivedAt = CarbonImmutable::now();
        }

        return new InboundEmail(
            accountId: $account->id,
            providerMessageId: (string) $message->getUid(),
            internetMessageId: $messageId !== '' ? $messageId : null,
            subject: mb_trim((string) $message->getSubject()),
            fromEmail: $from instanceof Address && $from->mail !== '' ? mb_strtolower(mb_trim($from->mail)) : null,
            fromName: $from instanceof Address && $from->personal !== '' ? mb_trim($from->personal) : null,
            to: array_values(array_filter(array_map(
                fn (mixed $address): ?string => $address instanceof Address && $address->mail !== '' ? mb_strtolower(mb_trim($address->mail)) : null,
                $message->getTo()->all(),
            ))),
            receivedAt: $receivedAt,
            bodyContentType: $message->hasHTMLBody() ? 'html' : 'text',
            body: $message->hasHTMLBody() ? $message->getHTMLBody() : $message->getTextBody(),
            hasAttachments: $this->hasRealAttachments($message),
        );
    }

    /**
     * Whether the message has real attached files: images embedded in the body (e.g. signatures) do not count,
     * but files sent inline by some mail clients (e.g. a PDF from Apple Mail) do.
     */
    private function hasRealAttachments(Message $message): bool
    {
        foreach ($message->getAttachments() as $attachment) {
            if (! EmailAttachment::isEmbeddedImageType($this->isInline($attachment), $attachment->getContentType(), $attachment->getName())) {
                return true;
            }
        }

        return false;
    }

    private function isInline(Attachment $attachment): bool
    {
        return mb_strtolower(mb_trim((string) $attachment->getDisposition())) === 'inline';
    }
}
