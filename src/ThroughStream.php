<?php

namespace Hibla\Stream;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;
use Hibla\Stream\Traits\PromiseHelperTrait;

/**
 * ThroughStream - A transform stream that processes data through a callback
 */
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
        return $this->createRejectedPromise(
            new StreamException('ThroughStream does not support read() method. Use event listeners.')
        );
    }

    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        return $this->createRejectedPromise(
            new StreamException('ThroughStream does not support readLine() method. Use event listeners.')
        );
    }

    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        return $this->createRejectedPromise(
            new StreamException('ThroughStream does not support readAll() method. Use event listeners.')
        );
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if (!$this->isReadable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not readable'));
        }

        if (!$destination->isWritable()) {
            $this->pause();
            return $this->createRejectedPromise(new StreamException('Destination is not writable'));
        }

        $endDestination = $options['end'] ?? true;
        $totalBytes = 0;
        $cancelled = false;

        $promise = new CancellablePromise();

        $dataHandler = function ($data) use ($destination, &$totalBytes, &$cancelled) {
            if ($cancelled) {
                return;
            }

            $destination->write($data)->then(function ($bytes) use (&$totalBytes) {
                $totalBytes += $bytes;
            });
        };

        $endHandler = function () use ($promise, $destination, $endDestination, &$totalBytes, &$cancelled, &$dataHandler, &$endHandler, &$errorHandler) {
            if ($cancelled) {
                return;
            }

            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);

            if ($endDestination) {
                $destination->end()->then(function () use ($promise, &$totalBytes) {
                    $promise->resolve($totalBytes);
                })->catch(function () use ($promise, &$totalBytes) {
                    $promise->resolve($totalBytes);
                });
            } else {
                $promise->resolve($totalBytes);
            }
        };

        $errorHandler = function ($error) use ($promise, &$cancelled, &$dataHandler, &$endHandler, &$errorHandler) {
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

        $promise->setCancelHandler(function () use (&$cancelled, &$dataHandler, &$endHandler, &$errorHandler) {
            $cancelled = true;
            $this->pause();
            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);
        });

        return $promise;
    }

    public function write(string $data): CancellablePromiseInterface
    {
        if (!$this->isWritable()) {
            return $this->createRejectedPromise(new StreamException('Stream is not writable'));
        }

        $promise = new CancellablePromise();

        try {
            if ($this->transformer !== null) {
                $data = ($this->transformer)($data);
            }

            $this->emit('data', $data);

            if ($this->paused) {
                $this->draining = true;
                $promise->resolve(0);
            } else {
                $promise->resolve(strlen($data));
            }
        } catch (\Throwable $e) {
            $this->emit('error', $e);
            $this->close();
            $promise->reject($e);
        }

        return $promise;
    }

    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->write($data . "\n");
    }

    public function end(?string $data = null): CancellablePromiseInterface
    {
        if (!$this->isWritable() || $this->ending) {
            return $this->createResolvedPromise(null);
        }

        $this->ending = true;
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

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }
}
