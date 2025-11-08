<?php

namespace Hibla\Stream;

use Hibla\EventLoop\Loop;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;

class WritableStream implements WritableStreamInterface
{
    use EventEmitterTrait;

    /** @var resource|null */
    private $resource;

    private bool $writable = true;
    private bool $closed = false;
    private bool $ending = false;
    private ?string $watcherId = null;
    private string $writeBuffer = '';
    private int $softLimit;

    /** @var array<array{resolve: callable, reject: callable, bytes: int, promise: CancellablePromiseInterface}> */
    private array $writeQueue = [];

    /**
     * @param resource $resource Stream resource
     * @param int $softLimit Soft limit for write buffer (in bytes)
     */
    public function __construct($resource, int $softLimit = 65536)
    {
        if (!is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        // Verify resource can be written to
        $meta = stream_get_meta_data($resource);
        $writableModes = ['w', 'a', 'x', 'c', '+'];
        $isWritable = false;
        foreach ($writableModes as $mode) {
            if (str_contains($meta['mode'], $mode)) {
                $isWritable = true;
                break;
            }
        }

        if (!$isWritable) {
            throw new StreamException('Resource is not writable');
        }

        $this->resource = $resource;
        $this->softLimit = $softLimit;

        // Set non-blocking mode
        if (!stream_set_blocking($resource, false)) {
            throw new StreamException('Failed to set non-blocking mode');
        }
    }

    public function write(string $data): CancellablePromiseInterface
    {
        if (!$this->isWritable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not writable'));
        }

        if ($data === '') {
            return $this->createResolvedCancellable(0);
        }

        $promise = new CancellablePromise(function ($resolve, $reject) use ($data) {
            $this->writeBuffer .= $data;
            $bytesToWrite = strlen($data);

            $queueItem = [
                'resolve' => $resolve,
                'reject' => $reject,
                'bytes' => $bytesToWrite,
                'promise' => null, // Will be set after creation
            ];

            $this->writeQueue[] = &$queueItem;

            $this->startWriting();
        });

        // Set the promise reference in the queue item
        $this->writeQueue[array_key_last($this->writeQueue)]['promise'] = $promise;

        // Set cancel handler
        $promise->setCancelHandler(function () use ($promise) {
            $this->cancelWrite($promise);
        });

        return $promise;
    }

    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->write($data . "\n");
    }

    public function end(?string $data = null): CancellablePromiseInterface
    {
        if (!$this->isWritable() || $this->ending) {
            return $this->createResolvedCancellable(null);
        }

        $this->ending = true;
        $this->writable = false;

        $promise = new CancellablePromise(function ($resolve, $reject) use ($data) {
            $writePromise = null;

            if ($data !== null && $data !== '') {
                $writePromise = $this->write($data);
            }

            $finish = function () use ($resolve, $reject) {
                $this->waitForDrain()->then(function () use ($resolve) {
                    $this->emit('finish');
                    $this->close();
                    $resolve(null);
                })->catch(function ($error) use ($reject) {
                    $this->emit('error', $error);
                    $this->close();
                    $reject($error);
                });
            };

            if ($writePromise !== null) {
                $writePromise->then($finish)->catch(function ($error) use ($reject) {
                    $this->emit('error', $error);
                    $this->close();
                    $reject($error);
                });
            } else {
                $finish();
            }
        });

        return $promise;
    }

    public function isWritable(): bool
    {
        return $this->writable && !$this->closed;
    }

    public function isEnding(): bool
    {
        return $this->ending;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->writable = false;

        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }

        // Reject any pending writes
        while (!empty($this->writeQueue)) {
            $item = array_shift($this->writeQueue);
            if (!$item['promise']->isCancelled()) {
                $item['reject'](new StreamException('Stream closed'));
            }
        }

        $this->writeBuffer = '';

