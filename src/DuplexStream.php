<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;

class DuplexStream implements DuplexStreamInterface
{
    use EventEmitterTrait;

    private ReadableStream $readable;
    private WritableStream $writable;
    private bool $closed = false;

    /**
     * @param resource $resource Stream resource (must be read+write)
     * @param int $readChunkSize Default chunk size for reads
     * @param int $writeSoftLimit Soft limit for write buffer
     */
    public function __construct($resource, int $readChunkSize = 8192, int $writeSoftLimit = 65536)
    {
        if (! is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        $meta = stream_get_meta_data($resource);
        if (! str_contains($meta['mode'], '+')) {
            throw new StreamException('Resource must be opened in read+write mode (e.g., "r+", "w+", "a+")');
        }

        $this->readable = new ReadableStream($resource, $readChunkSize);
        $this->writable = new WritableStream($resource, $writeSoftLimit);

        $this->forwardEvents($this->readable, ['data', 'end', 'pause', 'resume']);
        $this->forwardEvents($this->writable, ['drain', 'finish']);

        $this->readable->on('error', fn($error) => $this->emit('error', $error));
        $this->writable->on('error', fn($error) => $this->emit('error', $error));

        $this->readable->on('close', function () {
            if (! $this->closed) {
                $this->close();
            }
        });

        $this->writable->on('close', function () {
            if (! $this->closed) {
                $this->close();
            }
        });
    }

    /**
     * @inheritdoc
     */
    public function read(?int $length = null): CancellablePromiseInterface
    {
        return $this->readable->read($length);
    }

    /**
     * @inheritdoc
     */
    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        return $this->readable->readLine($maxLength);
    }

    /**
     * @inheritdoc
     */
    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        return $this->readable->readAll($maxLength);
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        return $this->readable->pipe($destination, $options);
    }

    /**
     * @inheritdoc
     */
    public function seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->readable->seek($offset, $whence);
    }

    /**
     * @inheritdoc
     */
    public function isReadable(): bool
    {
        return $this->readable->isReadable();
    }

    /**
     * @inheritdoc
     */
    public function isEof(): bool
    {
        return $this->readable->isEof();
    }

    /**
     * @inheritdoc
     */
    public function pause(): void
    {
        $this->readable->pause();
    }

    /**
     * @inheritdoc
     */
    public function resume(): void
    {
        if ($this->writable->isWritable()) {
            $this->readable->resume();
        }
    }

    /**
     * @inheritdoc
     */
    public function isPaused(): bool
    {
        return $this->readable->isPaused();
    }

    /**
     * @inheritdoc
     */
    public function write(string $data): CancellablePromiseInterface
    {
        return $this->writable->write($data);
    }

    /**
     * @inheritdoc
     */
    public function writeLine(string $data): CancellablePromiseInterface
    {
        return $this->writable->writeLine($data);
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): CancellablePromiseInterface
    {
        $this->readable->pause();

        return $this->writable->end($data);
    }

    /**
     * @inheritdoc
     */
    public function isWritable(): bool
    {
        return $this->writable->isWritable();
    }

    /**
     * @inheritdoc
     */
    public function isEnding(): bool
    {
        return $this->writable->isEnding();
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

        $this->readable->close();
        $this->writable->close();

        $this->emit('close');
        $this->removeAllListeners();
    }

    /**
     * @param ReadableStream|WritableStream $source
     * @param array<string> $events
     */
    private function forwardEvents(object $source, array $events): void
    {
        foreach ($events as $event) {
            $source->on($event, function (...$args) use ($event) {
                $this->emit($event, ...$args);
            });
        }
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}
