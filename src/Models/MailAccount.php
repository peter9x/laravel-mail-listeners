<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models;

use Carbon\CarbonImmutable;
use Cron\CronExpression;
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
 * @property list<string> $events Keys of events registered in the `mail-listeners.events` config, or event classes
 * @property string $poll_cron Cron expression the account is polled on (in `app.schedule_timezone`, or `app.timezone`)
 * @property \Illuminate\Support\Carbon|null $read_from
 * @property bool $active
 * @property bool $managed Defined in code ({@see \Mupy\MailListeners\Facades\MailListeners::mailbox()}) and kept in sync by the package
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
        'poll_cron',
        'read_from',
        'active',
        'managed',
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

    /**
     * Whether the account is defined in code: its settings are overwritten on every sync, so UIs should not edit it.
     */
    public function isManaged(): bool
    {
        return $this->managed;
    }

    /**
     * Due once the last run time of `poll_cron` (e.g. today 06:00 for "0 6 * * *") is past the last poll, or past the
     * creation of the account when never polled, so a run missed by the scheduler is caught up on the next poll.
     * An invalid cron expression is never due.
     */
    public function isDueForPolling(): bool
    {
        if (! $this->active || ! CronExpression::isValidExpression($this->poll_cron)) {
            return false;
        }

        $timezone = (string) (config('app.schedule_timezone') ?? config('app.timezone'));
        $lastRun = (new CronExpression($this->poll_cron))->getPreviousRunDate(now($timezone), allowCurrentDate: true, timeZone: $timezone);

        return $lastRun > ($this->last_polled_at ?? $this->created_at ?? now());
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
     * Configured events that resolve to an event class: registered keys or event classes (unknown values are ignored).
     *
     * @return list<string>
     */
    public function eventKeys(): array
    {
        $registry = app(EventRegistry::class);

        return array_values(array_filter($this->events ?? [], fn (string $event): bool => $registry->resolve($event) !== null));
    }

    /**
     * @return list<class-string<EmailReceived>>
     */
    public function eventClasses(): array
    {
        $registry = app(EventRegistry::class);

        return array_values(array_unique(array_map(fn (string $event): string => (string) $registry->resolve($event), $this->eventKeys())));
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
            'read_from' => 'datetime',
            'active' => 'boolean',
            'managed' => 'boolean',
            'last_polled_at' => 'datetime',
            'last_received_at' => 'datetime',
            'last_error_at' => 'datetime',
        ];
    }
}
