<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mupy\MailListeners\Database\Factories\MailMessageFactory;
use Mupy\MailListeners\Enums\MessageStatus;

/**
 * Email already read from a mail account. Only metadata is kept (no body, no attachments).
 *
 * @property int $id
 * @property int $mail_account_id
 * @property string $dedup_key
 * @property string $provider_message_id
 * @property string|null $internet_message_id
 * @property string|null $subject
 * @property string|null $from_email
 * @property \Illuminate\Support\Carbon $received_at
 * @property MessageStatus $status
 * @property string|null $error
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read MailAccount $account
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MailListenerRun> $listenerRuns
 *
 * @method static MailMessageFactory factory($count = null, $state = [])
 */
class MailMessage extends Model
{
    /** @use HasFactory<MailMessageFactory> */
    use HasFactory, Prunable;

    protected $table = 'mail_listener_messages';

    protected $fillable = [
        'mail_account_id',
        'dedup_key',
        'provider_message_id',
        'internet_message_id',
        'subject',
        'from_email',
        'received_at',
        'status',
        'error',
    ];

    public function account(): BelongsTo
    {
        return $this->belongsTo(MailAccount::class, 'mail_account_id');
    }

    public function listenerRuns(): HasMany
    {
        return $this->hasMany(MailListenerRun::class, 'mail_message_id');
    }

    /**
     * Read emails older than the retention (`mail-listeners.retention_days`) are pruned by `model:prune`.
     *
     * @return Builder<MailMessage>
     */
    public function prunable(): Builder
    {
        return static::query()->where('received_at', '<', now()->subDays((int) config('mail-listeners.retention_days', 180)));
    }

    protected static function newFactory(): Factory
    {
        return MailMessageFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'status' => MessageStatus::class,
        ];
    }
}
