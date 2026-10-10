<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Closure;
use Cron\CronExpression;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Validator;
use Mupy\MailListeners\Models\MailAccount;
use Mupy\MailListeners\Support\MailListenersLog;
use Mupy\MailListeners\Tenancy\Tenancy;
use Mupy\MailListeners\Tenancy\TenantColumn;

/**
 * Mail accounts defined in code: the central ones ({@see Facades\MailListeners::mailbox()}) and, with multi-tenancy,
 * the ones of each tenant ({@see Facades\MailListeners::tenantMailboxes()}).
 *
 * The code is the source of truth and the `mail_listener_accounts` table only holds their state (polling cursor,
 * read emails, listener runs). The rows are synced by the commands, and only when the definitions change: the
 * fingerprint of the last sync is kept in the cache, so a poll with unchanged definitions runs no extra query.
 * The mailboxes of the tenants are only synced on demand (`mail-listeners:sync`, {@see syncTenant()}), never by a poll.
 *
 * @phpstan-type SyncResult array{created: list<string>, updated: list<string>, deactivated: list<string>, errors: array<string, list<string>>}
 */
final class MailboxRegistry
{
    public const string FINGERPRINT_CACHE_KEY = 'mail-listeners:mailboxes-fingerprint';

    private const string SYNC_LOCK = 'mail-listeners:mailboxes-sync';

    private readonly MailboxDefinitions $central;

    /**
     * @var list<Closure(MailboxDefinitions, int|string): void>
     */
    private array $tenantDefinitions = [];

    public function __construct(
        private readonly ConnectorManager $connectors,
        private readonly EventRegistry $events,
        private readonly Tenancy $tenancy,
    ) {
        $this->central = new MailboxDefinitions();
    }

    /**
     * Define a central mail account (of no tenant), or get its definition when it is already defined.
     */
    public function mailbox(string $email): MailboxDefinition
    {
        return $this->central->mailbox($email);
    }

    /**
     * Central mail accounts defined in code.
     *
     * @return array<string, MailboxDefinition>
     */
    public function all(): array
    {
        return $this->central->all();
    }

    public function has(string $email): bool
    {
        return $this->central->has($email);
    }

    /**
     * Define the mail accounts of each tenant. The callback runs in the context of the tenant, when its mailboxes
     * are synced (`mail-listeners:sync` or {@see syncTenant()}), e.g. to read them from the tenant settings.
     *
     * @param  Closure(MailboxDefinitions $mailboxes, int|string $tenant): void  $define
     */
    public function tenantMailboxes(Closure $define): void
    {
        $this->tenantDefinitions[] = $define;
    }

    /**
     * Mail accounts defined in code for the tenant (null: the central ones).
     */
    public function definitions(int|string|null $tenant = null): MailboxDefinitions
    {
        return $this->tenancy->run($tenant, fn (): MailboxDefinitions => $this->evaluate($tenant));
    }

    /**
     * Sync the accounts of the tenant (null: the central ones) when their definitions (or the registered connectors
     * and events) changed since the last sync. Returns null when nothing changed (no query is run).
     *
     * @return SyncResult|null
     */
    public function syncIfChanged(int|string|null $tenant = null): ?array
    {
        return $this->tenancy->run($tenant, function () use ($tenant): ?array {
            $definitions = $this->evaluate($tenant);

            if (Cache::get($this->fingerprintCacheKey($tenant)) === $this->fingerprint($definitions)) {
                return null;
            }

            return $this->syncDefinitions($definitions, $tenant);
        });
    }

    /**
     * Sync the mail accounts of a tenant defined in code, when they changed. Call it when the data they are defined
     * from changes (e.g. the tenant settings).
     *
     * @return SyncResult|null
     */
    public function syncTenant(int|string $tenant): ?array
    {
        return $this->syncIfChanged($tenant);
    }

    /**
     * Central valid active definitions whose account is missing from the given active accounts (e.g. the database
     * was refreshed or the account was deactivated by hand), so the caller can sync again without an extra query.
     *
     * @param  Collection<int, MailAccount>  $activeAccounts
     * @return list<string>
     */
    public function missingFrom(Collection $activeAccounts): array
    {
        $emails = $activeAccounts->filter(fn (MailAccount $account): bool => $account->tenantKey() === null)->pluck('email')->all();
        $mailboxes = $this->central->all();

        return array_values(array_filter(
            array_keys($mailboxes),
            fn (string $email): bool => $mailboxes[$email]->isActive()
                && ! in_array($email, $emails, true)
                && $this->errors($mailboxes[$email]) === [],
        ));
    }

