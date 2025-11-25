<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Evenement\EventEmitter;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

class DuplexResourceStream extends EventEmitter implements DuplexStreamInterface
{
    private ReadableResourceStream $readable;
    private WritableResourceStream $writable;
    private bool $closed = false;

    /**
     * @param resource $resource Stream resource (must be read+write)
     * @param int $readChunkSize Default chunk size for reads
     * @param int $writeSoftLimit Soft limit for write buffer
     */
    public function __construct($resource, int $readChunkSize = 8192, int $writeSoftLimit = 65536)
    {
        if (! \is_resource($resource)) {
            throw new StreamException('Invalid resource provided');
        }

        $meta = stream_get_meta_data($resource);
        if (! str_contains($meta['mode'], '+')) {
            throw new StreamException('Resource must be opened in read+write mode (e.g., "r+", "w+", "a+")');
        }

        $this->readable = new ReadableResourceStream($resource, $readChunkSize);
        $this->writable = new WritableResourceStream($resource, $writeSoftLimit);

        $this->setupEventForwarding();
    }

    /**
     * Get the readable side of the stream.
     */
    public function getReadable(): ReadableResourceStream
    {
        return $this->readable;
    }

    /**
     * Get the writable side of the stream.
     */
    public function getWritable(): WritableResourceStream
    {
        return $this->writable;
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        return Util::pipe($this->readable, $destination, $options);
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
    public function write(string $data): bool
    {
        return $this->writable->write($data);
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): void
    {
        $this->readable->pause();
        $this->writable->end($data);
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

    private function setupEventForwarding(): void
    {
        Util::forwardEvents($this->readable, $this, ['data', 'end', 'pause', 'resume', 'pipe', 'unpipe']);
        Util::forwardEvents($this->writable, $this, ['drain', 'finish']);
        Util::forwardEvents($this->readable, $this, ['error']);
        Util::forwardEvents($this->writable, $this, ['error']);

        // Auto-close when both sides close
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

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}