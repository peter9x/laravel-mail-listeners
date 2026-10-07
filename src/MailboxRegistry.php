<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Cron\CronExpression;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Support\MailListenersLog;

/**
 * Mail accounts defined in code ({@see Facades\MailListeners::mailbox()}).
 *
 * The code is the source of truth and the `mail_listener_accounts` table only holds their state (polling cursor,
 * read emails, listener runs). The rows are synced by the commands, and only when the definitions change: the
 * fingerprint of the last sync is kept in the cache, so a poll with unchanged definitions runs no extra query.
 *
 * @phpstan-type SyncResult array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}
 */
final class MailboxRegistry
{
    public const string FINGERPRINT_CACHE_KEY = 'mail-listeners:mailboxes-fingerprint';

    private const string SYNC_LOCK = 'mail-listeners:mailboxes-sync';

    /**
     * @var array<string, MailboxDefinition>
     */
    private array $mailboxes = [];

    public function __construct(
        private readonly ConnectorManager $connectors,
        private readonly EventRegistry $events,
    ) {}

    /**
     * Define a mail account, or get its definition when it is already defined.
     */
    public function mailbox(string $email): MailboxDefinition
    {
        $definition = new MailboxDefinition($email);

        return $this->mailboxes[$definition->email] ??= $definition;
    }

    /**
     * @return array<string, MailboxDefinition>
     */
    public function all(): array
    {
        return $this->mailboxes;
    }

    public function has(string $email): bool
    {
        return array_key_exists(mb_strtolower(mb_trim($email)), $this->mailboxes);
    }

    /**
     * Sync the accounts when the definitions (or the registered connectors and events) changed since the last sync.
     * Returns null when nothing changed (no query is run).
     *
     * @return SyncResult|null
     */
    public function syncIfChanged(): ?array
    {
        if (Cache::get(self::FINGERPRINT_CACHE_KEY) === $this->fingerprint()) {
            return null;
        }

        return $this->sync();
    }

    /**
     * Valid active definitions whose account is missing from the given active accounts (e.g. the database was
     * refreshed or the account was deactivated by hand), so the caller can sync again without an extra query.
     *
     * @param  Collection<int, MailAccount>  $activeAccounts
     * @return list<string>
     */
    public function missingFrom(Collection $activeAccounts): array
    {
        $emails = $activeAccounts->pluck('email')->all();

        return array_values(array_filter(
            array_keys($this->mailboxes),
            fn (string $email): bool => $this->mailboxes[$email]->isActive()
                && ! in_array($email, $emails, true)
                && $this->errors($this->mailboxes[$email]) === [],
        ));
    }

    /**
     * Create or update the account of every valid definition (writing only what changed) and deactivate the accounts
     * that are no longer defined. Invalid definitions are reported and skipped, without blocking the others.
     *
     * @return SyncResult
     */
    public function sync(): array
    {
        return Cache::lock(self::SYNC_LOCK, 60)->block(10, function (): array {
            $fingerprint = $this->fingerprint();
            $result = ['created' => [], 'updated' => [], 'deactivated' => [], 'errors' => []];

            $accounts = MailAccount::query()
                ->whereIn('email', array_keys($this->mailboxes))
                ->orWhere('managed', true)
                ->orderBy('id')
                ->get();

            $accountsByEmail = $accounts->groupBy('email')->map(fn (Collection $group): MailAccount => $group->first());

            foreach ($this->mailboxes as $email => $definition) {
                $errors = $this->errors($definition);

                if ($errors !== []) {
                    $result['errors'][$email] = $errors;

                    continue;
                }

                $account = $accountsByEmail->get($email) ?? new MailAccount();
                $account->fill($definition->toAttributes());

                if (! $account->exists) {
                    $result['created'][] = $email;
                } elseif ($account->isDirty()) {
                    $result['updated'][] = $email;
                } else {
                    continue;
                }

                $account->save();
            }

            $undefined = $accounts->filter(fn (MailAccount $account): bool => $account->managed
                && $account->active
                && ! array_key_exists($account->email, $this->mailboxes));

            if ($undefined->isNotEmpty()) {
                MailAccount::query()->whereKey($undefined->modelKeys())->update(['active' => false]);

                $result['deactivated'] = $undefined->pluck('email')->values()->all();
            }

            Cache::forever(self::FINGERPRINT_CACHE_KEY, $fingerprint);

            $this->log($result);

            return $result;
        });
    }

    /**
     * Validation errors of a definition: unknown connector, invalid settings, invalid cron, missing or unknown events.
     *
     * @return list<string>
     */
    public function errors(MailboxDefinition $definition): array
    {
        $connector = $definition->connectorKey();

        if ($connector === null) {
            return ['No connector configured.'];
        }

        if (! $this->connectors->has($connector)) {
            return ["Mail connector [{$connector}] is not registered."];
        }

        $errors = Validator::make(
            ['email' => $definition->email, ...$definition->settings()],
            ['email' => ['required', 'email', 'max:255'], ...$this->connectors->connectorClass($connector)::settingsRules(creating: true)],
        )->errors()->all();

        if (! CronExpression::isValidExpression($definition->cronExpression())) {
            $errors[] = "Invalid cron expression [{$definition->cronExpression()}].";
        }

        if ($definition->eventList() === []) {
            $errors[] = 'No events configured.';
        }

        foreach ($definition->eventList() as $event) {
            if ($this->events->resolve($event) === null) {
                $errors[] = "Event [{$event}] is neither registered in the mail-listeners.events config nor a class extending EmailReceived.";
            }
        }

        return $errors;
    }

    /**
     * Changes when a definition, a registered connector or a registered event changes.
     */
    private function fingerprint(): string
    {
        $definitions = array_map(fn (MailboxDefinition $definition): array => $definition->toAttributes(), $this->mailboxes);
        ksort($definitions);

        return sha1(serialize([$definitions, $this->connectors->all(), $this->events->all()]));
    }

    /**
     * @param  SyncResult  $result
     */
    private function log(array $result): void
    {
        $logger = MailListenersLog::channel();

        foreach (['created', 'updated', 'deactivated'] as $change) {
            if ($result[$change] !== []) {
                $logger->info("Mail listeners: mail accounts defined in code {$change}.", ['emails' => $result[$change]]);
            }
        }

        foreach ($result['errors'] as $email => $errors) {
            $logger->error('Mail listeners: invalid mail account defined in code, skipped.', ['email' => $email, 'errors' => $errors]);
        }
    }
}
