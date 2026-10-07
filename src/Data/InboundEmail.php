<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Data;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Mupy\MailListeners\Models\MailAccount;

/**
 * An email read from a mail account, as delivered to the listeners.
 * Attachments are not part of the payload: they are downloaded lazily by the listener that asks for them.
 */
final class InboundEmail
{
    /**
     * @var Collection<int, EmailAttachment>|null
     */
    private ?Collection $loadedAttachments = null;

    private ?MailAccount $loadedAccount = null;

    /**
     * @param  list<string>  $to
     */
    public function __construct(
        public readonly int $accountId,
        public readonly string $providerMessageId,
        public readonly ?string $internetMessageId,
        public readonly string $subject,
        public readonly ?string $fromEmail,
        public readonly ?string $fromName,
        public readonly array $to,
        public readonly CarbonImmutable $receivedAt,
        public readonly string $bodyContentType,
        public readonly string $body,
        /** Whether the email has real attached files (images embedded in the body, e.g. signatures, do not count). */
        public readonly bool $hasAttachments,
        /** Id of the `mail_listener_messages` row, set once the email is recorded. */
        public readonly ?int $messageId = null,
    ) {}

    /**
     * Lazy state (account, attachments) is never serialized into the queue payload.
     *
     * @return list<string>
     */
    public function __sleep(): array
    {
        return [
            'accountId',
            'providerMessageId',
            'internetMessageId',
            'subject',
            'fromEmail',
            'fromName',
            'to',
            'receivedAt',
            'bodyContentType',
            'body',
            'hasAttachments',
            'messageId',
        ];
    }

    /**
     * Copy of this email linked to its `mail_listener_messages` row.
     */
    public function withMessageId(int $messageId): self
    {
        return new self(
            accountId: $this->accountId,
            providerMessageId: $this->providerMessageId,
            internetMessageId: $this->internetMessageId,
            subject: $this->subject,
            fromEmail: $this->fromEmail,
            fromName: $this->fromName,
            to: $this->to,
            receivedAt: $this->receivedAt,
            bodyContentType: $this->bodyContentType,
            body: $this->body,
            hasAttachments: $this->hasAttachments,
            messageId: $messageId,
        );
    }

    /**
     * Key used to detect the same email read twice: the RFC Message-ID when available, the provider id otherwise.
     */
    public function dedupKey(): string
    {
        return sha1($this->internetMessageId ?: $this->providerMessageId);
    }

    public function account(): MailAccount
    {
        return $this->loadedAccount ??= MailAccount::query()->findOrFail($this->accountId);
    }

    /**
     * File attachments, downloaded from the provider on first access and kept in memory for this process.
     * Images embedded in the body (e.g. signature logos) are left out, unless $includeInline is true.
     *
     * @return Collection<int, EmailAttachment>
     */
    public function attachments(bool $includeInline = false): Collection
    {
        if (! $this->hasAttachments && ! $includeInline) {
            return collect();
        }

        if (! $this->loadedAttachments instanceof Collection) {
            $account = $this->account();

            $this->loadedAttachments = collect($account->mailConnector()->attachments($account, $this->providerMessageId));
        }

        return $includeInline
            ? $this->loadedAttachments
            : $this->loadedAttachments->reject(fn (EmailAttachment $attachment): bool => $attachment->isEmbeddedImage())->values();
    }

    /**
     * Whether the subject contains any of the given needles (case-insensitive).
     */
    public function subjectContains(string ...$needles): bool
    {
        return array_any($needles, fn (string $needle): bool => $needle !== '' && mb_stripos($this->subject, $needle) !== false);
    }

    /**
     * Whether the sender address or domain matches any of the given values (case-insensitive).
     * A value starting with "@" matches the whole domain.
     */
    public function isFrom(string ...$senders): bool
    {
        $fromEmail = mb_strtolower((string) $this->fromEmail);

        return array_any($senders, function (string $sender) use ($fromEmail): bool {
            $sender = mb_strtolower(mb_trim($sender));

            return str_starts_with($sender, '@') ? str_ends_with($fromEmail, $sender) : $fromEmail === $sender;
        });
    }

    /**
     * Body as plain text.
     */
    public function bodyText(): string
    {
        if ($this->bodyContentType !== 'html') {
            return $this->body;
        }

        return mb_trim(html_entity_decode(strip_tags($this->body), ENT_QUOTES | ENT_HTML5));
    }
}
