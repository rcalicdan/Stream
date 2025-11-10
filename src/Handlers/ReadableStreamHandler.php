<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;

class ReadableStreamHandler
{
    /** @var resource */
    private $resource;
    private int $chunkSize;
    private string $buffer = '';
    private ?string $watcherId = null;

    /** @var array<array{resolve: callable, reject: callable, length: ?int, promise: CancellablePromiseInterface}> */
    private array $readQueue = [];

    // Callbacks to interact with the stream
    private $emitCallback;
    private $closeCallback;
    private $isEofCallback;
    private $pauseCallback;
    private $isPausedCallback;
    private $hasListenersCallback;

    /**
     * @param resource $resource
     */
    public function __construct(
        $resource,
        int $chunkSize,
        callable $emitCallback,
        callable $closeCallback,
        callable $isEofCallback,
        callable $pauseCallback,
        callable $isPausedCallback,
        callable $hasListenersCallback
    ) {
        $this->resource = $resource;
        $this->chunkSize = $chunkSize;
        $this->emitCallback = $emitCallback;
        $this->closeCallback = $closeCallback;
        $this->isEofCallback = $isEofCallback;
        $this->pauseCallback = $pauseCallback;
        $this->isPausedCallback = $isPausedCallback;
        $this->hasListenersCallback = $hasListenersCallback;
    }

    public function getBuffer(): string
    {
        return $this->buffer;
    }

    public function setBuffer(string $buffer): void
    {
        $this->buffer = $buffer;
    }

    public function clearBuffer(): void
    {
        $this->buffer = '';
    }

    public function prependBuffer(string $data): void
    {
        $this->buffer = $data . $this->buffer;
    }

    public function hasQueuedReads(): bool
    {
        return ! empty($this->readQueue);
    }

    public function queueRead(?int $length, CancellablePromiseInterface $promise): void
    {
        $this->readQueue[] = [
            'resolve' => fn ($value) => $promise->resolve($value),
            'reject' => fn ($reason) => $promise->reject($reason),
            'length' => $length ?? $this->chunkSize,
            'promise' => $promise,
        ];
    }

    public function cancelRead(CancellablePromiseInterface $promise): void
    {
        foreach ($this->readQueue as $index => $item) {
            if ($item['promise'] === $promise) {
                unset($this->readQueue[$index]);
                $this->readQueue = array_values($this->readQueue);

                break;
            }
        }

        // Pause if no more reads pending and no data listeners
        if (empty($this->readQueue) && ! ($this->hasListenersCallback)('data')) {
            ($this->pauseCallback)();
        }
    }

    public function rejectAllPending(\Throwable $error): void
    {
        while (! empty($this->readQueue)) {
            $item = array_shift($this->readQueue);
            $item['reject']($error);
        }
    }

    public function startWatching(bool $readable, bool $paused, bool $closed): void
    {
        if ($this->watcherId !== null || ! $readable || $paused || $closed) {
            return;
        }

        $this->watcherId = Loop::addStreamWatcher(
            $this->resource,
            fn () => $this->handleReadable(),
            'read'
        );
    }

    public function stopWatching(): void
    {
        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    public function handleReadable(): void
    {
        // Check if paused (important - don't read if paused)
        if (($this->isPausedCallback)()) {
            return;
        }

        $readLength = $this->chunkSize;
        if (! empty($this->readQueue)) {
            $readLength = $this->readQueue[0]['length'] ?? $this->chunkSize;
        }

        $data = @fread($this->resource, $readLength);

        if ($data === false) {
            $error = new StreamException('Failed to read from stream');
            ($this->emitCallback)('error', $error);

            while (! empty($this->readQueue)) {
                $item = array_shift($this->readQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['reject']($error);
                }
            }

            ($this->closeCallback)();

            return;
        }

        // Check for EOF
        if ($data === '' && ($this->isEofCallback)()) {
            $this->stopWatching();

            while (! empty($this->readQueue)) {
                $item = array_shift($this->readQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['resolve'](null);
                }
            }

            ($this->emitCallback)('end');

            return;
        }

        if ($data !== '') {
            // Emit data event
            ($this->emitCallback)('data', $data);

            // Resolve first pending read
            if (! empty($this->readQueue)) {
                $item = array_shift($this->readQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['resolve']($data);
                }

                // Pause if no more reads pending and no data listeners
                if (empty($this->readQueue) && ! ($this->hasListenersCallback)('data')) {
                    ($this->pauseCallback)();
                }
            } elseif (! ($this->hasListenersCallback)('data')) {
                // No pending reads and no data listeners, pause
                ($this->pauseCallback)();
            }
        }
    }

    public function shouldPauseAfterRead(): bool
    {
        return empty($this->readQueue) && ! ($this->hasListenersCallback)('data');
    }
}
