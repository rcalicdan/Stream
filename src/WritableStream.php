<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Handlers\WritableStreamHandler;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;
use Hibla\Stream\Traits\PromiseHelperTrait;

class WritableStream implements WritableStreamInterface
{
    use EventEmitterTrait;
    use PromiseHelperTrait;

    /** @var resource|null */
    private $resource;

    private bool $writable = true;
    private bool $closed = false;
    private bool $ending = false;
    private int $softLimit;

    private WritableStreamHandler $handler;

    /**
     * @param resource $resource Stream resource
     * @param int $softLimit Soft limit for write buffer (in bytes)
     */
    public function __construct($resource, int $softLimit = 65536)
    {
        if (! is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        $meta = stream_get_meta_data($resource);
        $writableModes = ['w', 'a', 'x', 'c', '+'];
        $isWritable = false;
        foreach ($writableModes as $mode) {
            if (str_contains($meta['mode'], $mode)) {
                $isWritable = true;

                break;
            }
        }

        if (! $isWritable) {
            throw new StreamException('Resource is not writable');
        }

        $this->resource = $resource;
        $this->softLimit = $softLimit;

        $this->setupNonBlocking($resource, $meta);
        $this->initializeHandler();
    }

    private function setupNonBlocking($resource, array $meta): void
    {
        $streamType = $meta['stream_type'] ?? '';
        $isWindows = DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS, 'WIN') === 0;

        $shouldSetNonBlocking = false;

        if (in_array($streamType, ['tcp_socket', 'udp_socket', 'unix_socket', 'ssl_socket', 'TCP/IP', 'tcp_socket/ssl'])) {
            $shouldSetNonBlocking = true;
        } elseif (! $isWindows && in_array($streamType, ['STDIO', 'PLAINFILE', 'TEMP', 'MEMORY'])) {
            $shouldSetNonBlocking = true;
        }

        if ($shouldSetNonBlocking) {
            @stream_set_blocking($resource, false);
        }
    }

    private function initializeHandler(): void
    {
        $this->handler = new WritableStreamHandler(
            $this->resource,
            $this->softLimit,
            fn (string $event, ...$args) => $this->emit($event, ...$args),
            fn () => $this->close()
        );
    }

    public function write(string $data): CancellablePromiseInterface
    {
        if (! $this->writable && ! $this->ending) {
            return $this->createRejectedPromise(new StreamException('Stream is not writable'));
        }

        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is not writable'));
        }

        if ($data === '') {
            return $this->createResolvedPromise(0);
        }

        $promise = new CancellablePromise();

        $this->handler->queueWrite($data, $promise);

        $promise->setCancelHandler(function () use ($promise) {
            $this->handler->cancelWrite($promise);
        });

        $this->handler->startWatching($this->writable, $this->ending, $this->closed);

        return $promise;
    }

    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->write($data . "\n");
    }

    public function end(?string $data = null): CancellablePromiseInterface
    {
        if ($this->ending || $this->closed) {
            return $this->createResolvedPromise(null);
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
            $this->write($data)->then(function () use ($finish) {
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
        return $this->writable && ! $this->closed;
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

        $this->handler->stopWatching();
        $this->handler->rejectAllPending(new StreamException('Stream closed'));

        if (is_resource($this->resource)) {
            @fclose($this->resource);
            $this->resource = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function waitForDrain(): CancellablePromiseInterface
    {
        if ($this->handler->isFullyDrained()) {
            return $this->createResolvedPromise(null);
        }

        $promise = new CancellablePromise();
        $cancelled = false;

        $drainHandler = null;
        $errorHandler = null;
        $checkDrained = null;

        $checkDrained = function () use ($promise, &$drainHandler, &$errorHandler, &$cancelled, &$checkDrained) {
            if ($cancelled) {
                return;
            }

            if ($this->handler->isFullyDrained()) {
                $this->off('drain', $drainHandler);
                $this->off('error', $errorHandler);
                $promise->resolve(null);
            }
        };

        $drainHandler = function () use ($checkDrained) {
            $checkDrained();
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

        $checkDrained();

        $promise->setCancelHandler(function () use (&$cancelled, &$drainHandler, &$errorHandler) {
            $cancelled = true;
            $this->off('drain', $drainHandler);
            $this->off('error', $errorHandler);
        });

        return $promise;
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}
