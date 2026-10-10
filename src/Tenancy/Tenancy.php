<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tenancy;

use Closure;
use Illuminate\Contracts\Container\Container;
use Mupy\MailListeners\Contracts\TenantResolver;

/**
 * Multi-tenancy of the package (`mail-listeners.tenancy`): whether it is enabled and the app {@see TenantResolver}.
 * When disabled, there is never a current tenant and callbacks run as they are.
 */
final readonly class Tenancy
{
    public function __construct(private Container $container) {}

    public static function enabled(): bool
    {
        return (bool) config('mail-listeners.tenancy.enabled', false);
    }

    /**
     * Type of the `tenant_id` column: unsignedBigInteger, uuid, ulid or string.
     */
    public static function columnType(): string
    {
        return (string) config('mail-listeners.tenancy.column_type', 'unsignedBigInteger');
    }

    /**
     * Log context of the tenant, empty when tenancy is disabled.
     *
     * @return array<string, int|string|null>
     */
    public static function logContext(int|string|null $tenant): array
    {
        return self::enabled() ? ['tenant' => $tenant] : [];
    }

    public function current(): int|string|null
    {
        return self::enabled() ? $this->resolver()->current() : null;
    }

    /**
     * @return list<int|string>
     */
    public function all(): array
    {
        return self::enabled() ? array_values([...$this->resolver()->all()]) : [];
    }

    /**
     * Run the callback in the context of the tenant (as it is without a tenant or when tenancy is disabled).
     *
     * @template TResult
     *
     * @param  Closure(): TResult  $callback
     * @return TResult
     */
    public function run(int|string|null $tenant, Closure $callback): mixed
    {
        if ($tenant === null || ! self::enabled()) {
            return $callback();
        }

        return $this->resolver()->run($tenant, $callback);
    }

    private function resolver(): TenantResolver
    {
        return $this->container->make(TenantResolver::class);
    }
}
