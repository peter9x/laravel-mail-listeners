<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Contracts;

use Carbon\CarbonImmutable;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Models\MailAccount;

/**
 * A {@see MailConnector} whose polls read the new messages from its own cursor (e.g. the IMAP UID) instead of a period,
 * so they never depend on the dates of the messages. The cursor is opaque to the package and kept on the account
 * (`poll_cursor`). Reading a period (`mail-listeners:read`) still uses {@see MailConnector::eachMessageBetween()}.
 */
interface ReadsNewMessages
{
    /**
     * Iterate over the messages received after the cursor, oldest first, calling $callback with each one and the
     * cursor to resume after it. Without a cursor, or with one no longer valid, read the messages received since
     * $since. Returns the cursor to resume from on the next poll.
     *
     * @param  callable(InboundEmail $email, string $cursor): void  $callback
     */
    public function eachNewMessage(MailAccount $account, ?string $cursor, CarbonImmutable $since, callable $callback): string;
}
