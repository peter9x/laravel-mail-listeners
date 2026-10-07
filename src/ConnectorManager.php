<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;
use Mupy\MailListeners\Contracts\MailConnector;

/**
 * Connectors registered in the `mail-listeners.connectors` config, keyed by the value stored on the accounts.
 */
final readonly class ConnectorManager
{
    public function __construct(private Container $container) {}

    /**
     * @return array<string, class-string<MailConnector>>
     */
    public function all(): array
    {
        return (array) config('mail-listeners.connectors', []);
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->all());
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->all());
    }

    /**
     * @return class-string<MailConnector>
     */
    public function connectorClass(string $key): string
    {
        $class = $this->all()[$key] ?? null;

        if (! is_string($class) || ! is_subclass_of($class, MailConnector::class)) {
            throw new InvalidArgumentException("Mail connector [{$key}] is not registered.");
        }

        return $class;
    }

    public function connector(string $key): MailConnector
    {
        return $this->container->make($this->connectorClass($key));
    }

    public function label(string $key): string
    {
        return $this->connectorClass($key)::label();
    }

    /**
     * Connectors for a select: value, label and default settings.
     *
     * @return list<array{value: string, label: string, defaults: array<string, mixed>}>
     */
    public function options(): array
    {
        return array_map(fn (string $key): array => [
            'value' => $key,
            'label' => $this->label($key),
            'defaults' => $this->connectorClass($key)::defaultSettings(),
        ], $this->keys());
    }
}
