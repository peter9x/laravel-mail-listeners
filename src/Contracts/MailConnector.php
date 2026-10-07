<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Contracts;

use Carbon\CarbonImmutable;
use Mupy\MailListeners\Data\EmailAttachment;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Models\MailAccount;

/**
 * Reads a mail account. Connectors must never change the mailbox (read-only access).
 * Register a connector in the `mail-listeners.connectors` config.
 */
interface MailConnector
{
    /**
     * Human readable name of the connector.
     */
    public static function label(): string;

    /**
     * Validation rules of the account `connector_settings` for this connector, keyed by setting name.
     *
     * @return array<string, mixed>
     */
    public static function settingsRules(bool $creating): array;

    /**
     * Settings holding secrets: never to be sent to a UI, and kept when left empty on update.
     *
     * @return list<string>
     */
    public static function secretSettings(): array;

    /**
     * Default settings of a new account.
     *
     * @return array<string, mixed>
     */
    public static function defaultSettings(): array;

    /**
     * Iterate over every message received within [$from, $to[, oldest first.
     *
     * @param  callable(InboundEmail): void  $callback
     */
    public function eachMessageBetween(MailAccount $account, CarbonImmutable $from, CarbonImmutable $to, callable $callback): void;

    /**
     * Fetch a single message again (e.g. to reprocess it).
     */
    public function message(MailAccount $account, string $providerMessageId): InboundEmail;

    /**
     * Download the file attachments of a message, in memory.
     *
     * @return list<EmailAttachment>
     */
    public function attachments(MailAccount $account, string $providerMessageId): array;

    /**
     * Throws when the account cannot be read.
     */
    public function testConnection(MailAccount $account): void;
}
