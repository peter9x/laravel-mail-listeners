<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Carbon\CarbonImmutable;
use DateTimeInterface;

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

    private int $pollIntervalMinutes = 5;

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

    public function pollEvery(int $minutes): self
    {
        $this->pollIntervalMinutes = $minutes;

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
            'poll_interval_minutes' => $this->pollIntervalMinutes,
            'read_from' => $this->readFrom,
            'active' => $this->active,
            'managed' => true,
        ];
    }
}
