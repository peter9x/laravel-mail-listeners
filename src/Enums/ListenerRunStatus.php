<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Enums;

enum ListenerRunStatus: string
{
    case PROCESSING = 'processing';
    case PROCESSED = 'processed';
    case FAILED = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::PROCESSING => __('Processing'),
            self::PROCESSED => __('Processed'),
            self::FAILED => __('Failed'),
        };
    }
}
