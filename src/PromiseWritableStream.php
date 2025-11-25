<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use Hibla\Stream\Traits\PromiseHelperTrait;

class PromiseWritableStream implements PromiseWritableStreamInterface
{
    use PromiseHelperTrait;

    private WritableResourceStream $stream;

    /**
     * Creates a promise-based wrapper around a WritableStreamResource.
     *
     * @param WritableResourceStream $stream The underlying stream resource
     */
    public function __construct(WritableResourceStream $stream)
    {
        $this->stream = $stream;
    }

    /**
     * Create a new instance from a PHP stream resource.
     *
     * @param resource $resource A writable PHP stream resource
     * @param int $softLimit The size of the write buffer (in bytes) at which backpressure is applied
     * @return self
     */
    public static function fromResource($resource, int $softLimit = 65536): self
    {
        return new self(new WritableResourceStream($resource, $softLimit));
    }

    /**
     * Get the underlying stream resource.
     */
    public function getWritableStream(): WritableResourceStream
    {
        return $this->stream;
    }

    /**
     * @inheritdoc
     */
    public function write(string $data): CancellablePromiseInterface
    {
        if (! $this->stream->isWritable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not writable'));
        }

        if ($data === '') {
            return $this->createResolvedPromise(0);
        }

        /** @var CancellablePromise<int> $promise */
        $promise = new CancellablePromise();
        $bytesToWrite = \strlen($data);
        $cancelled = false;

        $handler = $this->stream->getHandler();
        $initialBufferSize = $handler->getBufferLength();

        // Write the data
        $writeResult = $this->stream->write($data);

        // Set up promise resolution
        $checkWritten = function () use ($promise, $handler, $initialBufferSize, $bytesToWrite, &$cancelled): void {
            // @phpstan-ignore-next-line phpstan doesn't know that $cancelled is mutable
            if ($cancelled) {
                return;
            }

            $currentBufferSize = $handler->getBufferLength();
            $written = ($initialBufferSize + $bytesToWrite) - $currentBufferSize;

            if ($written >= $bytesToWrite) {
                $promise->resolve($bytesToWrite);
            }
        };

        // If no backpressure, data is buffered immediately
        if ($writeResult) {
            $checkWritten();
        } else {
            // Wait for drain event to resolve
            $drainHandler = function () use ($checkWritten): void {
                $checkWritten();
            };

            $errorHandler = function ($error) use ($promise, &$cancelled, $drainHandler): void {
                // @phpstan-ignore-next-line phpstan doesn't know that $cancelled is mutable
                if ($cancelled) {
                    return;
                }
                $this->stream->removeListener('drain', $drainHandler);
                $promise->reject($error);
            };

            $this->stream->on('drain', $drainHandler);
            $this->stream->on('error', $errorHandler);

            $promise->setCancelHandler(function () use (&$cancelled, $drainHandler, $errorHandler): void {
                $cancelled = true;
                $this->stream->removeListener('drain', $drainHandler);
                $this->stream->removeListener('error', $errorHandler);
            });

            // Check immediately in case drain already happened
            $checkWritten();
        }

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->write($data . "\n");
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): CancellablePromiseInterface
    {
        if ($this->stream->isEnding() || ! $this->stream->isWritable()) {
            return $this->createResolvedVoidPromise();
        }

        /** @var CancellablePromise<void> $promise */
        $promise = new CancellablePromise();
        $cancelled = false;

        $finishHandler = function () use ($promise, &$cancelled): void {
            // @phpstan-ignore-next-line phpstan doesn't know that $cancelled is mutable
            if ($cancelled) {
                return;
            }
            $promise->resolve(null);
        };

        $errorHandler = function ($error) use ($promise, &$cancelled, $finishHandler): void {
            // @phpstan-ignore-next-line phpstan doesn't know that $cancelled is mutable
            if ($cancelled) {
                return;
            }
            $this->stream->removeListener('finish', $finishHandler);
            $promise->reject($error);
        };

        $this->stream->once('finish', $finishHandler);
        $this->stream->on('error', $errorHandler);

        $promise->setCancelHandler(function () use (&$cancelled, $finishHandler, $errorHandler): void {
            $cancelled = true;
            $this->stream->removeListener('finish', $finishHandler);
            $this->stream->removeListener('error', $errorHandler);
        });

        // Call end on the underlying stream
        if ($data !== null && $data !== '') {
            $this->write($data)->then(function () {
                $this->stream->end();
            })->catch(function ($error) use ($promise): void {
                $this->stream->end();
                $promise->reject($error);
            });
        } else {
            $this->stream->end();
        }

        return $promise;
    }

    /**
     * Delegate method calls to the underlying stream.
     *
     * @param array<int, mixed> $arguments
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (method_exists($this->stream, $method)) {
            // @phpstan-ignore-next-line method.dynamicName
            return $this->stream->$method(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist");
    }
}
