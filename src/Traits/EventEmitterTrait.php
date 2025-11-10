<?php

declare(strict_types=1);

namespace Hibla\Stream\Traits;

trait EventEmitterTrait
{
    /** @var array<string, array<int, callable>> */
    private array $listeners = [];

    /** @var int */
    private int $listenerIdCounter = 0;

    /**
     * Attaches a callback to an event, enabling code to react to the stream's state changes.
     *
     * @param string $event The name of the event to listen for.
     * @param callable $callback The function to execute when the event occurs.
     * @return static
     */
    public function on(string $event, callable $callback): self
    {
        $this->listeners[$event] ??= [];
        $this->listeners[$event][$this->listenerIdCounter++] = $callback;

        return $this;
    }

    /**
     * Attaches a callback that is automatically removed after its first execution.
     * Useful for one-time setup or teardown logic without manual cleanup.
     *
     * @param string $event The name of the event to listen for.
     * @param callable $callback The function to execute once.
     * @return static
     */
    public function once(string $event, callable $callback): self
    {
        $wrapper = null;
        $wrapper = function (...$args) use ($event, $callback, &$wrapper) {
            $this->off($event, $wrapper);
            $callback(...$args);
        };

        $this->on($event, $wrapper);

        return $this;
    }

    /**
     * Detaches a specific callback from an event to prevent memory leaks and manage resources.
     *
     * @param string $event The name of the event.
     * @param callable $callback The specific listener to remove.
     * @return static
     */
    public function off(string $event, callable $callback): self
    {
        if (! isset($this->listeners[$event])) {
            return $this;
        }

        foreach ($this->listeners[$event] as $id => $listener) {
            if ($listener === $callback) {
                unset($this->listeners[$event][$id]);
            }
        }

        if ($this->listeners[$event] === []) {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * Broadcasts an event to all registered listeners, announcing that something meaningful has occurred.
     *
     * @param string $event The name of the event to broadcast.
     * @param mixed ...$args The data to pass to each listener.
     */
    protected function emit(string $event, mixed ...$args): void
    {
        if (! isset($this->listeners[$event])) {
            return;
        }

        foreach ($this->listeners[$event] as $listener) {
            try {
                $listener(...$args);
            } catch (\Throwable $e) {
                if ($event !== 'error') {
                    $this->emit('error', $e);
                } else {
                    // Avoid an infinite loop if the error handler itself throws.
                    fwrite(STDERR, "Unhandled error in stream error handler: {$e->getMessage()}\n");
                }
            }
        }
    }

    /**
     * Checks if any listeners are registered, which can be used to avoid expensive work if no one is listening.
     *
     * @param string $event The name of the event to check.
     */
    protected function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && $this->listeners[$event] !== [];
    }

    /**
     * Detaches all listeners, a crucial cleanup step to prevent memory leaks when a stream is closed.
     *
     * @param string|null $event The event to clear, or null to clear all events.
     */
    protected function removeAllListeners(?string $event = null): void
    {
        if ($event === null) {
            $this->listeners = [];
        } else {
            unset($this->listeners[$event]);
        }
    }
}
