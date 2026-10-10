<?php

declare(strict_types=1);

namespace Mupy\MailListeners\Models\Concerns;

/**
 * Models stored on the `mail-listeners.database.connection` connection (default: the app default connection), e.g. the
 * central database of an app whose tenancy package switches the default connection to each tenant database.
 */
trait UsesMailListenersConnection
{
    public function getConnectionName(): ?string
    {
        return config('mail-listeners.database.connection') ?? parent::getConnectionName();
    }
}
