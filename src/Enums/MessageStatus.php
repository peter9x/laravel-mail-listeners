<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Enums;

enum MessageStatus: string
{
    case DISPATCHED = 'dispatched';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::DISPATCHED => __('Read'),
            self::FAILED => __('Failed'),
        };
    }
}
