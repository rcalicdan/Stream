<?php

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;

/**
 * ThroughStream - A transform stream that processes data through a callback
 */
class ThroughStream implements DuplexStreamInterface
{
    use EventEmitterTrait;

    private bool $readable = true;
    private bool $writable = true;
    private bool $closed = false;
    private bool $paused = false;
    private bool $ending = false;
    private bool $draining = false;

    /** @var callable|null */
    private $transformer;

    /**
     * @param callable|null $transformer Function to transform data: fn(string $data): string
     */
    public function __construct(?callable $transformer = null)
    {
        $this->transformer = $transformer;
    }

    public function read(?int $length = null): CancellablePromiseInterface
    {
        return $this->createRejectedCancellable(
            new StreamException('ThroughStream does not support read() method. Use event listeners.')
        );
    }

    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        return $this->createRejectedCancellable(
            new StreamException('ThroughStream does not support readLine() method. Use event listeners.')
        );
    }

    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        return $this->createRejectedCancellable(
            new StreamException('ThroughStream does not support readAll() method. Use event listeners.')
        );
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if (!$this->isReadable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not readable'));
        }

        if (!$destination->isWritable()) {
            $this->pause();
            return $this->createRejectedCancellable(new StreamException('Destination is not writable'));
        }

        $endDestination = $options['end'] ?? true;
        $totalBytes = 0;

        $promise = new CancellablePromise(function ($resolve, $reject) use ($destination, $endDestination, &$totalBytes) {
            $cancelled = false;

            $dataHandler = function ($data) use ($destination, &$totalBytes, &$cancelled) {
                if ($cancelled) {
                    return;
                }

                $destination->write($data)->then(function ($bytes) use (&$totalBytes) {
                    $totalBytes += $bytes;
                });
            };

            $endHandler = function () use ($destination, $endDestination, $resolve, &$totalBytes, &$cancelled) {
                if ($cancelled) {
                    return;
                }

                if ($endDestination) {
                    $destination->end()->then(function () use ($resolve, &$totalBytes) {
                        $resolve($totalBytes);
                    });
                } else {
                    $resolve($totalBytes);
                }
            };

            $errorHandler = function ($error) use ($reject, &$cancelled) {
                if (!$cancelled) {
                    $reject($error);
                }
            };

            $this->on('data', $dataHandler);
            $this->on('end', $endHandler);
            $this->on('error', $errorHandler);
        });

        $promise->setCancelHandler(function () {
            $this->pause();
        });

        return $promise;
    }

    public function write(string $data): CancellablePromiseInterface
    {
        if (!$this->isWritable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not writable'));
        }

        $promise = new CancellablePromise(function ($resolve, $reject) use ($data) {
            try {
                // Transform data if transformer is set
                if ($this->transformer !== null) {
                    $data = ($this->transformer)($data);
                }

                // Emit the data
                $this->emit('data', $data);

                // Return indicating if we can continue writing
                if ($this->paused) {
                    $this->draining = true;
                    $resolve(0); // Indicate backpressure
                } else {
                    $resolve(strlen($data));
                }
            } catch (\Throwable $e) {
                $this->emit('error', $e);
                $this->close();
                $reject($e);
            }
        });

        return $promise;
    }

    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->write($data . "\n");
    }

    public function end(?string $data = null): CancellablePromiseInterface
    {
        if (!$this->isWritable() || $this->ending) {
            return $this->createResolvedCancellable(null);
        }

        $this->ending = true;
        $this->writable = false;

        $promise = new CancellablePromise(function ($resolve, $reject) use ($data) {
            if ($data !== null && $data !== '') {
                $this->write($data)->then(function () use ($resolve) {
                    $this->readable = false;
                    $this->emit('end');
                    $this->emit('finish');
                    $this->close();
                    $resolve(null);
                })->catch(function ($error) use ($reject) {
                    $this->emit('error', $error);
                    $this->close();
                    $reject($error);
                });
            } else {
                $this->readable = false;
                $this->emit('end');
                $this->emit('finish');
                $this->close();
                $resolve(null);
            }
        });

        return $promise;
    }

    public function pause(): void
    {
        if (!$this->readable || $this->paused) {
            return;
        }

        $this->paused = true;
        $this->emit('pause');
    }

    public function resume(): void
    {
        if (!$this->readable || !$this->paused) {
            return;
        }

        $this->paused = false;
        $this->emit('resume');

        if ($this->draining) {
            $this->draining = false;
            $this->emit('drain');
        }
    }

    public function isReadable(): bool
    {
        return $this->readable && !$this->closed;
    }

    public function isWritable(): bool
    {
        return $this->writable && !$this->closed;
    }

    public function isEnding(): bool
    {
        return $this->ending;
    }

    public function isEof(): bool
    {
        return !$this->readable;
    }

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

    private function createResolvedCancellable(mixed $value): CancellablePromiseInterface
    {
        $promise = new CancellablePromise(function ($resolve) use ($value) {
            $resolve($value);
        });

        return $promise;
    }

    private function createRejectedCancellable(\Throwable $reason): CancellablePromiseInterface
    {
        $promise = new CancellablePromise(function ($resolve, $reject) use ($reason) {
            $reject($reason);
        });

        return $promise;
    }
}