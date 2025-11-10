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
     * Register an event listener
     */
    public function on(string $event, callable $callback): self
    {
        $this->listeners[$event] ??= [];
        $this->listeners[$event][$this->listenerIdCounter++] = $callback;

        return $this;
    }

    /**
     * Register a one-time event listener
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
     * Remove an event listener
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

        if (empty($this->listeners[$event])) {
            unset($this->listeners[$event]);
        }

        return $this;
    }

    /**
     * Emit an event
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
                    // Error in error handler - log to stderr
                    fwrite(STDERR, "Unhandled error in stream error handler: {$e->getMessage()}\n");
                }
            }
        }
    }

    /**
     * Check if there are listeners for an event
     */
    protected function hasListeners(string $event): bool
    {
        return isset($this->listeners[$event]) && ! empty($this->listeners[$event]);
    }

    /**
     * Remove all listeners for an event or all events
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
