<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Support;

use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;

/**
 * Log channel of the package (`mail-listeners.logging.channel`, by default its own `mail-listeners` daily file).
 */
final class MailListenersLog
{
    public static function channel(): LoggerInterface
    {
        return Log::channel((string) config('mail-listeners.logging.channel', 'mail-listeners'));
    }
}
