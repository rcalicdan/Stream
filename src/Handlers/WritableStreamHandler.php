<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;

class WritableStreamHandler
{
    /** @var resource */
    private $resource;
    private int $softLimit;
    private string $writeBuffer = '';
    private ?string $watcherId = null;

    /** @var array<array{resolve: callable, reject: callable, bytes: int, promise: CancellablePromiseInterface}> */
    private array $writeQueue = [];

    private $emitCallback;
    private $closeCallback;

    /**
     * @param resource $resource
     */
    public function __construct($resource, int $softLimit, callable $emitCallback, callable $closeCallback)
    {
        $this->resource = $resource;
        $this->softLimit = $softLimit;
        $this->emitCallback = $emitCallback;
        $this->closeCallback = $closeCallback;
    }

    public function getBufferLength(): int
    {
        return strlen($this->writeBuffer);
    }

    public function hasQueuedWrites(): bool
    {
        return ! empty($this->writeQueue);
    }

    public function queueWrite(string $data, CancellablePromiseInterface $promise): void
    {
        $this->writeBuffer .= $data;
        $bytesToWrite = strlen($data);

        $this->writeQueue[] = [
            'resolve' => fn ($value) => $promise->resolve($value),
            'reject' => fn ($reason) => $promise->reject($reason),
            'bytes' => $bytesToWrite,
            'promise' => $promise,
        ];
    }

    public function cancelWrite(CancellablePromiseInterface $promise): void
    {
        $bytesToRemove = 0;

        foreach ($this->writeQueue as $index => $item) {
            if ($item['promise'] === $promise) {
                $bytesToRemove = $item['bytes'];
                unset($this->writeQueue[$index]);
                $this->writeQueue = array_values($this->writeQueue);

                break;
            }
        }

        if ($bytesToRemove > 0 && strlen($this->writeBuffer) >= $bytesToRemove) {
            $this->writeBuffer = substr($this->writeBuffer, 0, -$bytesToRemove);
        }

        if ($this->writeBuffer === '' && $this->watcherId !== null) {
            $this->stopWatching();
        }
    }

    public function rejectAllPending(\Throwable $error): void
    {
        while (! empty($this->writeQueue)) {
            $item = array_shift($this->writeQueue);
            if (! $item['promise']->isCancelled()) {
                $item['reject']($error);
            }
        }
        $this->writeBuffer = '';
    }

    public function startWatching(bool $writable, bool $ending, bool $closed): void
    {
        if ($this->watcherId !== null || $closed || $this->writeBuffer === '') {
            return;
        }

        if (! $writable && ! $ending) {
            return;
        }

        $this->watcherId = Loop::addStreamWatcher(
            $this->resource,
            fn () => $this->handleWritable(),
            'write'
        );
    }

    public function stopWatching(): void
    {
        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    public function handleWritable(): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        $written = @fwrite($this->resource, $this->writeBuffer);

        if ($written === false || $written === 0) {
            $error = new StreamException('Failed to write to stream');
            ($this->emitCallback)('error', $error);

            while (! empty($this->writeQueue)) {
                $item = array_shift($this->writeQueue);
                if (! $item['promise']->isCancelled()) {
                    $item['reject']($error);
                }
            }

            ($this->closeCallback)();

            return;
        }

        $wasAboveLimit = strlen($this->writeBuffer) >= $this->softLimit;
        $this->writeBuffer = substr($this->writeBuffer, $written);
        $isNowBelowLimit = strlen($this->writeBuffer) < $this->softLimit;

        $this->resolveCompletedWrites($written);

        if ($wasAboveLimit && $isNowBelowLimit) {
            ($this->emitCallback)('drain');
        }

        if ($this->writeBuffer === '' && empty($this->writeQueue)) {
            ($this->emitCallback)('drain');
        }

        if ($this->writeBuffer === '' && $this->watcherId !== null) {
            $this->stopWatching();
        }
    }

    private function resolveCompletedWrites(int $written): void
    {
        $remaining = $written;

        while ($remaining > 0 && ! empty($this->writeQueue)) {
            $item = &$this->writeQueue[0];

            if ($item['promise']->isCancelled()) {
                array_shift($this->writeQueue);

                continue;
            }

            if ($remaining >= $item['bytes']) {
                $remaining -= $item['bytes'];
                $completed = array_shift($this->writeQueue);
                $completed['resolve']($completed['bytes']);
            } else {
                $item['bytes'] -= $remaining;
                $remaining = 0;
            }
        }
    }

    public function isFullyDrained(): bool
    {
        return $this->writeBuffer === '' && empty($this->writeQueue);
    }
}
