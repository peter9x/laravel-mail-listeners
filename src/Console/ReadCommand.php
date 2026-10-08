<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Mupy\MailListeners\Console\Concerns\ReportsMailboxSync;
use Mupy\MailListeners\Data\InboundEmail;
use Mupy\MailListeners\MailboxRegistry;
use Mupy\MailListeners\MailReader;
use Mupy\MailListeners\Models\MailAccount;
use Symfony\Component\Console\Helper\ProgressBar;
use Throwable;

/**
 * Reads a configured mail account within a period (e.g. to recover past emails), firing the account events
 * for the emails not read yet. The polling cursor of the account is left untouched.
 */
final class ReadCommand extends Command
{
    use ReportsMailboxSync;

    /**
     * Without an interactive terminal (cron, a log file, --no-ansi) a plain line is written every this many emails.
     */
    private const int PLAIN_PROGRESS_EVERY = 25;

    protected $signature = 'mail-listeners:read
        {email : Email of a mail account (in the database or defined in code)}
        {--from= : Start of the period (e.g. 01-01-2026 or "01-01-2026 08:00"). Default: 24h before the end}
        {--to= : End of the period (e.g. 31-01-2026, the whole day, or "31-01-2026 18:00"). Default: now}';

    protected $description = 'Read the emails of a configured mail account within a period and fire the events of the ones not read yet';

