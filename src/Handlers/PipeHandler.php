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
    /**
     * @param callable(string, callable): void $onCallback
     * @param callable(string, callable): void $offCallback
     * @param callable(string, mixed): void $emitCallback
     * @param callable(): void $pauseCallback
     * @param callable(): void $resumeCallback
     * @param callable(): bool $isReadableCallback
     * @param callable(): bool $isEofCallback
     */
    public function __construct(
        private $onCallback,
        private $offCallback,
        private $emitCallback,
        private $pauseCallback,
        private $resumeCallback,
        private $isReadableCallback,
        private $isEofCallback
    ) {
    }

    /**
     * @param array{end?: bool} $options
     * @return CancellablePromiseInterface<int>
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        $endDestination = (bool) ($options['end'] ?? true);
        $totalBytes = 0;
        $cancelled = false;
        $pendingWriteCount = 0;
        $hasError = false;

        /** @var CancellablePromise<int> $promise */
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

    /**
     * @param CancellablePromiseInterface<int> $promise
     * @return array{data: callable, end: callable, error: callable, close: callable}
     */
    private function createHandlers(
        WritableStreamInterface $destination,
        bool $endDestination,
        CancellablePromiseInterface $promise,
        int &$totalBytes,
        bool &$cancelled,
        int &$pendingWriteCount,
        bool &$hasError
    ): array {
        $handlers = [
            'data' => null,
            'end' => null,
            'error' => null,
            'close' => null,
        ];

        $handlers['data'] = function (string $data) use ($destination, &$totalBytes, &$cancelled, &$pendingWriteCount, &$hasError) {
            if ($cancelled || $hasError) {
                return;
            }

            $pendingWriteCount++;
            $writePromise = $destination->write($data);

            $writePromise->then(function ($bytes) use (&$totalBytes, &$pendingWriteCount) {
                $totalBytes += $bytes;
                $pendingWriteCount--;
            })->catch(function ($error) use (&$pendingWriteCount, &$hasError) {
                $pendingWriteCount--;
                if ($hasError) {
                    return;
                }
                $hasError = true;
                ($this->emitCallback)('error', $error);
            });
        };

        $handlers['end'] = function () use ($promise, $destination, $endDestination, &$totalBytes, &$cancelled, &$pendingWriteCount, &$hasError, &$handlers) {
            if ($cancelled || $hasError) {
                return;
            }

            /** @phpstan-ignore argument.type */
            $this->detachHandlers($destination, $handlers);

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

        $handlers['error'] = function ($error) use ($promise, $destination, &$cancelled, &$hasError, &$handlers) {
            if ($cancelled || $hasError) {
                return;
            }

            $hasError = true;
            /** @phpstan-ignore argument.type */
            $this->detachHandlers($destination, $handlers);
            $promise->reject($error);
        };

        $handlers['close'] = function () use ($promise, $destination, &$cancelled, &$hasError, &$handlers) {
            if ($cancelled || $hasError) {
                return;
            }

            /** @phpstan-ignore argument.type */
            $this->detachHandlers($destination, $handlers);

            if (($this->isReadableCallback)() && ! ($this->isEofCallback)()) {
                $hasError = true;
                $promise->reject(new StreamException('Destination closed before transfer completed'));
            }
        };

        return $handlers;
    }

    /**
     * @param array{data: callable, end: callable, error: callable, close: callable} $handlers
     */
    private function attachHandlers(WritableStreamInterface $destination, array $handlers): void
    {
        ($this->onCallback)('data', $handlers['data']);
        ($this->onCallback)('end', $handlers['end']);
        ($this->onCallback)('error', $handlers['error']);
        $destination->on('close', $handlers['close']);
    }

    /**
     * @param array{data: callable, end: callable, error: callable, close: callable} $handlers
     */
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