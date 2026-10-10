<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Tenancy;

use Illuminate\Database\Schema\Blueprint;
use InvalidArgumentException;

/**
 * The nullable `tenant_id` column of the package tables, created only when `mail-listeners.tenancy.enabled` is set
 * (null for central accounts), with the type of `mail-listeners.tenancy.column_type`.
 */
final class TenantColumn
{
    public const string NAME = 'tenant_id';

    /**
     * @param  list<string>  $indexWith  columns indexed after `tenant_id`
     */
    public static function add(Blueprint $table, array $indexWith = []): void
    {
        if (! Tenancy::enabled()) {
            return;
        }

        $type = Tenancy::columnType();

        $column = match ($type) {
            'unsignedBigInteger' => $table->unsignedBigInteger(self::NAME),
            'uuid' => $table->uuid(self::NAME),
            'ulid' => $table->ulid(self::NAME),
            'string' => $table->string(self::NAME, 64),
            default => throw new InvalidArgumentException("Invalid mail-listeners.tenancy.column_type [{$type}]: use unsignedBigInteger, uuid, ulid or string."),
        };

        $column->nullable();

        $table->index([self::NAME, ...$indexWith]);
    }

    /**
     * Attributes setting the tenant of a row, empty when tenancy is disabled (there is no column).
     *
     * @return array<string, int|string|null>
     */
    public static function attributes(int|string|null $tenant): array
    {
        return Tenancy::enabled() ? [self::NAME => $tenant] : [];
    }
}
