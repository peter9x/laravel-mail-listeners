<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Console;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Mupy\MailListeners\Console\Concerns\ReportsMailboxSync;
use Mupy\MailListeners\MailboxRegistry;
use Mupy\MailListeners\MailReader;
use Mupy\MailListeners\Models\MailAccount;
use Throwable;

/**
 * Reads a configured mail account within a period (e.g. to recover past emails), firing the account events
 * for the emails not read yet. The polling cursor of the account is left untouched.
 */
final class ReadCommand extends Command
{
    use ReportsMailboxSync;

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

        try {
            $result = $reader->readBetween($account, $from, $to);
        } catch (Throwable $exception) {
            $this->error("The mail account could not be read: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(sprintf(
            '%d emails found: %d new (events fired), %d already read.',
            $result['found'],
            $result['new'],
            $result['found'] - $result['new'],
        ));

        return self::SUCCESS;
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
