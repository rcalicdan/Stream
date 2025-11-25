<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Evenement\EventEmitter;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Handlers\ReadableStreamHandler;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

class ReadableResourceStream extends EventEmitter implements ReadableStreamInterface
{
    /** @var resource|null The underlying stream resource. */
    private $resource;

    private bool $readable = true;
    private bool $paused = true;
    private bool $closed = false;
    private bool $eof = false;
    private int $chunkSize;

    private ReadableStreamHandler $handler;

    /**
     * Initializes the readable stream, validates the resource, and sets it to non-blocking mode.
     * This constructor sets up the internal machinery required for async reading without blocking the event loop.
     *
     * @param resource $resource A readable PHP stream resource.
     * @param int $chunkSize The default amount of data to read in a single operation.
     */
    public function __construct($resource, int $chunkSize = 65536)
    {
        if (! \is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        $meta = stream_get_meta_data($resource);
        if (! str_contains($meta['mode'], 'r') && ! str_contains($meta['mode'], '+')) {
            throw new StreamException('Resource is not readable');
        }

        $this->resource = $resource;
        $this->chunkSize = $chunkSize;

        $this->setupNonBlocking($resource, $meta);
        $this->initializeHandler();
    }

    /**
     * Get the internal handler for advanced operations.
     */
    public function getHandler(): ReadableStreamHandler
    {
        return $this->handler;
    }

    /**
     * Get the chunk size.
     */
    public function getChunkSize(): int
    {
        return $this->chunkSize;
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        return Util::pipe($this, $destination, $options);
    }

    /**
     * @inheritdoc
     */
    public function pause(): void
    {
        if (! $this->readable || $this->paused || $this->closed) {
            return;
        }

        $this->paused = true;
        $this->handler->stopWatching();
        $this->emit('pause');
    }

    /**
     * @inheritdoc
     */
    public function resume(): void
    {
        if (! $this->readable || ! $this->paused || $this->closed) {
            return;
        }

        $this->paused = false;
        $this->handler->startWatching($this->readable, $this->paused, $this->closed);
        $this->emit('resume');
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
    public function isEof(): bool
    {
        return $this->eof || ($this->resource !== null && is_resource($this->resource) && feof($this->resource));
    }

    /**
     * @inheritdoc
     */
    public function isPaused(): bool
    {
        return $this->paused;
    }

    /**
     * @inheritdoc
     */
    public function seek(int $offset, int $whence = SEEK_SET): bool
    {
        if ($this->closed) {
            throw new StreamException('Cannot seek on a closed stream');
        }

        if ($this->resource === null || ! \is_resource($this->resource)) {
            throw new StreamException('Invalid stream resource');
        }

        $meta = stream_get_meta_data($this->resource);
        // PHPStan knows 'seekable' always exists in metadata
        if ($meta['seekable'] === false) {
            return false;
        }

        $result = @fseek($this->resource, $offset, $whence);

        if ($result === 0) {
            $this->handler->clearBuffer();
            $this->eof = false;

            return true;
        }

        return false;
    }

    /**
     * Get the current position in the stream.
     *
     * @return int|false The current position, or false on failure
     * @throws StreamException If the stream is closed or the resource is invalid
     */
    public function tell(): int|false
    {
        if ($this->closed) {
            throw new StreamException('Cannot tell position on a closed stream');
        }

        if ($this->resource === null || ! \is_resource($this->resource)) {
            throw new StreamException('Invalid stream resource');
        }

        return @ftell($this->resource);
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
        $this->readable = false;
        $this->pause();

        $this->handler->rejectAllPending(new StreamException('Stream closed'));

        if ($this->resource !== null && \is_resource($this->resource)) {
            @fclose($this->resource);
            $this->resource = null;
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * Check if there are listeners for an event.
     */
    private function hasListeners(string $event): bool
    {
        $listeners = $this->listeners($event);

        return \count($listeners) > 0;
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
            @stream_set_read_buffer($resource, 0);
        }
    }

    private function initializeHandler(): void
    {
        if ($this->resource === null) {
            throw new StreamException('Resource is null during handler initialization');
        }

        $resource = $this->resource;

        $this->handler = new ReadableStreamHandler(
            $resource,
            $this->chunkSize,
            function (string $event, ...$args): void {
                $this->emit($event, $args);

                if ($event === 'end') {
                    $this->eof = true;
                }
            },
            fn () => $this->close(),
            fn () => \is_resource($resource) && feof($resource),
            fn () => $this->pause(),
            fn () => $this->paused,
            fn (string $event) => $this->hasListeners($event)
        );
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}
