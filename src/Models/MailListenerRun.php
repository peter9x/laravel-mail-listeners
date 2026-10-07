<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Mupy\MailListeners\Enums\ListenerRunStatus;

/**
 * Processing of one email by one interested listener.
 *
 * @property int $id
 * @property int $mail_message_id
 * @property class-string $listener
 * @property ListenerRunStatus $status
 * @property int $attempts
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $started_at
 * @property \Illuminate\Support\Carbon|null $finished_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MailMessage $message
 */
class MailListenerRun extends Model
{
    protected $table = 'mail_listener_runs';

    protected $fillable = [
        'mail_message_id',
        'listener',
        'status',
        'attempts',
        'error',
        'started_at',
        'finished_at',
    ];

    public function message(): BelongsTo
    {
        return $this->belongsTo(MailMessage::class, 'mail_message_id');
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => ListenerRunStatus::class,
            'attempts' => 'integer',
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
        ];
    }
}
