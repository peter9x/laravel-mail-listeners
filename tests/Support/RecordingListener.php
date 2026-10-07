<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tests\Support;

use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\MailListener;
use RuntimeException;

/**
 * Listener recording what it processed. Configure it through its static properties.
 */
class RecordingListener extends MailListener
{
    /**
     * @var list<InboundEmail>
     */
    public static array $processed = [];

    public static ?string $subjectKeyword = null;

    public static bool $fail = false;

    public static function label(): string
    {
        return class_basename(static::class);
    }

    public static function reset(): void
    {
        self::$processed = [];
        self::$subjectKeyword = null;
        self::$fail = false;
    }

    public function interestedIn(InboundEmail $email): bool
    {
        return self::$subjectKeyword === null || $email->subjectContains(self::$subjectKeyword);
    }

    protected function process(InboundEmail $email): void
    {
        if (self::$fail) {
            throw new RuntimeException('Listener failed');
        }

        self::$processed[] = $email;
    }
}
