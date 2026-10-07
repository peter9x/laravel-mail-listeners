<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Carbon\CarbonImmutable;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Enums\MessageStatus;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Support\MailListenersLog;
use Throwable;

/**
 * Reads the new emails of a mail account and fires the account context events for each one.
 * The mailbox is never changed: already read emails are tracked in `mail_listener_messages`.
 */
final class MailReader
{
    /**
     * Read the emails received since the last poll. Returns the number of new emails.
     *
     * @throws Throwable when the account cannot be read (recorded on the account)
     */
    public function poll(MailAccount $account): int
    {
        $newMessages = 0;
        $lastReceivedAt = $account->last_received_at !== null ? CarbonImmutable::instance($account->last_received_at) : null;

        try {
            $account->mailConnector()->eachMessageBetween(
                $account,
                $account->pollSince(),
                CarbonImmutable::now(),
                function (InboundEmail $email) use ($account, &$newMessages, &$lastReceivedAt): void {
                    if (! $lastReceivedAt instanceof CarbonImmutable || $email->receivedAt->greaterThan($lastReceivedAt)) {
                        $lastReceivedAt = $email->receivedAt;
                    }

                    if ($this->record($account, $email)) {
                        $newMessages++;
                    }
                },
            );
        } catch (Throwable $exception) {
            $account->update([
                'last_polled_at' => now(),
                'last_received_at' => $lastReceivedAt,
                'last_error' => mb_substr($exception->getMessage(), 0, 5000),
                'last_error_at' => now(),
            ]);

            MailListenersLog::channel()->error('Mail listeners: failed to read the account.', [
                'account' => $account->id,
                'email' => $account->email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $account->update([
            'last_polled_at' => now(),
            'last_received_at' => $lastReceivedAt,
            'last_error' => null,
            'last_error_at' => null,
        ]);

        if ($newMessages > 0) {
            MailListenersLog::channel()->info('Mail listeners: new emails read.', [
                'account' => $account->id,
                'email' => $account->email,
                'count' => $newMessages,
            ]);
        }

        return $newMessages;
    }

    /**
     * Read the emails received within [$from, $to[ (e.g. to recover a past period), firing the events of the new ones.
     * Emails already read are skipped, and the polling cursor of the account is left untouched.
     *
     * @return array{found: int, new: int}
     */
    public function readBetween(MailAccount $account, CarbonImmutable $from, CarbonImmutable $to): array
    {
        $result = ['found' => 0, 'new' => 0];

        $account->mailConnector()->eachMessageBetween(
            $account,
            $from,
            $to,
            function (InboundEmail $email) use ($account, &$result): void {
                $result['found']++;

                if ($this->record($account, $email)) {
                    $result['new']++;
                }
            },
        );

        return $result;
    }

    /**
     * Fetch an already read email again and fire the account events, so the listeners that failed run again
     * (listeners that already processed it skip it).
     */
    public function redispatch(MailMessage $message): void
    {
        $account = $message->account;

        $email = $account->mailConnector()
            ->message($account, $message->provider_message_id)
            ->withMessageId($message->id);

        $this->dispatchEvents($account, $message, $email);
    }

    /**
     * Record the email (when not read yet) and fire the account events for it, even when it was already read
     * (e.g. to test the listeners with a real email). Listeners that already processed it skip it.
     */
    public function dispatchEmail(MailAccount $account, InboundEmail $email): MailMessage
    {
        $message = $this->findOrRecord($account, $email);

        $this->dispatchEvents($account, $message, $email->withMessageId($message->id));

        return $message;
    }

    /**
     * Record the email and fire its events. Returns false when it was already read.
     */
    private function record(MailAccount $account, InboundEmail $email): bool
    {
        $message = $this->findOrRecord($account, $email);

        if (! $message->wasRecentlyCreated) {
            return false;
        }

        $this->dispatchEvents($account, $message, $email->withMessageId($message->id));

        return true;
    }

    private function findOrRecord(MailAccount $account, InboundEmail $email): MailMessage
    {
        return MailMessage::query()->createOrFirst(
            [
                'mail_account_id' => $account->id,
                'dedup_key' => $email->dedupKey(),
            ],
            [
                'provider_message_id' => $email->providerMessageId,
                'internet_message_id' => $email->internetMessageId !== null ? mb_substr($email->internetMessageId, 0, 512) : null,
                'subject' => mb_substr($email->subject, 0, 1000),
                'from_email' => $email->fromEmail !== null ? mb_substr($email->fromEmail, 0, 255) : null,
                'received_at' => $email->receivedAt,
                'status' => MessageStatus::DISPATCHED,
            ],
        );
    }

    /**
     * Fire every context event of the account. A failing event never prevents the others from being fired.
     */
    private function dispatchEvents(MailAccount $account, MailMessage $message, InboundEmail $email): void
    {
        $errors = [];

        foreach ($account->eventClasses() as $eventClass) {
            try {
                event(new $eventClass($email));
            } catch (Throwable $exception) {
                $errors[] = class_basename($eventClass).': '.$exception->getMessage();

                MailListenersLog::channel()->error('Mail listeners: failed to dispatch an event.', [
                    'account' => $account->id,
                    'message' => $message->id,
                    'event' => $eventClass,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        $message->update([
            'status' => $errors === [] ? MessageStatus::DISPATCHED : MessageStatus::FAILED,
            'error' => $errors === [] ? null : mb_substr(implode("\n", $errors), 0, 5000),
        ]);
    }
}
