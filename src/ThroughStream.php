<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;
use Hibla\Stream\Traits\PromiseHelperTrait;

class ThroughStream implements DuplexStreamInterface
{
    use EventEmitterTrait;
    use PromiseHelperTrait;

    private bool $readable = true;
    private bool $writable = true;
    private bool $closed = false;
    private bool $paused = false;
    private bool $ending = false;
    private bool $draining = false;

    /**
     * Initializes the transform stream with an optional callback.
     * If provided, this callback will be applied to every chunk of data written to the stream before it is emitted.
     *
     * @param callable(string): string|null $transformer A function to process each data chunk.
     */
    public function __construct(
        private $transformer = null
    ) {
    }

    /**
     * This operation is not supported on a ThroughStream.
     * A ThroughStream does not have an underlying resource to actively read from; it only emits data
     * that has been written to it. To consume data, pipe this stream to a writable destination
     * or listen for the 'data' event.
     *
     * @throws StreamException Always.
     */
    public function read(?int $length = null): CancellablePromiseInterface
    {
        return $this->createRejectedPromise(
            new StreamException('ThroughStream does not support read() method. Use pipe() or event listeners.')
        );
    }

    /**
     * This operation is not supported on a ThroughStream.
     * A ThroughStream does not have an underlying resource to actively read from; it only emits data
     * that has been written to it. To consume data, pipe this stream to a writable destination
     * or listen for the 'data' event.
     *
     * @throws StreamException Always.
     */
    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        return $this->createRejectedPromise(
            new StreamException('ThroughStream does not support readLine() method. Use pipe() or event listeners.')
        );
    }

    /**
     * This operation is not supported on a ThroughStream.
     * A ThroughStream does not have an underlying resource to actively read from; it only emits data
     * that has been written to it. To consume data, pipe this stream to a writable destination
     * or listen for the 'data' event.
     *
     * @throws StreamException Always.
     */
    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        return $this->createRejectedPromise(
            new StreamException('ThroughStream does not support readAll() method. Use pipe() or event listeners.')
        );
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if (! $this->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        if (! $destination->isWritable()) {
            $this->pause();

            return $this->createRejectedPromise(new StreamException('Destination is not writable'));
        }

        $endDestination = $options['end'] ?? true;
        $totalBytes = 0;
        $cancelled = false;

        /** @var CancellablePromise<int> $promise */
        $promise = new CancellablePromise();

        $dataHandler = function (string $data) use ($destination, &$totalBytes, &$cancelled): void {
            /** @phpstan-ignore if.alwaysFalse */
            if ($cancelled) {
                return;
            }

            $destination->write($data)->then(function (int $bytes) use (&$totalBytes): void {
                $totalBytes += $bytes;
            });
        };

        $endHandler = function () use (
            $promise,
            $destination,
            $endDestination,
            &$totalBytes,
            &$cancelled,
            &$dataHandler,
            &$endHandler,
            &$errorHandler,
        ): void {
            /** @phpstan-ignore if.alwaysFalse */
            if ($cancelled) {
                return;
            }

            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            /** @phpstan-ignore argument.type */
            $this->off('error', $errorHandler);

            if ($endDestination) {
                $destination->end()->then(function () use ($promise, &$totalBytes): void {
                    $promise->resolve($totalBytes);
                })->catch(function () use ($promise, &$totalBytes): void {
                    $promise->resolve($totalBytes);
                });
            } else {
                $promise->resolve($totalBytes);
            }
        };

        $errorHandler = function (\Throwable $error) use ($promise, &$cancelled, &$dataHandler, &$endHandler, &$errorHandler): void {
            /** @phpstan-ignore if.alwaysFalse */
            if ($cancelled) {
                return;
            }

            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);

            $promise->reject($error);
        };

        $this->on('data', $dataHandler);
        $this->on('end', $endHandler);
        $this->on('error', $errorHandler);

        $promise->setCancelHandler(function () use (&$cancelled, &$dataHandler, &$endHandler, &$errorHandler): void {
            $cancelled = true;
            $this->pause();
            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);
        });

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function write(string $data): CancellablePromiseInterface
    {
        if (! $this->isWritable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not writable'));
        }

        /** @var CancellablePromise<int> $promise */
        $promise = new CancellablePromise();

        try {
            $transformedData = $data;
            if ($this->transformer !== null) {
                $transformedData = ($this->transformer)($data);
            }

            $this->emit('data', $transformedData);

            if ($this->paused) {
                $this->draining = true;
                $promise->resolve(0);
            } else {
                $promise->resolve(strlen($transformedData));
            }
        } catch (\Throwable $e) {
            $this->emit('error', $e);
            $this->close();
            $promise->reject($e);
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
        if (! $this->isWritable() || $this->ending) {
            return $this->createResolvedVoidPromise();
        }

        $this->ending = true;

        /** @var CancellablePromise<void> $promise */
        $promise = new CancellablePromise();

        try {
            if ($data !== null && $data !== '') {
                $transformedData = $data;
                if ($this->transformer !== null) {
                    $transformedData = ($this->transformer)($data);
                }

                $this->emit('data', $transformedData);
            }

            $this->writable = false;
            $this->readable = false;
            $this->emit('end');
            $this->emit('finish');
            $this->close();
            $promise->resolve(null);
        } catch (\Throwable $e) {
            $this->writable = false;
            $this->readable = false;
            $this->emit('error', $e);
            $this->close();
            $promise->reject($e);
        }

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function pause(): void
    {
        if (! $this->readable || $this->paused) {
            return;
        }

        $this->paused = true;
        $this->emit('pause');
    }

    /**
     * @inheritdoc
     */
    public function resume(): void
    {
        if (! $this->readable || ! $this->paused) {
            return;
        }

        $this->paused = false;
        $this->emit('resume');

        if ($this->draining) {
            $this->draining = false;
            $this->emit('drain');
        }
    }

    /**
     * @inheritdoc
     */
    public function isReadable(): bool
    {
        return $this->readable && ! $this->closed;
    }

    /**
     * @inheritdoc
     */
    public function isWritable(): bool
    {
        return $this->writable && ! $this->closed;
    }

    /**
     * @inheritdoc
     */
    public function isEnding(): bool
    {
        return $this->ending;
    }

    /**
     * @inheritdoc
     */
    public function isEof(): bool
    {
        return ! $this->readable;
    }

    /**
     * @inheritdoc
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->readable = false;
        $this->writable = false;
        $this->paused = false;
        $this->transformer = null;

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}
