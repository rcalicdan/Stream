<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\PromiseWritableStreamInterface;
use Hibla\Stream\Traits\PromiseHelperTrait;

class PromiseWritableStream extends WritableResourceStream implements PromiseWritableStreamInterface
{
    use PromiseHelperTrait;

    /**
     * Creates a promise-based writable stream.
     *
     * @param resource $resource A writable PHP stream resource
     * @param int $softLimit The size of the write buffer (in bytes) at which backpressure is applied
     */
    public function __construct($resource, int $softLimit = 65536)
    {
        parent::__construct($resource, $softLimit);
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
        return new self($resource, $softLimit);
    }

    /**
     * @inheritdoc
     */
    public function writeAsync(string $data): CancellablePromiseInterface
    {
        if (!$this->isWritable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not writable'));
        }

        if ($data === '') {
            return $this->createResolvedPromise(0);
        }

        /** @var CancellablePromise<int> $promise */
        $promise = new CancellablePromise();
        $bytesToWrite = \strlen($data);
        $cancelled = false;

        $handler = $this->getHandler();

        // Write the data first using parent's method
        $writeResult = parent::write($data);

        // Get the buffer size AFTER writing
        $bufferSizeAfterWrite = $handler->getBufferLength();

        // Set up promise resolution
        $checkWritten = function () use ($promise, $handler, $bufferSizeAfterWrite, $bytesToWrite, &$cancelled): void {
            if ($cancelled) {
                return;
            }

            $currentBufferSize = $handler->getBufferLength();
            $written = $bufferSizeAfterWrite - $currentBufferSize;

            if ($written >= $bytesToWrite) {
                $promise->resolve($bytesToWrite);
            }
        };

        // Always wait for drain event - data needs to be flushed to disk
        $drainHandler = function () use ($checkWritten, &$drainHandler, &$errorHandler): void {
            $checkWritten();

            // Clean up listeners after checking
            $this->removeListener('drain', $drainHandler);
            $this->removeListener('error', $errorHandler);
        };

        $errorHandler = function ($error) use ($promise, &$cancelled, &$drainHandler, &$errorHandler): void {
            if ($cancelled) {
                return;
            }
            $this->removeListener('drain', $drainHandler);
            $this->removeListener('error', $errorHandler);
            $promise->reject($error);
        };

        $this->on('drain', $drainHandler);
        $this->on('error', $errorHandler);

        $promise->setCancelHandler(function () use (&$cancelled, &$drainHandler, &$errorHandler): void {
            $cancelled = true;
            $this->removeListener('drain', $drainHandler);
            $this->removeListener('error', $errorHandler);
        });

        // Check immediately in case drain already happened (buffer already empty)
        if ($handler->isFullyDrained()) {
            $promise->resolve($bytesToWrite);
            $this->removeListener('drain', $drainHandler);
            $this->removeListener('error', $errorHandler);
        }

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function writeLineAsync(string $data): CancellablePromiseInterface
    {
        return $this->writeAsync($data . "\n");
    }

    /**
     * @inheritdoc
     */
    public function endAsync(?string $data = null): CancellablePromiseInterface
    {
        if ($this->isEnding() || !$this->isWritable()) {
            return $this->createResolvedVoidPromise();
        }

        /** @var CancellablePromise<void> $promise */
        $promise = new CancellablePromise();
        $cancelled = false;

        $finishHandler = function () use ($promise, &$cancelled): void {
            if ($cancelled) {
                return;
            }
            $promise->resolve(null);
        };

        $errorHandler = function ($error) use ($promise, &$cancelled, $finishHandler): void {
            if ($cancelled) {
                return;
            }
            $this->removeListener('finish', $finishHandler);
            $promise->reject($error);
        };

        $this->once('finish', $finishHandler);
        $this->on('error', $errorHandler);

        $promise->setCancelHandler(function () use (&$cancelled, $finishHandler, $errorHandler): void {
            $cancelled = true;
            $this->removeListener('finish', $finishHandler);
            $this->removeListener('error', $errorHandler);
        });

        // Call end on parent
        if ($data !== null && $data !== '') {
            $this->writeAsync($data)->then(function () {
                parent::end();
            })->catch(function ($error) use ($promise): void {
                parent::end();
                $promise->reject($error);
            });
        } else {
            parent::end();
        }

        return $promise;
    }
}