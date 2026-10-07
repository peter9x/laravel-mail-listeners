<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mupy\MailListeners\Models\MailAccount;

/**
 * @extends Factory<MailAccount>
 */
class MailAccountFactory extends Factory
{
    protected $model = MailAccount::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'name' => $this->faker->words(2, true),
            'email' => $this->faker->unique()->safeEmail(),
            'connector' => 'microsoft_graph',
            'connector_settings' => ['tenant' => 'default', 'folder' => 'inbox'],
            'events' => [],
            'poll_interval_minutes' => 5,
            'read_from' => now()->subDay(),
            'active' => true,
            'managed' => false,
        ];
    }

    public function imap(): static
    {
        return $this->state(fn (): array => [
            'connector' => 'imap',
            'connector_settings' => [
                'host' => 'imap.example.com',
                'port' => 993,
                'encryption' => 'ssl',
                'validate_cert' => true,
                'username' => 'mailbox@example.com',
                'password' => 'super-secret',
            ],
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (): array => ['active' => false]);
    }

    public function managed(): static
    {
        return $this->state(fn (): array => ['managed' => true]);
    }

    /**
     * @param  list<string>  $events  keys of events registered in the `mail-listeners.events` config, or event classes
     */
    public function withEvents(array $events): static
    {
        return $this->state(fn (): array => ['events' => array_values($events)]);
    }
}
