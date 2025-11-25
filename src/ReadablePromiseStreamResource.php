<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Handlers\ReadAllHandler;
use Hibla\Stream\Handlers\ReadLineHandler;
use Hibla\Stream\Interfaces\ReadablePromiseStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\PromiseHelperTrait;

class ReadablePromiseStreamResource implements ReadablePromiseStreamInterface
{
    use PromiseHelperTrait;

    private ReadableResourceStream $stream;
    private ReadLineHandler $lineHandler;
    private ReadAllHandler $allHandler;

    /**
     * Creates a promise-based wrapper around a ReadableStreamResource.
     *
     * @param ReadableResourceStream $stream The underlying stream resource
     */
    public function __construct(ReadableResourceStream $stream)
    {
        $this->stream = $stream;
        $this->initializeHandlers();
    }

    /**
     * Create a new instance from a PHP stream resource.
     *
     * @param resource $resource A readable PHP stream resource
     * @param int $chunkSize The default amount of data to read in a single operation
     * @return static
     */
    public static function fromResource($resource, int $chunkSize = 65536): static
    {
        return new static(new ReadableResourceStream($resource, $chunkSize));
    }

    /**
     * Get the underlying stream resource.
     */
    public function getStream(): ReadableResourceStream
    {
        return $this->stream;
    }

    /**
     * @inheritdoc
     */
    public function read(?int $length = null): CancellablePromiseInterface
    {
        if (! $this->stream->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        if ($this->stream->isEof()) {
            //@phpstan-ignore-next-line
            return $this->createResolvedPromise(null);
        }

        /** @var CancellablePromise<string|null> $promise */
        $promise = new CancellablePromise();

        $handler = $this->stream->getHandler();
        $handler->queueRead($length, $promise);

        $promise->setCancelHandler(function () use ($promise, $handler): void {
            $handler->cancelRead($promise);
        });

        if ($this->stream->isPaused()) {
            $this->stream->resume();
        }

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        if (! $this->stream->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        $handler = $this->stream->getHandler();

        if ($this->stream->isEof() && $handler->getBuffer() === '') {
            //@phpstan-ignore-next-line
            return $this->createResolvedPromise(null);
        }

        $maxLen = $maxLength ?? $this->stream->getChunkSize();
        $buffer = $handler->getBuffer();

        $line = $this->lineHandler->findLineInBuffer($buffer, $maxLen);
        if ($line !== null) {
            $handler->setBuffer($buffer);

            //@phpstan-ignore-next-line
            return $this->createResolvedPromise($line);
        }

        $handler->clearBuffer();

        return $this->lineHandler->readLineFromStream($buffer, $maxLen);
    }

    /**
     * @inheritdoc
     */
    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        if (! $this->stream->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        $handler = $this->stream->getHandler();
        $buffer = $handler->getBuffer();
        $handler->clearBuffer();

        return $this->allHandler->readAll($buffer, $maxLength);
    }

    /**
     * @inheritdoc
     */
    public function pipeWithPromise(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if (! $this->stream->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        if (! $destination->isWritable()) {
            return $this->createRejectedPromise(new StreamException('Destination is not writable'));
        }

        $endDestination = (bool) ($options['end'] ?? true);
        $totalBytes = 0;
        $cancelled = false;
        $hasError = false;

        /** @var CancellablePromise<int> $promise */
        $promise = new CancellablePromise();

        // Track data being written
        $dataHandler = function (string $data) use ($destination, &$totalBytes, &$cancelled, &$hasError): void {
            if ($cancelled || $hasError) {
                return;
            }

            $feedMore = $destination->write($data);
            $totalBytes += strlen($data);

            if (false === $feedMore) {
                $this->stream->pause();
            }
        };

        // When source ends
        $endHandler = function () use ($promise, $destination, $endDestination, &$totalBytes, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler): void {
            if ($cancelled || $hasError) {
                return;
            }

            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);

            if ($endDestination) {
                $destination->end();
                $destination->once('finish', function () use ($promise, &$totalBytes): void {
                    $promise->resolve($totalBytes);
                });
            } else {
                $promise->resolve($totalBytes);
            }
        };

        // When source errors
        $errorHandler = function ($error) use ($promise, $destination, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler): void {
            if ($cancelled || $hasError) {
                return;
            }

            $hasError = true;
            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            $promise->reject($error);
        };

        // When destination closes
        $closeHandler = function () use ($promise, $destination, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler): void {
            if ($cancelled || $hasError) {
                return;
            }

            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);

            if ($this->stream->isReadable() && ! $this->stream->isEof()) {
                $hasError = true;
                $promise->reject(new StreamException('Destination closed before transfer completed'));
            }
        };

        // Attach handlers
        $this->stream->on('data', $dataHandler);
        $this->stream->on('end', $endHandler);
        $this->stream->on('error', $errorHandler);
        $destination->on('close', $closeHandler);

        // Handle drain to resume
        $drainHandler = function () use (&$cancelled, &$hasError): void {
            if ($cancelled || $hasError) {
                return;
            }
            $this->stream->resume();
        };
        $destination->on('drain', $drainHandler);

        $promise->setCancelHandler(function () use (&$cancelled, $destination, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, &$drainHandler): void {
            $cancelled = true;
            $this->stream->pause();
            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            $destination->removeListener('drain', $drainHandler);
        });

        $this->stream->resume();

        return $promise;
    }

    /**
     * Delegate method calls to the underlying stream.
     */
    public function __call(string $method, array $arguments): mixed
    {
        if (method_exists($this->stream, $method)) {
            return $this->stream->$method(...$arguments);
        }

        throw new \BadMethodCallException("Method {$method} does not exist");
    }

    private function initializeHandlers(): void
    {
        $this->lineHandler = new ReadLineHandler(
            fn (?int $length) => $this->read($length),
            fn (string $data) => $this->stream->getHandler()->prependBuffer($data)
        );

        $this->allHandler = new ReadAllHandler(
            $this->stream->getChunkSize(),
            fn (?int $length) => $this->read($length)
        );
    }

    private function detachPipeHandlers(
        WritableStreamInterface $destination,
        callable $dataHandler,
        callable $endHandler,
        callable $errorHandler,
        callable $closeHandler
    ): void {
        $this->stream->removeListener('data', $dataHandler);
        $this->stream->removeListener('end', $endHandler);
        $this->stream->removeListener('error', $errorHandler);
        $destination->removeListener('close', $closeHandler);
    }
}
