<?php

declare(strict_types=1);

namespace Mupy\MailListeners;

use Illuminate\Support\Facades\Event;
use Mupy\MailListeners\Events\EmailReceived;

/**
 * Context events registered by the app in the `mail-listeners.events` config, keyed by the value stored on the accounts.
 */
final class EventRegistry
{
    /**
     * @return array<string, array{class: class-string<EmailReceived>, label: string}>
     */
    public function all(): array
    {
        return array_filter(
            (array) config('mail-listeners.events', []),
            fn (mixed $event): bool => is_array($event) && is_string($event['class'] ?? null) && is_subclass_of($event['class'], EmailReceived::class),
        );
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
     * @return class-string<EmailReceived>|null
     */
    public function eventClass(string $key): ?string
    {
        return $this->all()[$key]['class'] ?? null;
    }

    /**
     * Event class of a registered key, or the value itself when it is an event class (extending EmailReceived).
     *
     * @return class-string<EmailReceived>|null
     */
    public function resolve(string $event): ?string
    {
        return $this->eventClass($event) ?? (is_subclass_of($event, EmailReceived::class) ? $event : null);
    }

    public function label(string $key): string
    {
        return __($this->all()[$key]['label'] ?? $key);
    }

    /**
     * Class names of the listeners registered for the event (closures are left out).
     *
     * @return list<class-string>
     */
    public function listeners(string $key): array
    {
        $eventClass = $this->eventClass($key);

        if ($eventClass === null) {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            fn (mixed $listener): ?string => is_string($listener) ? explode('@', $listener)[0] : null,
            Event::getRawListeners()[$eventClass] ?? [],
        ))));
    }

    /**
     * Events for a select: value, label and the labels of their listeners.
     *
     * @return list<array{value: string, label: string, listeners: list<string>}>
     */
    public function options(): array
    {
        return array_map(fn (string $key): array => [
            'value' => $key,
            'label' => $this->label($key),
            'listeners' => array_map(
                fn (string $listener): string => is_subclass_of($listener, MailListener::class) ? $listener::label() : class_basename($listener),
                $this->listeners($key),
            ),
        ], $this->keys());
    }
}