    public function handle(MailReader $reader, MailboxRegistry $mailboxes): int
    {
        $email = mb_strtolower(mb_trim((string) $this->argument('email')));

        $this->reportSyncErrors($mailboxes->syncIfChanged());

        $account = MailAccount::query()->where('email', $email)->first();

        if (! $account instanceof MailAccount && $mailboxes->has($email)) {
            $this->reportSyncErrors($mailboxes->sync());

            $account = MailAccount::query()->where('email', $email)->first();
        }

        if (! $account instanceof MailAccount) {
            $this->error("The mail account {$email} is not configured.");

            return self::FAILURE;
        }

        try {
            $to = $this->option('to') !== null ? $this->parseDate((string) $this->option('to'), endOfDay: true) : CarbonImmutable::now();
            $from = $this->option('from') !== null ? $this->parseDate((string) $this->option('from'), endOfDay: false) : $to->subDay();
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($from->greaterThanOrEqualTo($to)) {
            $this->error('The start of the period must be before its end.');

            return self::FAILURE;
        }

        if (! $account->active) {
            $this->warn("The mail account {$account->email} is inactive; reading it anyway.");
        }

        $this->info("Reading {$account->email} from {$from->format('d-m-Y H:i')} to {$to->format('d-m-Y H:i')}...");
        $this->line("Connecting to {$account->email} and searching messages...");

        $startedAt = microtime(true);
        $progress = ['found' => 0, 'new' => 0];
        $lastReceivedAt = null;
        $bar = $this->output->isDecorated() ? $this->progressBar($startedAt, $progress) : null;

        $onEmail = function (InboundEmail $email, bool $isNew, array $result) use ($bar, $startedAt, &$progress, &$lastReceivedAt): void {
            $progress = $result;
            $lastReceivedAt = $email->receivedAt;

            if ($bar instanceof ProgressBar) {
                $bar->setMessage($email->receivedAt->format('d-m-Y H:i').' '.Str::limit($email->subjectDecoded, 60));
                $bar->advance();
            }

            if ($isNew && $this->output->isVerbose()) {
                $bar?->clear();
                $this->line(sprintf('  + %s  %s  %s', $email->receivedAt->format('d-m-Y H:i'), $email->fromEmail ?? '-', $email->subjectDecoded));
                $bar?->display();
            }

            if (! $bar instanceof ProgressBar && $result['found'] % self::PLAIN_PROGRESS_EVERY === 0) {
                $this->line(sprintf('[%s] %d emails read (%d new)...', $this->elapsed($startedAt), $result['found'], $result['new']));
            }
        };

        try {
            $result = $reader->readBetween($account, $from, $to, $onEmail);
        } catch (Throwable $exception) {
            $this->finishProgress($bar, $startedAt, $progress);
            $this->error("The mail account could not be read: {$exception->getMessage()}");

            if ($progress['found'] > 0 && $lastReceivedAt instanceof CarbonImmutable) {
                $this->warn(sprintf(
                    'Read before the failure: %d emails found, %d new (events fired), %d already read.',
                    $progress['found'],
                    $progress['new'],
                    $progress['found'] - $progress['new'],
                ));
                $this->line("Last email processed: received at {$lastReceivedAt->format('d-m-Y H:i')}.");
                $this->line(sprintf(
                    'Resume with: php artisan mail-listeners:read %s --from="%s" --to="%s"',
                    $account->email,
                    $lastReceivedAt->format('d-m-Y H:i'),
                    ($to->second > 0 || $to->microsecond > 0 ? $to->startOfMinute()->addMinute() : $to)->format('d-m-Y H:i'),
                ));
            }

            return self::FAILURE;
        }

        $this->finishProgress($bar, $startedAt, $result);

        $this->info(sprintf(
            '%d emails found: %d new (events fired), %d already read.',
            $result['found'],
            $result['new'],
            $result['found'] - $result['new'],
        ));

        return self::SUCCESS;
    }

    /**
     * Counter without a maximum: the number of emails in the period is only known once they are all read.
     *
     * @param  array{found: int, new: int}  $progress  updated by reference while reading
     */
    private function progressBar(float $startedAt, array &$progress): ProgressBar
    {
        $bar = $this->output->createProgressBar();

        $bar->setPlaceholderFormatter('new', function () use (&$progress): string {
            return (string) $progress['new'];
        });
        $bar->setPlaceholderFormatter('read', function () use (&$progress): string {
            return (string) ($progress['found'] - $progress['new']);
        });
        $bar->setPlaceholderFormatter('clock', fn (): string => $this->elapsed($startedAt));
        $bar->setFormat('  %current% emails [new: %new% | already read: %read%] %clock% %memory:6s% — %message%');
        $bar->setMessage('');

        return $bar;
    }

    /**
     * @param  array{found: int, new: int}  $progress
     */
    private function finishProgress(?ProgressBar $bar, float $startedAt, array $progress): void
    {
        if ($bar instanceof ProgressBar) {
            if ($progress['found'] > 0) {
                $bar->finish();
                $this->newLine();
            }

            return;
        }

        if ($progress['found'] > 0) {
            $this->line(sprintf('[%s] %d emails read (%d new).', $this->elapsed($startedAt), $progress['found'], $progress['new']));
        }
    }

    private function elapsed(float $startedAt): string
    {
        $seconds = (int) (microtime(true) - $startedAt);

        return sprintf('%02d:%02d', intdiv($seconds, 60), $seconds % 60);
    }

    /**
     * A day (d-m-Y) covers the whole day: its start for --from, the start of the next day (exclusive) for --to.
     * A day with time (d-m-Y H:i) and other formats understood by Carbon are used as given.
     */
    private function parseDate(string $value, bool $endOfDay): CarbonImmutable
    {
        $value = mb_trim($value);

        try {
            if (preg_match('/^\d{1,2}-\d{1,2}-\d{4}$/', $value) === 1) {
                $day = CarbonImmutable::createFromFormat('!d-m-Y', $value);

                return $endOfDay ? $day->addDay() : $day;
            }

            if (preg_match('/^\d{1,2}-\d{1,2}-\d{4} \d{1,2}:\d{2}$/', $value) === 1) {
                return CarbonImmutable::createFromFormat('!d-m-Y H:i', $value);
            }

            return CarbonImmutable::parse($value);
        } catch (Throwable) {
            throw new InvalidArgumentException("Invalid date: {$value}");
        }
    }
}
