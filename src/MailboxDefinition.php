<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Carbon\CarbonImmutable;
use DateTimeInterface;
use InvalidArgumentException;

/**
 * Mail account defined in code, e.g. in a service provider:
 *
 * MailListeners::mailbox('invoices@example.com')
 *     ->connector('microsoft_graph', ['tenant' => 'default'])
 *     ->events(SupplierEmailReceived::class, 'hr');
 *
 * The package keeps a `mail_listener_accounts` row in sync with it (see {@see MailboxRegistry}).
 */
final class MailboxDefinition
{
    public readonly string $email;

    private ?string $name = null;

    private ?string $connector = null;

    /**
     * @var array<string, mixed>
     */
    private array $settings = [];

    /**
     * @var list<string>
     */
    private array $events = [];

    private string $cron = '*/5 * * * *';

    private ?CarbonImmutable $readFrom = null;

    private bool $active = true;

    public function __construct(string $email)
    {
        $this->email = mb_strtolower(mb_trim($email));
    }

    /**
     * Human readable name of the account (default: its email).
     */
    public function name(string $name): self
    {
        $this->name = $name;

        return $this;
    }

    /**
     * Key of a connector registered in the `mail-listeners.connectors` config, and its settings.
     *
     * @param  array<string, mixed>  $settings
     */
    public function connector(string $connector, array $settings = []): self
    {
        $this->connector = $connector;
        $this->settings = $settings;

        return $this;
    }

    /**
     * Events fired for every new email: keys registered in the `mail-listeners.events` config, or event classes.
     */
    public function events(string ...$events): self
    {
        $this->events = array_values(array_unique($events));

        return $this;
    }

    /**
     * Poll every N minutes (default: every 5 minutes), aligned to the clock: stored as the cron expression
     * "*\/N * * * *", or "0 *\/H * * *" for whole hours. N must divide an hour (1, 2, 3, 4, 5, 6, 10, 12, 15, 20, 30),
     * or be whole hours dividing a day (60, 120, 180, 240, 360, 480, 720); use cron() for any other period.
     *
     * @throws InvalidArgumentException
     */
    public function pollEvery(int $minutes): self
    {
        $this->cron = match (true) {
            $minutes === 1 => '* * * * *',
            $minutes > 1 && $minutes < 60 && 60 % $minutes === 0 => "*/{$minutes} * * * *",
            $minutes === 60 => '0 * * * *',
            $minutes > 60 && $minutes < 1440 && $minutes % 60 === 0 && 24 % intdiv($minutes, 60) === 0 => '0 */'.intdiv($minutes, 60).' * * *',
            default => throw new InvalidArgumentException("Cannot poll [{$this->email}] every {$minutes} minutes: the minutes must divide an hour, or be whole hours dividing a day. Use cron() instead."),
        };

        return $this;
    }

    /**
     * Poll on a cron expression, e.g. "0 6 * * *" (every day at 06:00), in `app.schedule_timezone`
     * (or `app.timezone`). A run missed while the scheduler was down is caught up on the next poll.
     */
    public function cron(string $expression): self
    {
        $this->cron = mb_trim($expression);

        return $this;
    }

    /**
     * Date the first poll reads from (default: when the account is first synced).
     */
    public function readFrom(DateTimeInterface|string|null $date): self
    {
        $this->readFrom = $date !== null ? CarbonImmutable::parse($date) : null;

        return $this;
    }

    public function active(bool $active = true): self
    {
        $this->active = $active;

        return $this;
    }

    public function connectorKey(): ?string
    {
        return $this->connector;
    }

    /**
     * @return array<string, mixed>
     */
    public function settings(): array
    {
        return $this->settings;
    }

    /**
     * @return list<string>
     */
    public function eventList(): array
    {
        return $this->events;
    }

    public function cronExpression(): string
    {
        return $this->cron;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    /**
     * Attributes of the `MailAccount` kept in sync with this definition.
     *
     * @return array<string, mixed>
     */
    public function toAttributes(): array
    {
        return [
            'name' => $this->name ?? $this->email,
            'email' => $this->email,
            'connector' => $this->connector,
            'connector_settings' => $this->settings,
            'events' => $this->events,
            'poll_cron' => $this->cron,
            'read_from' => $this->readFrom,
            'active' => $this->active,
            'managed' => true,
        ];
    }
}
