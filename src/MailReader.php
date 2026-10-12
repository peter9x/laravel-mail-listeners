<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Carbon\CarbonImmutable;
use Mupy\MailListeners\Contracts\ReadsNewMessages;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Enums\MessageStatus;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Models\MailMessage;
use Mupy\MailListeners\Support\MailListenersLog;
use Mupy\MailListeners\Tenancy\Tenancy;
use Mupy\MailListeners\Tenancy\TenantColumn;
use Throwable;

/**
 * Reads the new emails of a mail account and fires the account context events for each one.
 * The mailbox is never changed: already read emails are tracked in `mail_listener_messages`.
 * With multi-tenancy, everything runs in the context of the tenant of the account (events and listeners included).
 */
final readonly class MailReader
{
    /**
     * While a poll reads, its cursor is saved at most this often (a single write on the usual short polls).
     */
    private const int SAVE_PROGRESS_EVERY_SECONDS = 30;

    public function __construct(private Tenancy $tenancy) {}

    /**
     * Read the emails received since the last poll. Returns the number of new emails.
     *
     * @throws Throwable when the account cannot be read (recorded on the account)
     */
    public function poll(MailAccount $account): int
    {
        return $this->tenancy->run($account->tenantKey(), fn (): int => $this->pollInTenant($account));
    }

    /**
     * Read the emails received within [$from, $to[ (e.g. to recover a past period), firing the events of the new ones.
     * Emails already read are skipped, and the polling cursor of the account is left untouched.
     * $onEmail is called after each email is recorded (and its events fired), with the counters so far.
     *
     * @param  (callable(InboundEmail $email, bool $isNew, array{found: int, new: int} $result): void)|null  $onEmail
     * @return array{found: int, new: int}
     */
    public function readBetween(MailAccount $account, CarbonImmutable $from, CarbonImmutable $to, ?callable $onEmail = null): array
    {
        return $this->tenancy->run($account->tenantKey(), function () use ($account, $from, $to, $onEmail): array {
            $result = ['found' => 0, 'new' => 0];

            $account->mailConnector()->eachMessageBetween(
                $account,
                $from,
                $to,
                function (InboundEmail $email) use ($account, $onEmail, &$result): void {
                    $result['found']++;

                    $isNew = $this->record($account, $email);

                    if ($isNew) {
                        $result['new']++;
                    }

                    if ($onEmail !== null) {
                        $onEmail($email, $isNew, $result);
                    }
                },
            );

            return $result;
        });
    }

    /**
     * Fetch an already read email again and fire the account events, so the listeners that failed run again
     * (listeners that already processed it skip it).
     */
    public function redispatch(MailMessage $message): void
    {
        $this->tenancy->run($message->tenantKey(), function () use ($message): void {
            $account = $message->account;

            $email = $account->mailConnector()
                ->message($account, $message->provider_message_id)
                ->withMessage($message);

            $this->dispatchEvents($account, $message, $email);
        });
    }

    /**
     * Record the email (when not read yet) and fire the account events for it, even when it was already read
     * (e.g. to test the listeners with a real email). Listeners that already processed it skip it.
     */
    public function dispatchEmail(MailAccount $account, InboundEmail $email): MailMessage
    {
        return $this->tenancy->run($account->tenantKey(), function () use ($account, $email): MailMessage {
            $message = $this->findOrRecord($account, $email);

            $this->dispatchEvents($account, $message, $email->withMessage($message));

            return $message;
        });
    }

    /**
     * The polling cursor advances only past the emails already recorded (and their events fired), and is saved while
     * reading, so a poll killed midway (e.g. a job timeout on a long backlog) resumes where it was left.
     *
     * @throws Throwable
     */
    private function pollInTenant(MailAccount $account): int
    {
        $connector = $account->mailConnector();
        $newMessages = 0;
        $lastReceivedAt = $account->last_received_at !== null ? CarbonImmutable::instance($account->last_received_at) : null;
        $cursor = $account->poll_cursor;
        $savedAt = CarbonImmutable::now();

        $onEmail = function (InboundEmail $email, ?string $emailCursor = null) use ($account, &$newMessages, &$lastReceivedAt, &$cursor, &$savedAt): void {
            if ($this->record($account, $email)) {
                $newMessages++;
            }

            // A date in the future (wrong clock of the sender) must not move the cursor past the emails to come.
            $receivedAt = $email->receivedAt->min(CarbonImmutable::now());

            if (! $lastReceivedAt instanceof CarbonImmutable || $receivedAt->greaterThan($lastReceivedAt)) {
                $lastReceivedAt = $receivedAt;
            }

            $cursor = $emailCursor ?? $cursor;

            if ($savedAt->diffInSeconds(CarbonImmutable::now()) >= self::SAVE_PROGRESS_EVERY_SECONDS) {
                $account->update(['last_received_at' => $lastReceivedAt, 'poll_cursor' => $cursor]);
                $savedAt = CarbonImmutable::now();
            }
        };

        try {
            if ($connector instanceof ReadsNewMessages) {
                $cursor = $connector->eachNewMessage($account, $account->poll_cursor, $account->pollSince(), $onEmail);
            } else {
                $connector->eachMessageBetween($account, $account->pollSince(), CarbonImmutable::now(), $onEmail);
            }
        } catch (Throwable $exception) {
            $account->update([
                'last_polled_at' => now(),
                'last_received_at' => $lastReceivedAt,
                'poll_cursor' => $cursor,
                'last_error' => mb_substr($exception->getMessage(), 0, 5000),
                'last_error_at' => now(),
            ]);

            MailListenersLog::channel()->error('Mail listeners: failed to read the account.', [
                ...Tenancy::logContext($account->tenantKey()),
                'account' => $account->id,
                'email' => $account->email,
                'error' => $exception->getMessage(),
            ]);

            throw $exception;
        }

        $account->update([
            'last_polled_at' => now(),
            'last_received_at' => $lastReceivedAt,
            'poll_cursor' => $cursor,
            'last_error' => null,
            'last_error_at' => null,
        ]);

        if ($newMessages > 0) {
            MailListenersLog::channel()->info('Mail listeners: new emails read.', [
                ...Tenancy::logContext($account->tenantKey()),
                'account' => $account->id,
                'email' => $account->email,
                'count' => $newMessages,
            ]);
        }

        return $newMessages;
    }

    /**
     * Record the email and fire its events. Returns false when it was already read.
     * An email still pending (its process died before firing the events) has them fired now; should another reader be
     * firing them at the same time, the listeners skip the email they already processed.
     */
    private function record(MailAccount $account, InboundEmail $email): bool
    {
        $message = $this->findOrRecord($account, $email);

        if (! $message->wasRecentlyCreated && $message->status !== MessageStatus::PENDING) {
            return false;
        }

        $this->dispatchEvents($account, $message, $email->withMessage($message));

        return true;
    }

    private function findOrRecord(MailAccount $account, InboundEmail $email): MailMessage
    {
        return MailMessage::query()->allTenants()->createOrFirst(
            [
                'mail_account_id' => $account->id,
                'dedup_key' => $email->dedupKey(),
            ],
            [
                ...TenantColumn::attributes($account->tenantKey()),
                'provider_message_id' => $email->providerMessageId,
                'internet_message_id' => $email->internetMessageId !== null ? mb_substr($email->internetMessageId, 0, 512) : null,
                'subject' => mb_substr($email->subjectDecoded, 0, 1000),
                'from_email' => $email->fromEmail !== null ? mb_substr($email->fromEmail, 0, 255) : null,
                'received_at' => $email->receivedAt,
                'status' => MessageStatus::PENDING,
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
                    ...Tenancy::logContext($account->tenantKey()),
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
