<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Evenement\EventEmitter;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Handlers\WritableStreamHandler;
use Hibla\Stream\Interfaces\WritableStreamInterface;

class WritableResourceStream extends EventEmitter implements WritableStreamInterface
{
    /** @var resource|null The underlying stream resource. */
    private $resource;

    private bool $writable = true;
    private bool $closed = false;
    private bool $ending = false;
    private int $softLimit;

    private WritableStreamHandler $handler;

    /**
     * Initializes the writable stream, validates the resource, and sets it to non-blocking mode.
     * This prepares the stream for asynchronous writing without halting the event loop.
     *
     * @param resource $resource A writable PHP stream resource.
     * @param int $softLimit The size of the write buffer (in bytes) at which backpressure is applied.
     */
    public function __construct($resource, int $softLimit = 65536)
    {
        if (! \is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        $meta = stream_get_meta_data($resource);
        $writableModes = ['w', 'a', 'x', 'c', '+'];
        $isWritable = false;
        foreach ($writableModes as $mode) {
            if (str_contains($meta['mode'], $mode)) {
                $isWritable = true;

                break;
            }
        }

        if (! $isWritable) {
            throw new StreamException('Resource is not writable');
        }

        $this->resource = $resource;
        $this->softLimit = $softLimit;

        $this->setupNonBlocking($resource, $meta);
        $this->initializeHandler();
    }

    /**
     * Get the internal handler for advanced operations.
     */
    public function getHandler(): WritableStreamHandler
    {
        return $this->handler;
    }

    /**
     * Get the soft limit.
     */
    public function getSoftLimit(): int
    {
        return $this->softLimit;
    }

    /**
     * @inheritdoc
     */
    public function write(string $data): bool
    {
        if (! $this->writable || $this->closed) {
            $this->emit('error', [new StreamException('Stream is not writable')]);

            return false;
        }

        if ($data === '') {
            return true;
        }

        $this->handler->bufferData($data);
        $this->handler->startWatching($this->writable, $this->ending, $this->closed);

        // Return false if buffer exceeded soft limit (backpressure signal)
        return $this->handler->getBufferLength() < $this->softLimit;
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): void
    {
        if ($this->ending || $this->closed) {
            return;
        }

        $this->ending = true;
        $this->writable = false;

        if ($data !== null && $data !== '') {
            $this->handler->bufferData($data);
        }

        // If buffer is already empty, finish immediately
        if ($this->handler->isFullyDrained()) {
            $this->emit('finish');
            $this->close();

            return;
        }

        // Otherwise, start watching to drain the buffer
        $this->handler->startWatching(false, $this->ending, $this->closed);
    }

    /**
     * @inheritdoc
     */
    public function isWritable(): bool
    {
        return $this->writable && ! $this->closed;
    }
 
    public function isEnding(): bool
    {
        return $this->ending;
    }

    /**
     * @inheritdoc
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        $this->writable = false;
        $this->ending = false;

        $this->handler->stopWatching();
        $this->handler->clearBuffer();

        if (\is_resource($this->resource)) {
            @fclose($this->resource);
            $this->resource = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * @param resource $resource
     * @param array<string, mixed> $meta
     */
    private function setupNonBlocking($resource, array $meta): void
    {
        $streamType = $meta['stream_type'] ?? '';
        $isWindows = DIRECTORY_SEPARATOR === '\\' || stripos(PHP_OS, 'WIN') === 0;

        $shouldSetNonBlocking = false;

        if (\in_array($streamType, ['tcp_socket', 'udp_socket', 'unix_socket', 'ssl_socket', 'TCP/IP', 'tcp_socket/ssl'], true)) {
            $shouldSetNonBlocking = true;
        } elseif (! $isWindows && \in_array($streamType, ['STDIO', 'PLAINFILE', 'TEMP', 'MEMORY'], true)) {
            $shouldSetNonBlocking = true;
        }

        if ($shouldSetNonBlocking) {
            @stream_set_blocking($resource, false);
        }
    }

    private function initializeHandler(): void
    {
        if ($this->resource === null) {
            throw new StreamException('Resource is null during handler initialization');
        }

        $this->handler = new WritableStreamHandler(
            $this->resource,
            $this->softLimit,
            function (string $event, ...$args): void {
                $this->emit($event, $args);

                if ($event === 'finish' && $this->ending) {
                    $this->close();
                }
            },
            fn () => $this->close(),
            fn () => $this->ending
        );
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}