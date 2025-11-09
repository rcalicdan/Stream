<?php

namespace Hibla\Stream;

use Hibla\EventLoop\Loop;
use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;

class ReadableStream implements ReadableStreamInterface
{
    use EventEmitterTrait;

    /** @var resource|null */
    private $resource;

    private bool $readable = true;
    private bool $paused = true;
    private bool $closed = false;
    private bool $eof = false;
    private ?string $watcherId = null;
    private int $chunkSize;
    private string $buffer = '';

    /** @var array<array{resolve: callable, reject: callable, length: ?int, promise: CancellablePromiseInterface}> */
    private array $readQueue = [];

    /**
     * @param resource $resource Stream resource
     * @param int $chunkSize Default chunk size for reads
     */
    public function __construct($resource, int $chunkSize = 8192)
    {
        if (!is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        $meta = stream_get_meta_data($resource);
        if (!str_contains($meta['mode'], 'r') && !str_contains($meta['mode'], '+')) {
            throw new StreamException('Resource is not readable');
        }

        $this->resource = $resource;
        $this->chunkSize = $chunkSize;

        $streamType = $meta['stream_type'] ?? '';
        $isWindows = DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS, 'WIN') === 0;

        $shouldSetNonBlocking = false;

        if (in_array($streamType, ['tcp_socket', 'udp_socket', 'unix_socket', 'ssl_socket', 'TCP/IP', 'tcp_socket/ssl'])) {
            $shouldSetNonBlocking = true;
        } elseif (!$isWindows && in_array($streamType, ['STDIO', 'PLAINFILE', 'TEMP', 'MEMORY'])) {
            $shouldSetNonBlocking = true;
        }

        if ($shouldSetNonBlocking) {
            @stream_set_blocking($resource, false);
            @stream_set_read_buffer($resource, 0);
        }
    }

    public function read(?int $length = null): CancellablePromiseInterface
    {
        if (!$this->isReadable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not readable'));
        }

        if ($this->eof) {
            return $this->createResolvedCancellable(null);
        }

        $promise = new CancellablePromise();

        $queueItem = [
            'resolve' => fn($value) => $promise->resolve($value),
            'reject' => fn($reason) => $promise->reject($reason),
            'length' => $length ?? $this->chunkSize,
            'promise' => $promise,
        ];

        $this->readQueue[] = $queueItem;

        // Set cancel handler
        $promise->setCancelHandler(function () use ($promise) {
            $this->cancelRead($promise);
        });

        // Start reading if not already
        if ($this->paused) {
            $this->resume();
        }

        return $promise;
    }

    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        if (!$this->isReadable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not readable'));
        }

        if ($this->eof && empty($this->buffer)) {
            return $this->createResolvedCancellable(null);
        }

        $maxLen = $maxLength ?? $this->chunkSize;
        $promise = new CancellablePromise();

        // Check if we already have a line in buffer
        $newlinePos = strpos($this->buffer, "\n");
        if ($newlinePos !== false) {
            $line = substr($this->buffer, 0, $newlinePos + 1);
            $this->buffer = substr($this->buffer, $newlinePos + 1);
            $promise->resolve($line);
            return $promise;
        }

        // Check if buffer exceeds max length
        if (strlen($this->buffer) >= $maxLen) {
            $line = substr($this->buffer, 0, $maxLen);
            $this->buffer = substr($this->buffer, $maxLen);
            $promise->resolve($line);
            return $promise;
        }

        // Need to read more data
        $lineBuffer = $this->buffer;
        $this->buffer = '';
        $cancelled = false;