        if (is_resource($this->resource)) {
            @fclose($this->resource);
            $this->resource = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function startWriting(): void
    {
        if ($this->watcherId !== null || !$this->writable || $this->writeBuffer === '') {
            return;
        }

        $this->watcherId = Loop::addStreamWatcher(
            $this->resource,
            function () {
                $this->handleWritable();
            },
            'write'
        );
    }

    private function handleWritable(): void
    {
        if (!$this->writable || $this->writeBuffer === '') {
            return;
        }

        $written = @fwrite($this->resource, $this->writeBuffer);

        if ($written === false || $written === 0) {
            $error = new StreamException('Failed to write to stream');
            $this->emit('error', $error);

            // Reject all pending writes
            while (!empty($this->writeQueue)) {
                $item = array_shift($this->writeQueue);
                if (!$item['promise']->isCancelled()) {
                    $item['reject']($error);
                }
            }

            $this->close();
            return;
        }

        // Update buffer
        $wasAboveLimit = strlen($this->writeBuffer) >= $this->softLimit;
        $this->writeBuffer = substr($this->writeBuffer, $written);
        $isNowBelowLimit = strlen($this->writeBuffer) < $this->softLimit;

        // Resolve completed writes
        $remaining = $written;
        while ($remaining > 0 && !empty($this->writeQueue)) {
            $item = &$this->writeQueue[0];
            
            if ($item['promise']->isCancelled()) {
                // Skip cancelled promises
                array_shift($this->writeQueue);
                continue;
            }
            
            if ($remaining >= $item['bytes']) {
                // This write is complete
                $remaining -= $item['bytes'];
                $completed = array_shift($this->writeQueue);
                $completed['resolve']($completed['bytes']);
            } else {
                // Partial write
                $item['bytes'] -= $remaining;
                $remaining = 0;
            }
        }

        // Emit drain if buffer went from above to below limit
        if ($wasAboveLimit && $isNowBelowLimit) {
            $this->emit('drain');
        }

        // Stop watching if buffer is empty
        if ($this->writeBuffer === '' && $this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    private function cancelWrite(CancellablePromiseInterface $promise): void
    {
        // Remove from queue and update write buffer
        $bytesToRemove = 0;
        
        foreach ($this->writeQueue as $index => $item) {
            if ($item['promise'] === $promise) {
                $bytesToRemove = $item['bytes'];
                unset($this->writeQueue[$index]);
                $this->writeQueue = array_values($this->writeQueue); // Re-index
                break;
            }
        }

        // Remove cancelled bytes from buffer (from the end)
        if ($bytesToRemove > 0 && strlen($this->writeBuffer) >= $bytesToRemove) {
            $this->writeBuffer = substr($this->writeBuffer, 0, -$bytesToRemove);
        }

        // Stop watching if buffer is empty
        if ($this->writeBuffer === '' && $this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    private function waitForDrain(): CancellablePromiseInterface
    {
        if ($this->writeBuffer === '') {
            return $this->createResolvedCancellable(null);
        }

        $promise = new CancellablePromise(function ($resolve, $reject) {
            $drainHandler = null;
            $errorHandler = null;
            $cancelled = false;

            $drainHandler = function () use ($resolve, &$drainHandler, &$errorHandler, &$cancelled) {
                if ($cancelled) {
                    return;
                }

                $this->off('drain', $drainHandler);
                $this->off('error', $errorHandler);
                $resolve(null);
            };

            $errorHandler = function ($error) use ($reject, &$drainHandler, &$errorHandler, &$cancelled) {
                if ($cancelled) {
                    return;
                }

                $this->off('drain', $drainHandler);
                $this->off('error', $errorHandler);
                $reject($error);
            };

            $this->on('drain', $drainHandler);
            $this->on('error', $errorHandler);

            // If buffer is already empty, resolve immediately
            if ($this->writeBuffer === '') {
                $this->off('drain', $drainHandler);
                $this->off('error', $errorHandler);
                $resolve(null);
            }
        });

        return $promise;
    }

    private function createResolvedCancellable(mixed $value): CancellablePromiseInterface
    {
        $promise = new CancellablePromise(function ($resolve) use ($value) {
            $resolve($value);
        });

        return $promise;
    }

    private function createRejectedCancellable(\Throwable $reason): CancellablePromiseInterface
    {
        $promise = new CancellablePromise(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });

        return $promise;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }
}