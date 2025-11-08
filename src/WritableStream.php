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

        $streamType = $meta['stream_type'] ?? '';
        $isWindows = DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS, 'WIN') === 0;

        $shouldSetNonBlocking = false;

        if (in_array($streamType, ['tcp_socket', 'udp_socket', 'unix_socket', 'ssl_socket', 'TCP/IP', 'tcp_socket/ssl'])) {
            $shouldSetNonBlocking = true;
        } elseif (!$isWindows && in_array($streamType, ['STDIO', 'PLAINFILE', 'TEMP', 'MEMORY'])) {
            $shouldSetNonBlocking = true;
        }

        if ($shouldSetNonBlocking) {
            @stream_set_blocking($resource, false);
        }
    }

    public function write(string $data): CancellablePromiseInterface
    {
        if (!$this->writable && !$this->ending) {
            return $this->createRejectedCancellable(new StreamException('Stream is not writable'));
        }

        if ($this->closed) {
            return $this->createRejectedCancellable(new StreamException('Stream is not writable'));
        }

        if ($data === '') {
            return $this->createResolvedCancellable(0);
        }

        $promise = new CancellablePromise();
        
        $this->writeBuffer .= $data;
        $bytesToWrite = strlen($data);

        $queueItem = [
            'resolve' => fn($value) => $promise->resolve($value),
            'reject' => fn($reason) => $promise->reject($reason),
            'bytes' => $bytesToWrite,
            'promise' => $promise,
        ];

        $this->writeQueue[] = $queueItem;

        $promise->setCancelHandler(function () use ($promise) {
            $this->cancelWrite($promise);
        });

        $this->startWriting();

        return $promise;
    }

    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->write($data . "\n");
    }

    public function end(?string $data = null): CancellablePromiseInterface
    {
        if ($this->ending || $this->closed) {
            return $this->createResolvedCancellable(null);
        }

        $this->ending = true;
        $promise = new CancellablePromise();

        $finish = function () use ($promise) {
            $this->writable = false;

            $this->waitForDrain()->then(function () use ($promise) {
                $this->emit('finish');
                $this->close();
                $promise->resolve(null);
            })->catch(function ($error) use ($promise) {
                $this->emit('error', $error);
                $this->close();
                $promise->reject($error);
            });
        };

        if ($data !== null && $data !== '') {
            $this->write($data)->then(function() use ($finish) {
                $finish();
            })->catch(function ($error) use ($promise) {
                $this->writable = false;
                $this->emit('error', $error);
                $this->close();
                $promise->reject($error);
            });
        } else {
            $finish();
        }

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
        if ($this->watcherId !== null || $this->closed || $this->writeBuffer === '') {
            return;
        }

        if (!$this->writable && !$this->ending) {
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
        // Allow writes while ending, but not if already closed or not writable and not ending
        if ((!$this->writable && !$this->ending) || $this->writeBuffer === '') {
            return;
        }

        $written = @fwrite($this->resource, $this->writeBuffer);

        if ($written === false || $written === 0) {
            $error = new StreamException('Failed to write to stream');
            $this->emit('error', $error);

            while (!empty($this->writeQueue)) {
                $item = array_shift($this->writeQueue);
                if (!$item['promise']->isCancelled()) {
                    $item['reject']($error);
                }
            }

            $this->close();
            return;
        }

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

        $promise = new CancellablePromise();
        $cancelled = false;

        $drainHandler = null;
        $errorHandler = null;

        $drainHandler = function () use ($promise, &$drainHandler, &$errorHandler, &$cancelled) {
            if ($cancelled) {
                return;
            }

            $this->off('drain', $drainHandler);
            $this->off('error', $errorHandler);
            $promise->resolve(null);
        };

        $errorHandler = function ($error) use ($promise, &$drainHandler, &$errorHandler, &$cancelled) {
            if ($cancelled) {
                return;
            }

            $this->off('drain', $drainHandler);
            $this->off('error', $errorHandler);
            $promise->reject($error);
        };

        $this->on('drain', $drainHandler);
        $this->on('error', $errorHandler);

        // If buffer is already empty, resolve immediately
        if ($this->writeBuffer === '') {
            $this->off('drain', $drainHandler);
            $this->off('error', $errorHandler);
            $promise->resolve(null);
        }

        $promise->setCancelHandler(function () use (&$cancelled) {
            $cancelled = true;
        });

        return $promise;
    }

    private function createResolvedCancellable(mixed $value): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $promise->resolve($value);
        return $promise;
    }

    private function createRejectedCancellable(\Throwable $reason): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $promise->reject($reason);
        return $promise;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }
}