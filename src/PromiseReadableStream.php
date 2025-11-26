<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Handlers\ReadAllHandler;
use Hibla\Stream\Handlers\ReadLineHandler;
use Hibla\Stream\Interfaces\PromiseReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\PromiseHelperTrait;

class PromiseReadableStream extends ReadableResourceStream implements PromiseReadableStreamInterface
{
    use PromiseHelperTrait;

    private ReadLineHandler $lineHandler;
    private ReadAllHandler $allHandler;

    /**
     * Creates a promise-based readable stream.
     *
     * @param resource $resource A readable PHP stream resource
     * @param int $chunkSize The default amount of data to read in a single operation
     */
    public function __construct($resource, int $chunkSize = 65536)
    {
        parent::__construct($resource, $chunkSize);
        $this->initializeHandlers();
    }

    /**
     * Create a new instance from a PHP stream resource.
     *
     * @param resource $resource A readable PHP stream resource
     * @param int $chunkSize The default amount of data to read in a single operation
     * @return self
     */
    public static function fromResource($resource, int $chunkSize = 65536): self
    {
        return new self($resource, $chunkSize);
    }

    /**
     * @inheritdoc
     */
    public function readAsync(?int $length = null): CancellablePromiseInterface
    {
        if (! $this->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        if ($this->isEof()) {
            return $this->createResolvedPromise(null);
        }

        /** @var CancellablePromise<string|null> $promise */
        $promise = new CancellablePromise();

        $handler = $this->getHandler();
        $handler->queueRead($length, $promise);

        $promise->setCancelHandler(function () use ($promise, $handler): void {
            $handler->cancelRead($promise);
        });

        if ($this->isPaused()) {
            $this->resume();
        }

        return $promise;
    }

    /**
     * @inheritdoc
     */
    public function readLineAsync(?int $maxLength = null): CancellablePromiseInterface
    {
        if (! $this->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        $handler = $this->getHandler();

        if ($this->isEof() && $handler->getBuffer() === '') {
            return $this->createResolvedPromise(null);
        }

        $maxLen = $maxLength ?? $this->getChunkSize();
        $buffer = $handler->getBuffer();

        $line = $this->lineHandler->findLineInBuffer($buffer, $maxLen);
        if ($line !== null) {
            $handler->setBuffer($buffer);

            return $this->createResolvedPromise($line);
        }

        $handler->clearBuffer();

        return $this->lineHandler->readLineFromStream($buffer, $maxLen);
    }

    /**
     * @inheritdoc
     */
    public function readAllAsync(int $maxLength = 1048576): CancellablePromiseInterface
    {
        if (! $this->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        $handler = $this->getHandler();
        $buffer = $handler->getBuffer();
        $handler->clearBuffer();

        return $this->allHandler->readAll($buffer, $maxLength);
    }

    /**
     * @inheritdoc
     */
    public function pipeAsync(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if (! $this->isReadable()) {
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
                $this->pause();
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

            if ($this->isReadable() && ! $this->isEof()) {
                $hasError = true;
                $promise->reject(new StreamException('Destination closed before transfer completed'));
            }
        };

        // Attach handlers
        $this->on('data', $dataHandler);
        $this->on('end', $endHandler);
        $this->on('error', $errorHandler);
        $destination->on('close', $closeHandler);

        // Handle drain to resume
        $drainHandler = function () use (&$cancelled, &$hasError): void {
            if ($cancelled || $hasError) {
                return;
            }
            $this->resume();
        };
        $destination->on('drain', $drainHandler);

        $promise->setCancelHandler(function () use (&$cancelled, $destination, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, &$drainHandler): void {
            $cancelled = true;
            $this->pause();
            $this->detachPipeHandlers($destination, $dataHandler, $endHandler, $errorHandler, $closeHandler);
            $destination->removeListener('drain', $drainHandler);
        });

        $this->resume();

        return $promise;
    }

    private function initializeHandlers(): void
    {
        $this->lineHandler = new ReadLineHandler(
            fn (?int $length) => $this->readAsync($length),
            fn (string $data) => $this->getHandler()->prependBuffer($data)
        );

        $this->allHandler = new ReadAllHandler(
            $this->getChunkSize(),
            fn (?int $length) => $this->readAsync($length)
        );
    }

    private function detachPipeHandlers(
        WritableStreamInterface $destination,
        ?callable $dataHandler,
        ?callable $endHandler,
        ?callable $errorHandler,
        ?callable $closeHandler
    ): void {
        if ($dataHandler !== null) {
            $this->removeListener('data', $dataHandler);
        }
        if ($endHandler !== null) {
            $this->removeListener('end', $endHandler);
        }
        if ($errorHandler !== null) {
            $this->removeListener('error', $errorHandler);
        }
        if ($closeHandler !== null) {
            $destination->removeListener('close', $closeHandler);
        }
    }
}
