<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Cache;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\Enums\ListenerRunStatus;
use Mupy\MailListeners\Events\EmailReceived;
use Mupy\MailListeners\Models\MailListenerRun;
use Mupy\MailListeners\Support\MailListenersLog;
use Throwable;

/**
 * Base class of every mail listener. Register it in a service provider, e.g.
 * `Event::listen(SupplierEmailReceived::class, MyEmailListener::class)`.
 *
 * Each listener runs in its own queued job, so a slow or failing listener never delays the others.
 * A run is recorded only when the listener is interested in the email, and an email already processed
 * by a listener is never processed again by it (even when it listens to several events of the same account).
 */
abstract class MailListener implements ShouldQueue
{
    use InteractsWithQueue;

    /**
     * Queue the listener runs on (default: `mail-listeners.queues.listeners`). Override it for heavy listeners.
     */
    public ?string $queue = null;

    public int $tries = 3;

    /**
     * @var list<int>
     */
    public array $backoff = [30, 120];

    /**
     * Seconds a run stays locked (default: `mail-listeners.run_lock_seconds`).
     */
    protected ?int $lockSeconds = null;

    /**
     * Whether this email matters to the listener (subject, sender, body, attachment names...).
     */
    abstract public function interestedIn(InboundEmail $email): bool;

    /**
     * Process an email the listener is interested in. Throw to mark the run as failed (and retry it).
     */
    abstract protected function process(InboundEmail $email): void;

    /**
     * Human readable name of the listener.
     */
    abstract public static function label(): string;

    /**
     * Cache lock held while this listener processes the given email.
     */
    final public static function runLockKey(int $messageId): string
    {
        return 'mail-listeners-run:'.$messageId.':'.sha1(static::class);
    }

    final public function viaQueue(): string
    {
        return $this->queue ?? (string) config('mail-listeners.queues.listeners');
    }

    final public function handle(EmailReceived $event): void
    {
        $email = $event->email;

        if ($email->messageId === null || ! $this->interestedIn($email)) {
            return;
        }

        $lock = Cache::lock(static::runLockKey($email->messageId), $this->lockSeconds ?? (int) config('mail-listeners.run_lock_seconds', 3600));

        if (! $lock->get()) {
            $this->release(30);

            return;
        }

        try {
            $run = MailListenerRun::query()->firstOrCreate(
                ['mail_message_id' => $email->messageId, 'listener' => static::class],
                ['status' => ListenerRunStatus::PROCESSING],
            );

            if ($run->status === ListenerRunStatus::PROCESSED) {
                return;
            }

            $run->update([
                'status' => ListenerRunStatus::PROCESSING,
                'attempts' => $run->attempts + 1,
                'error' => null,
                'started_at' => now(),
                'finished_at' => null,
            ]);

            try {
                $this->process($email);
            } catch (Throwable $exception) {
                $this->markFailed($run, $exception);

                throw $exception;
            }

            $run->update([
                'status' => ListenerRunStatus::PROCESSED,
                'finished_at' => now(),
            ]);
        } finally {
            $lock->release();
        }
    }

    /**
     * Called by the queue once all attempts are exhausted (also on timeouts, where handle() could not record it).
     */
    final public function failed(EmailReceived $event, Throwable $exception): void
    {
        if ($event->email->messageId === null) {
            return;
        }

        $run = MailListenerRun::query()
            ->where('mail_message_id', $event->email->messageId)
            ->where('listener', static::class)
            ->first();

        if ($run instanceof MailListenerRun && $run->status !== ListenerRunStatus::PROCESSED) {
            $this->markFailed($run, $exception);
        }
    }

    /**
     * @return list<string>
     */
    final public function tags(): array
    {
        return ['MailListeners', class_basename(static::class)];
    }

    private function markFailed(MailListenerRun $run, Throwable $exception): void
    {
        $run->update([
            'status' => ListenerRunStatus::FAILED,
            'error' => mb_substr($exception::class.': '.$exception->getMessage(), 0, 5000),
            'finished_at' => now(),
        ]);

        MailListenersLog::channel()->error('Mail listeners: a listener failed to process an email.', [
            'listener' => static::class,
            'message' => $run->mail_message_id,
            'attempts' => $run->attempts,
            'error' => $exception->getMessage(),
        ]);
    }
}