        $readMore = function () use ($promise, $maxLen, &$lineBuffer, &$readMore, &$cancelled) {
            if ($cancelled) {
                return;
            }

            $this->read(1024)->then(function ($data) use ($promise, $maxLen, &$lineBuffer, &$readMore, &$cancelled) {
                if ($cancelled) {
                    return;
                }

                if ($data === null) {
                    // EOF reached
                    $promise->resolve($lineBuffer === '' ? null : $lineBuffer);
                    return;
                }

                $lineBuffer .= $data;

                // Check for newline
                $newlinePos = strpos($lineBuffer, "\n");
                if ($newlinePos !== false) {
                    $line = substr($lineBuffer, 0, $newlinePos + 1);
                    $remaining = substr($lineBuffer, $newlinePos + 1);
                    $this->buffer = $remaining . $this->buffer;
                    $promise->resolve($line);
                    return;
                }

                // Check if exceeded max length
                if (strlen($lineBuffer) >= $maxLen) {
                    $line = substr($lineBuffer, 0, $maxLen);
                    $remaining = substr($lineBuffer, $maxLen);
                    $this->buffer = $remaining . $this->buffer;
                    $promise->resolve($line);
                    return;
                }

                // Continue reading
                $readMore();
            })->catch(function ($error) use ($promise, &$cancelled) {
                if (!$cancelled) {
                    $promise->reject($error);
                }
            });
        };

        $promise->setCancelHandler(function () use (&$cancelled) {
            $cancelled = true;
        });

        $readMore();

