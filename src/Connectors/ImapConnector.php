<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Connectors;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Validation\Rule;
use Mupy\MailListeners\Contracts\MailConnector;
use Mupy\MailListeners\Contracts\ReadsNewMessages;
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
 * so the mailbox is never changed. Polls read by UID ({@see eachNewMessage()}), periods by date.
 *
 * Connector settings: `host`, `port`, `encryption` (ssl, tls, starttls, none), `validate_cert`,
 * `username`, `password` (secret) and `folder` (default `mail-listeners.imap.default_folder`, "INBOX").
 */
final readonly class ImapConnector implements MailConnector, ReadsNewMessages
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

    /**
     * Polls by UID: only the messages above the last UID read are fetched, whatever their dates. The cursor holds the
     * folder, its UIDVALIDITY and the last UID; when the folder changes or its UIDs are reset (another UIDVALIDITY),
     * the messages since $since are read by date once (the emails already read are skipped by the reader).
     */
    public function eachNewMessage(MailAccount $account, ?string $cursor, CarbonImmutable $since, callable $callback): string
    {
        $folderPath = $account->connectorSetting('folder', self::defaultFolder());

        return $this->withFolder($account, function (Folder $folder) use ($account, $folderPath, $cursor, $since, $callback): string {
            $status = $folder->examine();
            $uidValidity = (int) ($status['uidvalidity'] ?? 0);
            $uidNext = isset($status['uidnext']) ? (int) $status['uidnext'] : null;
            $lastUid = $this->lastUid($cursor, $folderPath, $uidValidity);

            if ($lastUid !== null && $uidNext !== null && $uidNext <= $lastUid + 1) {
                return $this->cursor($folderPath, $uidValidity, $lastUid);
            }

            $query = $folder->query()->leaveUnread();
            $readSince = null;

            if ($lastUid === null) {
                // IMAP SEARCH only filters by day (in the server timezone): search a wider window, filter exactly below.
                $readSince = $since;
                $uids = $this->sortedUids($query->whereSince($since->subDay()->startOfDay())->search());
                $lastUid = max(0, ($uidNext ?? 1) - 1, ...$uids);
            } else {
                // CUSTOM keeps the UID set unquoted (whereUid() would quote it). "N:*" also matches the last message
                // when there is none above N: keep only the UIDs above the cursor.
                $uids = array_values(array_filter(
                    $this->sortedUids($query->where('CUSTOM UID '.($lastUid + 1).':*')->search()),
                    fn (int $uid): bool => $uid > $lastUid,
                ));
            }

            // Only the messages found are fetched (with their body), a chunk per IMAP round trip.
            foreach (array_chunk($uids, self::CHUNK_SIZE) as $chunk) {
                $messages = $query->curate_messages(collect($chunk))->sortBy(fn (Message $message): int => (int) $message->getUid());

                foreach ($messages as $message) {
                    $email = $this->toInboundEmail($account, $message);
                    $uid = (int) $message->getUid();
                    $lastUid = max($lastUid, $uid);

                    if (! $readSince instanceof CarbonImmutable || $email->receivedAt->greaterThanOrEqualTo($readSince)) {
                        $callback($email, $this->cursor($folderPath, $uidValidity, $uid));
                    }
                }
            }

            return $this->cursor($folderPath, $uidValidity, $lastUid);
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
                    name: $this->decodeHeader((string) $attachment->getName()),
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
     * @param  Collection<int, mixed>  $uids
     * @return list<int>
     */
    private function sortedUids(Collection $uids): array
    {
        $sorted = array_map(intval(...), $uids->values()->all());
        sort($sorted);

        return $sorted;
    }

    private function cursor(string $folder, int $uidValidity, int $uid): string
    {
        return (string) json_encode(['folder' => $folder, 'uidvalidity' => $uidValidity, 'uid' => $uid]);
    }

    /**
     * Last UID read, or null when there is no cursor yet or it belongs to another folder or UIDVALIDITY.
     */
    private function lastUid(?string $cursor, string $folder, int $uidValidity): ?int
    {
        $data = $cursor !== null ? json_decode($cursor, true) : null;

        if (! is_array($data) || ($data['folder'] ?? null) !== $folder || ($data['uidvalidity'] ?? null) !== $uidValidity || ! is_int($data['uid'] ?? null)) {
            return null;
        }

        return $data['uid'];
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
        $subject = mb_trim((string) $message->getSubject());

        try {
            $receivedAt = CarbonImmutable::instance($message->getDate()->toDate());
        } catch (Throwable) {
            $receivedAt = CarbonImmutable::now();
        }

        return new InboundEmail(
            accountId: $account->id,
            providerMessageId: (string) $message->getUid(),
            internetMessageId: $messageId !== '' ? $messageId : null,
            subject: $subject,
            fromEmail: $from instanceof Address && $from->mail !== '' ? mb_strtolower(mb_trim($from->mail)) : null,
            fromName: $from instanceof Address && $from->personal !== '' ? mb_trim($this->decodeHeader($from->personal)) : null,
            to: array_values(array_filter(array_map(
                fn (mixed $address): ?string => $address instanceof Address && $address->mail !== '' ? mb_strtolower(mb_trim($address->mail)) : null,
                $message->getTo()->all(),
            ))),
            receivedAt: $receivedAt,
            bodyContentType: $message->hasHTMLBody() ? 'html' : 'text',
            body: $message->hasHTMLBody() ? $message->getHTMLBody() : $message->getTextBody(),
            hasAttachments: $this->hasRealAttachments($message),
            subjectDecoded: mb_trim($this->decodeHeader($subject)),
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

    /**
     * Decodes MIME encoded words (e.g. "=?UTF-8?Q?Eletr=C3=B3nica?="). Without the PHP imap extension
     * (removed in PHP 8.4), webklex returns some headers still encoded.
     */
    private function decodeHeader(string $value): string
    {
        if (! str_contains($value, '=?')) {
            return $value;
        }

        $decoded = iconv_mime_decode($value, ICONV_MIME_DECODE_CONTINUE_ON_ERROR, 'UTF-8');

        return $decoded !== false ? $decoded : mb_decode_mimeheader($value);
    }

    private function isInline(Attachment $attachment): bool
    {
        return mb_strtolower(mb_trim((string) $attachment->getDisposition())) === 'inline';
    }
}
