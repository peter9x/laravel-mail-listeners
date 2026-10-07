<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Mupy\MailListeners\Data\InboundEmail;

/**
 * Base of the context events fired for every new email of a mail account (one per event configured on the account).
 * Define your own events extending it and register them in the `mail-listeners.events` config.
 * Listeners should extend {@see \Mupy\MailListeners\MailListener}.
 */
abstract class EmailReceived
{
    use Dispatchable;

    public function __construct(public readonly InboundEmail $email) {}
}
