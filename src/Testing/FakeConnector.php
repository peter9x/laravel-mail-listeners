<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Testing;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Mupy\MailListeners\ConnectorManager;
use Mupy\MailListeners\Contracts\MailConnector;
use Mupy\MailListeners\Data\EmailAttachment;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Models\MailAccount;
use RuntimeException;
use Throwable;

/**
 * In-memory connector for tests: `$fake = FakeConnector::swap();` replaces a configured connector in the container.
 */
class FakeConnector implements MailConnector
{
    /**
     * @var list<InboundEmail>
     */
    public array $messages = [];

    /**
     * @var array<string, list<EmailAttachment>>
     */
    public array $attachmentsByMessage = [];

    /**
     * Periods read, as [from, to].
     *
     * @var list<array{0: CarbonImmutable, 1: CarbonImmutable}>
     */
    public array $rangesRequested = [];

    public int $attachmentCalls = 0;

    /**
     * Error thrown when reading (after the messages) and when testing the connection.
     */
    public ?Throwable $failWith = null;

    /**
     * Bind a new fake in place of the connector registered under the given key.
     */
    public static function swap(string $connector = 'microsoft_graph'): self
    {
        $fake = new self();

        app()->instance(app(ConnectorManager::class)->connectorClass($connector), $fake);

        return $fake;
    }

    /**
     * Build an email of the given account, for the fake messages or to fire events in tests.
     *
     * @param  array<string, mixed>  $overrides  constructor arguments of InboundEmail
     */
    public static function makeEmail(MailAccount $account, array $overrides = []): InboundEmail
    {
        $id = (string) Str::uuid();

        return new InboundEmail(...array_merge([
            'accountId' => $account->id,
            'providerMessageId' => 'provider-'.$id,
            'internetMessageId' => '<'.$id.'@example.com>',
            'subject' => 'Invoice '.mb_substr($id, 0, 8),
            'fromEmail' => 'supplier@example.com',
            'fromName' => 'Supplier',
            'to' => [$account->email],
            'receivedAt' => CarbonImmutable::now()->subMinutes(10),
            'bodyContentType' => 'html',
            'body' => '<p>Hello</p>',
            'hasAttachments' => false,
        ], $overrides));
    }

    public static function label(): string
    {
        return 'Fake';
    }

    public static function settingsRules(bool $creating): array
    {
        return [];
    }

    public static function secretSettings(): array
    {
        return [];
    }

    public static function defaultSettings(): array
    {
        return [];
    }

    public function withMessages(InboundEmail ...$messages): static
    {
        array_push($this->messages, ...$messages);

        return $this;
    }

    /**
     * @param  list<EmailAttachment>  $attachments
     */
    public function withAttachments(string $providerMessageId, array $attachments): static
    {
        $this->attachmentsByMessage[$providerMessageId] = $attachments;

        return $this;
    }

    public function eachMessageBetween(MailAccount $account, CarbonImmutable $from, CarbonImmutable $to, callable $callback): void
    {
        $this->rangesRequested[] = [$from, $to];

        foreach ($this->messages as $message) {
            if ($message->accountId === $account->id && $message->receivedAt->greaterThanOrEqualTo($from) && $message->receivedAt->lessThan($to)) {
                $callback($message);
            }
        }

        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }
    }

    public function message(MailAccount $account, string $providerMessageId): InboundEmail
    {
        foreach ($this->messages as $message) {
            if ($message->providerMessageId === $providerMessageId) {
                return $message;
            }
        }

        throw new RuntimeException('Message not found.');
    }

    public function attachments(MailAccount $account, string $providerMessageId): array
    {
        $this->attachmentCalls++;

        return $this->attachmentsByMessage[$providerMessageId] ?? [];
    }

    public function testConnection(MailAccount $account): void
    {
        if ($this->failWith instanceof Throwable) {
            throw $this->failWith;
        }
    }
}