        return $promise;
    }

    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        if (!$this->isReadable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not readable'));
        }

        $promise = new CancellablePromise();
        $buffer = $this->buffer;
        $this->buffer = '';
        $cancelled = false;

        $readMore = function () use ($promise, $maxLength, &$buffer, &$readMore, &$cancelled) {
            if ($cancelled) {
                return;
            }

            if (strlen($buffer) >= $maxLength) {
                $promise->resolve($buffer);
                return;
            }

            $this->read(min($this->chunkSize, $maxLength - strlen($buffer)))->then(
                function ($data) use ($promise, $maxLength, &$buffer, &$readMore, &$cancelled) {
                    if ($cancelled) {
                        return;
                    }

                    if ($data === null) {
                        $promise->resolve($buffer);
                        return;
                    }

                    $buffer .= $data;
                    $readMore();
                }
            )->catch(function ($error) use ($promise, &$cancelled) {
                if (!$cancelled) {
                    $promise->reject($error);
                }
            });
        };

        $promise->setCancelHandler(function () use (&$cancelled) {
            $cancelled = true;
        });

        $readMore();

        return $promise;
    }

    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if (!$this->isReadable()) {
            return $this->createRejectedCancellable(new StreamException('Stream is not readable'));
        }

        if (!$destination->isWritable()) {
            return $this->createRejectedCancellable(new StreamException('Destination is not writable'));
        }

        $endDestination = $options['end'] ?? true;
        $totalBytes = 0;
        $cancelled = false;
        $pendingWriteCount = 0;
        $hasError = false;

        $promise = new CancellablePromise();

        $dataHandler = null;
        $endHandler = null;
        $errorHandler = null;
        $closeHandler = null;

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
                if (!$cancelled && !$hasError) {
                    $hasError = true;
                    $this->emit('error', $error);
                }
            });
        };

        $endHandler = function () use ($promise, $destination, $endDestination, &$totalBytes, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, &$cancelled, &$pendingWriteCount, &$hasError) {
            if ($cancelled || $hasError) {
                return;
            }

            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);
            $destination->off('close', $closeHandler);

            $checkPending = function () use ($promise, $destination, $endDestination, &$totalBytes, &$pendingWriteCount, &$checkPending, &$hasError) {
                if ($hasError) {
                    return;
                }

                if ($pendingWriteCount > 0) {
                    Loop::defer($checkPending);
                    return;
                }

                if ($endDestination) {
                    $destination->end()->then(function () use ($promise, &$totalBytes) {
                        $promise->resolve($totalBytes);
                    })->catch(function ($error) use ($promise, &$totalBytes) {
                        $promise->resolve($totalBytes);
                    });
                } else {
                    $promise->resolve($totalBytes);
                }
            };

            $checkPending();
        };

        $errorHandler = function ($error) use ($promise, $destination, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, &$cancelled, &$hasError) {
            if ($cancelled || $hasError) {
                return;
            }

            $hasError = true;
            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);
            $destination->off('close', $closeHandler);
            $promise->reject($error);
        };

        $closeHandler = function () use ($promise, $destination, &$cancelled, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, &$hasError) {
            if ($cancelled || $hasError) {
                return;
            }

            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);
            $destination->off('close', $closeHandler);

            if ($this->isReadable() && !$this->isEof()) {
                $hasError = true;
                $promise->reject(new StreamException('Destination closed before transfer completed'));
            }
        };

        $this->on('data', $dataHandler);
        $this->on('end', $endHandler);
        $this->on('error', $errorHandler);
        $destination->on('close', $closeHandler);

        $promise->setCancelHandler(function () use (&$cancelled, &$dataHandler, &$endHandler, &$errorHandler, &$closeHandler, $destination) {
            $cancelled = true;
            $this->pause();

            $this->off('data', $dataHandler);
            $this->off('end', $endHandler);
            $this->off('error', $errorHandler);
            $destination->off('close', $closeHandler);
        });

        $this->resume();

        return $promise;
    }

    public function pause(): void
    {
        if (!$this->readable || $this->paused || $this->closed) {
            return;
        }

        $this->paused = true;

        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }

        $this->emit('pause');
    }

    public function resume(): void
    {
        if (!$this->readable || !$this->paused || $this->closed) {
            return;
        }

        $this->paused = false;
        $this->startReading();
        $this->emit('resume');
    }

    public function isReadable(): bool
    {
        return $this->readable && !$this->closed;
    }

    public function isEof(): bool
    {
        return $this->eof || ($this->resource && feof($this->resource));
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
        $this->pause();

        // Reject any pending reads
        while (!empty($this->readQueue)) {
            $item = array_shift($this->readQueue);
            $item['reject'](new StreamException('Stream closed'));
        }

        if (is_resource($this->resource)) {
            @fclose($this->resource);
            $this->resource = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function startReading(): void
    {
        if ($this->watcherId !== null || !$this->readable || $this->paused || $this->closed) {
            return;
        }

        $this->watcherId = Loop::addStreamWatcher(
            $this->resource,
            function () {
                $this->handleReadable();
            },
            'read'
        );
    }

    private function handleReadable(): void
    {
        if ($this->paused || !$this->readable) {
            return;
        }

        $readLength = $this->chunkSize;
        if (!empty($this->readQueue)) {
            $readLength = $this->readQueue[0]['length'] ?? $this->chunkSize;
        }

        $data = @fread($this->resource, $readLength);

        if ($data === false) {
            $error = new StreamException('Failed to read from stream');
            $this->emit('error', $error);

            while (!empty($this->readQueue)) {
                $item = array_shift($this->readQueue);
                if (!$item['promise']->isCancelled()) {
                    $item['reject']($error);
                }
            }

            $this->close();
            return;
        }

        // Check for EOF
        if ($data === '' && feof($this->resource)) {
            $this->eof = true;
            $this->pause();

            // Resolve pending reads with null
            while (!empty($this->readQueue)) {
                $item = array_shift($this->readQueue);
                if (!$item['promise']->isCancelled()) {
                    $item['resolve'](null);
                }
            }

            $this->emit('end');
            // DON'T close here - let the user close or it will close on destruct
            return;
        }

        if ($data !== '') {
            // Emit data event
            $this->emit('data', $data);

            // Resolve first pending read
            if (!empty($this->readQueue)) {
                $item = array_shift($this->readQueue);
                if (!$item['promise']->isCancelled()) {
                    $item['resolve']($data);
                }

                // Pause if no more reads pending and no data listeners
                if (empty($this->readQueue) && !$this->hasListeners('data')) {
                    $this->pause();
                }
            } elseif (!$this->hasListeners('data')) {
                // No pending reads and no data listeners, pause
                $this->pause();
            }
        }
    }

    private function cancelRead(CancellablePromiseInterface $promise): void
    {
        // Remove from queue
        foreach ($this->readQueue as $index => $item) {
            if ($item['promise'] === $promise) {
                unset($this->readQueue[$index]);
                $this->readQueue = array_values($this->readQueue); // Re-index
                break;
            }
        }

        // Pause if no more reads pending
        if (empty($this->readQueue) && !$this->hasListeners('data')) {
            $this->pause();
        }
    }

    private function createResolvedCancellable(mixed $value): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $promise->resolve($value);
        return $promise;
    }

    private function createRejectedCancellable(\Throwable $reason): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $promise->reject($reason);
        return $promise;
    }

    public function __destruct()
    {
        if (!$this->closed) {
            $this->close();
        }
    }
}
