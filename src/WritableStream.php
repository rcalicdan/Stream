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

    /**
     * @param resource $resource
     * @param array<string, mixed> $meta
     */
    private function setupNonBlocking($resource, array $meta): void
    {
        $streamType = $meta['stream_type'] ?? '';
        $isWindows = DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS, 'WIN') === 0;

        $shouldSetNonBlocking = false;

        if (in_array($streamType, ['tcp_socket', 'udp_socket', 'unix_socket', 'ssl_socket', 'TCP/IP', 'tcp_socket/ssl'], true)) {
            $shouldSetNonBlocking = true;
        } elseif (! $isWindows && in_array($streamType, ['STDIO', 'PLAINFILE', 'TEMP', 'MEMORY'], true)) {
            $shouldSetNonBlocking = true;
        }

        if ($shouldSetNonBlocking) {
            @stream_set_blocking($resource, false);
        }
    }

    private function initializeHandler(): void
    {
        if ($this->resource === null) {
            throw new StreamException('Resource is null during handler initialization');
        }

        $this->handler = new WritableStreamHandler(
            $this->resource,
            $this->softLimit,
            fn (string $event, mixed ...$args) => $this->emit($event, ...$args),
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

        /** @var CancellablePromise<int> $promise */
        $promise = new CancellablePromise();

        $this->handler->queueWrite($data, $promise);

        $promise->setCancelHandler(function () use ($promise): void {
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
            return $this->createResolvedVoidPromise();
        }

        $this->ending = true;
        
        /** @var CancellablePromise<void> $promise */
        $promise = new CancellablePromise();

        $finish = function () use ($promise): void {
            $this->writable = false;

            $this->waitForDrain()->then(function () use ($promise): void {
                $this->emit('finish');
                $this->close();
                $promise->resolve(null);
            })->catch(function (mixed $error) use ($promise): void {
                $this->emit('error', $error);
                $this->close();
                $promise->reject($error);
            });
        };

        if ($data !== null && $data !== '') {
            $this->write($data)->then(function () use ($finish): void {
                $finish();
            })->catch(function (mixed $error) use ($promise): void {
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

    /**
     * @return CancellablePromiseInterface<void>
     */
    private function waitForDrain(): CancellablePromiseInterface
    {
        if ($this->handler->isFullyDrained()) {
            return $this->createResolvedVoidPromise();
        }

        /** @var CancellablePromise<void> $promise */
        $promise = new CancellablePromise();
        $cancelled = false;

        $handlers = [
            'drain' => null,
            'error' => null,
        ];

        $checkDrained = function () use ($promise, &$handlers, &$cancelled): void {
            /** @phpstan-ignore if.alwaysFalse */
            if ($cancelled) {
                return;
            }

            if ($this->handler->isFullyDrained()) {
                /** @phpstan-ignore argument.type */
                $this->off('drain', $handlers['drain']);
                /** @phpstan-ignore argument.type */
                $this->off('error', $handlers['error']);
                $promise->resolve(null);
            }
        };

        $handlers['drain'] = function () use ($checkDrained): void {
            $checkDrained();
        };

        $handlers['error'] = function (mixed $error) use ($promise, &$handlers, &$cancelled): void {
            /** @phpstan-ignore if.alwaysFalse */
            if ($cancelled) {
                return;
            }

            $this->off('drain', $handlers['drain']);
            /** @phpstan-ignore argument.type */
            $this->off('error', $handlers['error']);
            $promise->reject($error);
        };

        $this->on('drain', $handlers['drain']);
        $this->on('error', $handlers['error']);

        $checkDrained();

        $promise->setCancelHandler(function () use (&$cancelled, &$handlers): void {
            $cancelled = true;
            $this->off('drain', $handlers['drain']);
            $this->off('error', $handlers['error']);
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