    /**
     * Create or update the account of every valid definition of the tenant (null: the central ones), writing only
     * what changed, and deactivate its accounts that are no longer defined. Invalid definitions are reported and
     * skipped, without blocking the others.
     *
     * @return SyncResult
     */
    public function sync(int|string|null $tenant = null): array
    {
        return $this->tenancy->run($tenant, fn (): array => $this->syncDefinitions($this->evaluate($tenant), $tenant));
    }

    /**
     * Sync the central accounts and the accounts of every tenant.
     *
     * @return list<array{tenant: int|string|null, result: SyncResult}>
     */
    public function syncAll(): array
    {
        $results = [['tenant' => null, 'result' => $this->sync()]];

        foreach ($this->tenancy->all() as $tenant) {
            $results[] = ['tenant' => $tenant, 'result' => $this->sync($tenant)];
        }

        return $results;
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
     * Definitions of the tenant (null: the central ones), evaluated in the current context.
     */
    private function evaluate(int|string|null $tenant): MailboxDefinitions
    {
        if ($tenant === null) {
            return $this->central;
        }

        $definitions = new MailboxDefinitions();

        foreach ($this->tenantDefinitions as $define) {
            $define($definitions, $tenant);
        }

        return $definitions;
    }

    /**
     * Runs in the context of the tenant, so the validation of the definitions can depend on it.
     *
     * @return SyncResult
     */
    private function syncDefinitions(MailboxDefinitions $definitions, int|string|null $tenant): array
    {
        return Cache::lock($this->tenantKey(self::SYNC_LOCK, $tenant), 60)->block(10, function () use ($definitions, $tenant): array {
            $mailboxes = $definitions->all();
            $fingerprint = $this->fingerprint($definitions);
            $result = ['created' => [], 'updated' => [], 'deactivated' => [], 'errors' => []];

            $accounts = MailAccount::query()
                ->forTenant($tenant)
                ->where(fn (Builder $query) => $query->whereIn('email', array_keys($mailboxes))->orWhere('managed', true))
                ->orderBy('id')
                ->get();

            $accountsByEmail = $accounts->groupBy('email')->map(fn (Collection $group): MailAccount => $group->first());

            foreach ($mailboxes as $email => $definition) {
                $errors = $this->errors($definition);

                if ($errors !== []) {
                    $result['errors'][$email] = $errors;

                    continue;
                }

                $account = $accountsByEmail->get($email) ?? (new MailAccount())->forceFill(TenantColumn::attributes($tenant));
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
                && ! array_key_exists($account->email, $mailboxes));

            if ($undefined->isNotEmpty()) {
                MailAccount::query()->allTenants()->whereKey($undefined->modelKeys())->update(['active' => false]);

                $result['deactivated'] = $undefined->pluck('email')->values()->all();
            }

            Cache::forever($this->fingerprintCacheKey($tenant), $fingerprint);

            $this->log($result, $tenant);

            return $result;
        });
    }

    private function fingerprintCacheKey(int|string|null $tenant): string
    {
        return $this->tenantKey(self::FINGERPRINT_CACHE_KEY, $tenant);
    }

    private function tenantKey(string $key, int|string|null $tenant): string
    {
        return $tenant === null ? $key : "{$key}:tenant:{$tenant}";
    }

    /**
     * Changes when a definition, a registered connector or a registered event changes.
     */
    private function fingerprint(MailboxDefinitions $definitions): string
    {
        $attributes = array_map(fn (MailboxDefinition $definition): array => $definition->toAttributes(), $definitions->all());
        ksort($attributes);

        return sha1(serialize([$attributes, $this->connectors->all(), $this->events->all()]));
    }

    /**
     * @param  SyncResult  $result
     */
    private function log(array $result, int|string|null $tenant): void
    {
        $logger = MailListenersLog::channel();
        $context = Tenancy::logContext($tenant);

        foreach (['created', 'updated', 'deactivated'] as $change) {
            if ($result[$change] !== []) {
                $logger->info("Mail listeners: mail accounts defined in code {$change}.", [...$context, 'emails' => $result[$change]]);
            }
        }

        foreach ($result['errors'] as $email => $errors) {
            $logger->error('Mail listeners: invalid mail account defined in code, skipped.', [...$context, 'email' => $email, 'errors' => $errors]);
        }
    }
}
