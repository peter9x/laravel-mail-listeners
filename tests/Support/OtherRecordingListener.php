<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tests\Support;

/**
 * Second listener, on its own queue, to check listeners are independent.
 */
class OtherRecordingListener extends RecordingListener
{
    public ?string $queue = 'long';
}
