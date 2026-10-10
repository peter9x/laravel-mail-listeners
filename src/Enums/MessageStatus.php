<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Enums;

enum MessageStatus: string
{
    /**
     * Recorded, its events not fired yet: fired again when the email is read again (e.g. the process died meanwhile).
     */
    case PENDING = 'pending';
    case DISPATCHED = 'dispatched';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PENDING => __('Pending'),
            self::DISPATCHED => __('Read'),
            self::FAILED => __('Failed'),
        };
    }
}
