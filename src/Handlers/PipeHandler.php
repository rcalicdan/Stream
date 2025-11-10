<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\WritableStreamInterface;

class PipeHandler
{
    private $onCallback;
    private $offCallback;
    private $emitCallback;
    private $pauseCallback;
    private $resumeCallback;
    private $isReadableCallback;
    private $isEofCallback;

    public function __construct(
        callable $onCallback,
        callable $offCallback,
        callable $emitCallback,
        callable $pauseCallback,
        callable $resumeCallback,
        callable $isReadableCallback,
        callable $isEofCallback
    ) {
        $this->onCallback = $onCallback;
        $this->offCallback = $offCallback;
        $this->emitCallback = $emitCallback;
        $this->pauseCallback = $pauseCallback;
        $this->resumeCallback = $resumeCallback;
        $this->isReadableCallback = $isReadableCallback;
        $this->isEofCallback = $isEofCallback;
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        $endDestination = $options['end'] ?? true;
        $totalBytes = 0;
        $cancelled = false;
        $pendingWriteCount = 0;
        $hasError = false;

        $promise = new CancellablePromise();

        $handlers = $this->createHandlers(
            $destination,
            $endDestination,
            $promise,
            $totalBytes,
            $cancelled,
            $pendingWriteCount,
            $hasError
        );

        $this->attachHandlers($destination, $handlers);

        $promise->setCancelHandler(function () use (&$cancelled, $handlers, $destination) {
            $cancelled = true;
            ($this->pauseCallback)();
            $this->detachHandlers($destination, $handlers);
        });

        ($this->resumeCallback)();

        return $promise;
    }

    private function createHandlers(
        WritableStreamInterface $destination,
        bool $endDestination,
        CancellablePromiseInterface $promise,
        int &$totalBytes,
        bool &$cancelled,
        int &$pendingWriteCount,
        bool &$hasError
    ): array {
        $dataHandler = function ($data) use ($destination, &$totalBytes, &$cancelled, &$pendingWriteCount, &$hasError) {
            if ($cancelled || $hasError) {
                return;
            }

            $pendingWriteCount++;
            $writePromise = $destination->write($data);

            $writePromise->then(function ($bytes) use (&$totalBytes, &$pendingWriteCount) {
                $totalBytes += $bytes;
                $pendingWriteCount--;
            })->catch(function ($error) use (&$cancelled, &$pendingWriteCount, &$hasError) {
                $pendingWriteCount--;
                if (! $cancelled && ! $hasError) {
                    $hasError = true;
                    ($this->emitCallback)('error', $error);
                }
            });
        };

        $endHandler = function () use ($promise, $destination, $endDestination, &$totalBytes, &$cancelled, &$pendingWriteCount, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler) {
            if ($cancelled || $hasError) {
                return;
            }

            $this->detachHandlers($destination, [
                'data' => $dataHandler,
                'end' => $endHandler,
                'error' => $errorHandler,
                'close' => $closeHandler,
            ]);

            $this->waitForPendingWrites($pendingWriteCount, $hasError, function () use ($promise, $destination, $endDestination, &$totalBytes) {
                if ($endDestination) {
                    $destination->end()->then(function () use ($promise, &$totalBytes) {
                        $promise->resolve($totalBytes);
                    })->catch(function ($error) use ($promise, &$totalBytes) {
                        $promise->resolve($totalBytes);
                    });
                } else {
                    $promise->resolve($totalBytes);
                }
            });
        };

        $errorHandler = function ($error) use ($promise, $destination, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler) {
            if ($cancelled || $hasError) {
                return;
            }

            $hasError = true;
            $this->detachHandlers($destination, [
                'data' => $dataHandler,
                'end' => $endHandler,
                'error' => $errorHandler,
                'close' => $closeHandler,
            ]);
            $promise->reject($error);
        };

        $closeHandler = function () use ($promise, $destination, &$cancelled, &$hasError, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler) {
            if ($cancelled || $hasError) {
                return;
            }

            $this->detachHandlers($destination, [
                'data' => $dataHandler,
                'end' => $endHandler,
                'error' => $errorHandler,
                'close' => $closeHandler,
            ]);

            if (($this->isReadableCallback)() && ! ($this->isEofCallback)()) {
                $hasError = true;
                $promise->reject(new StreamException('Destination closed before transfer completed'));
            }
        };

        return [
            'data' => $dataHandler,
            'end' => $endHandler,
            'error' => $errorHandler,
            'close' => $closeHandler,
        ];
    }

    private function attachHandlers(WritableStreamInterface $destination, array $handlers): void
    {
        ($this->onCallback)('data', $handlers['data']);
        ($this->onCallback)('end', $handlers['end']);
        ($this->onCallback)('error', $handlers['error']);
        $destination->on('close', $handlers['close']);
    }

    private function detachHandlers(WritableStreamInterface $destination, array $handlers): void
    {
        ($this->offCallback)('data', $handlers['data']);
        ($this->offCallback)('end', $handlers['end']);
        ($this->offCallback)('error', $handlers['error']);
        $destination->off('close', $handlers['close']);
    }

    private function waitForPendingWrites(int &$pendingWriteCount, bool &$hasError, callable $onComplete): void
    {
        $checkPending = function () use (&$pendingWriteCount, &$hasError, $onComplete, &$checkPending) {
            if ($hasError) {
                return;
            }

            if ($pendingWriteCount > 0) {
                Loop::defer($checkPending);

                return;
            }

            $onComplete();
        };

        $checkPending();
    }
}
