<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Mupy\MailListeners\ConnectorManager;
use Mupy\MailListeners\Contracts\MailConnector;
use Mupy\MailListeners\Database\Factories\MailAccountFactory;
use Mupy\MailListeners\EventRegistry;
use Mupy\MailListeners\Events\EmailReceived;

/**
 * Mailbox read periodically, firing its context events for every new email.
 *
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string $connector Key of a connector registered in the `mail-listeners.connectors` config
 * @property array<string, mixed>|null $connector_settings Connector specific settings (encrypted: may hold secrets)
 * @property list<string> $events Keys of events registered in the `mail-listeners.events` config
 * @property int $poll_interval_minutes
 * @property \Illuminate\Support\Carbon|null $read_from
 * @property bool $active
 * @property \Illuminate\Support\Carbon|null $last_polled_at
 * @property \Illuminate\Support\Carbon|null $last_received_at
 * @property string|null $last_error
 * @property \Illuminate\Support\Carbon|null $last_error_at
 * @property \Illuminate\Support\Carbon|null $created_at
 * @property \Illuminate\Support\Carbon|null $updated_at
 * @property-read \Illuminate\Database\Eloquent\Collection<int, MailMessage> $messages
 *
 * @method static MailAccountFactory factory($count = null, $state = [])
 */
class MailAccount extends Model
{
    /** @use HasFactory<MailAccountFactory> */
    use HasFactory;

    protected $table = 'mail_listener_accounts';

    protected $fillable = [
        'name',
        'email',
        'connector',
        'connector_settings',
        'events',
        'poll_interval_minutes',
        'read_from',
        'active',
        'last_polled_at',
        'last_received_at',
        'last_error',
        'last_error_at',
    ];

    public function messages(): HasMany
    {
        return $this->hasMany(MailMessage::class, 'mail_account_id');
    }

    /**
     * @param  Builder<MailAccount>  $query
     */
    public function scopeActive(Builder $query): void
    {
        $query->where('active', true);
    }

    /**
     * The connector reading this account.
     */
    public function mailConnector(): MailConnector
    {
        return app(ConnectorManager::class)->connector($this->connector);
    }

    /**
     * @return class-string<MailConnector>
     */
    public function connectorClass(): string
    {
        return app(ConnectorManager::class)->connectorClass($this->connector);
    }

    public function isDueForPolling(): bool
    {
        if (! $this->active) {
            return false;
        }

        return $this->last_polled_at === null
            || $this->last_polled_at->copy()->addMinutes($this->poll_interval_minutes)->lessThanOrEqualTo(now());
    }

    /**
     * Start of the window read on the next poll.
     */
    public function pollSince(): CarbonImmutable
    {
        if ($this->last_received_at !== null) {
            return CarbonImmutable::instance($this->last_received_at)->subMinutes((int) config('mail-listeners.poll_overlap_minutes', 5));
        }

        return CarbonImmutable::instance($this->read_from ?? $this->created_at ?? now());
    }

    /**
     * Configured events that are registered (unknown keys are ignored).
     *
     * @return list<string>
     */
    public function eventKeys(): array
    {
        $registry = app(EventRegistry::class);

        return array_values(array_filter($this->events ?? [], fn (string $event): bool => $registry->has($event)));
    }

    /**
     * @return list<class-string<EmailReceived>>
     */
    public function eventClasses(): array
    {
        $registry = app(EventRegistry::class);

        return array_map(fn (string $event): string => (string) $registry->eventClass($event), $this->eventKeys());
    }

    public function connectorSetting(string $key, ?string $default = null): ?string
    {
        $value = $this->connector_settings[$key] ?? null;

        return is_string($value) && $value !== '' ? $value : $default;
    }

    protected static function newFactory(): Factory
    {
        return MailAccountFactory::new();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'connector_settings' => 'encrypted:array',
            'events' => 'array',
            'poll_interval_minutes' => 'integer',
            'read_from' => 'datetime',
            'active' => 'boolean',
            'last_polled_at' => 'datetime',
            'last_received_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
