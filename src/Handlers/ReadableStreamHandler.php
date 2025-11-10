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

    /** @var array<array{resolve: callable(string|null): void, reject: callable(\Throwable): void, length: int, promise: CancellablePromiseInterface<string|null>}> */
    private array $readQueue = [];

    /**
     * @param resource $resource
     * @param callable(string, mixed=): void $emitCallback
     * @param callable(): void $closeCallback
     * @param callable(): bool $isEofCallback
     * @param callable(): void $pauseCallback
     * @param callable(): bool $isPausedCallback
     * @param callable(string): bool $hasListenersCallback
     */
    public function __construct(
        $resource,
        int $chunkSize,
        private $emitCallback,
        private $closeCallback,
        private $isEofCallback,
        private $pauseCallback,
        private $isPausedCallback,
        private $hasListenersCallback
    ) {
        $this->resource = $resource;
        $this->chunkSize = $chunkSize;
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
        return $this->readQueue !== [];
    }

    /**
     * @param CancellablePromiseInterface<string|null> $promise
     */
    public function queueRead(?int $length, CancellablePromiseInterface $promise): void
    {
        $this->readQueue[] = [
            'resolve' => fn (string|null $value) => $promise->resolve($value),
            'reject' => fn (\Throwable $reason) => $promise->reject($reason),
            'length' => $length ?? $this->chunkSize,
            'promise' => $promise,
        ];
    }

    /**
     * @param CancellablePromiseInterface<string|null> $promise
     */
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
        if ($this->readQueue === [] && ! ($this->hasListenersCallback)('data')) {
            ($this->pauseCallback)();
        }
    }

    public function rejectAllPending(\Throwable $error): void
    {
        while ($this->readQueue !== []) {
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
        if (($this->isPausedCallback)()) {
            return;
        }

        $readLength = $this->chunkSize;
        if ($this->readQueue !== []) {
            $readLength = $this->readQueue[0]['length'];
        }

        $data = @fread($this->resource, max(1, $readLength));

        if ($data === false) {
            $error = new StreamException('Failed to read from stream');
            ($this->emitCallback)('error', $error);

            while ($this->readQueue !== []) {
                $item = array_shift($this->readQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['reject']($error);
                }
            }

            ($this->closeCallback)();

            return;
        }

        if ($data === '' && ($this->isEofCallback)()) {
            $this->stopWatching();

            while ($this->readQueue !== []) {
                $item = array_shift($this->readQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['resolve'](null);
                }
            }

            ($this->emitCallback)('end');

            return;
        }

        if ($data !== '') {
            ($this->emitCallback)('data', $data);

            // Resolve first pending read
            if ($this->readQueue !== []) {
                $item = array_shift($this->readQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['resolve']($data);
                }

                // Pause if no more reads pending and no data listeners
                if ($this->readQueue === [] && ! ($this->hasListenersCallback)('data')) {
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
        return $this->readQueue === [] && ! ($this->hasListenersCallback)('data');
    }
}