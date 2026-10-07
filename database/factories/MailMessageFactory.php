<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Mupy\MailListeners\Enums\MessageStatus;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailMessage;

/**
 * @extends Factory<MailMessage>
 */
class MailMessageFactory extends Factory
{
    protected $model = MailMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $internetMessageId = '<'.$this->faker->uuid().'@example.com>';

        return [
            'mail_account_id' => MailAccount::factory(),
            'dedup_key' => sha1($internetMessageId),
            'provider_message_id' => $this->faker->sha256(),
            'internet_message_id' => $internetMessageId,
            'subject' => $this->faker->sentence(),
            'from_email' => $this->faker->safeEmail(),
            'received_at' => now()->subHour(),
            'status' => MessageStatus::DISPATCHED,
        ];
    }
}
