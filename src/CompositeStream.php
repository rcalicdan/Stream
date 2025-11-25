<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Evenement\EventEmitterTrait;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

/**
 * Creates a duplex stream from separate readable and writable streams.
 * This is useful for combining independent streams into a single bidirectional interface.
 */
class CompositeStream implements DuplexStreamInterface
{
    use EventEmitterTrait;

    private ReadableStreamInterface $readable;
    private WritableStreamInterface $writable;
    private bool $closed = false;

    /**
     * Creates a duplex stream from separate readable and writable streams.
     *
     * @param ReadableStreamInterface $readable The readable side of the stream
     * @param WritableStreamInterface $writable The writable side of the stream
     */
    public function __construct(
        ReadableStreamInterface $readable,
        WritableStreamInterface $writable
    ) {
        $this->readable = $readable;
        $this->writable = $writable;

        $this->setupEventForwarding();
    }

    /**
     * Get the readable side of the stream.
     */
    public function getReadable(): ReadableStreamInterface
    {
        return $this->readable;
    }

    /**
     * Get the writable side of the stream.
     */
    public function getWritable(): WritableStreamInterface
    {
        return $this->writable;
    }

    // ReadableStreamInterface methods

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        return $this->readable->pipe($destination, $options);
    }

    /**
     * @inheritdoc
     */
    public function isReadable(): bool
    {
        return ! $this->closed && $this->readable->isReadable();
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
        if (! $this->closed) {
            $this->readable->pause();
        }
    }

    /**
     * @inheritdoc
     */
    public function resume(): void
    {
        if (! $this->closed && $this->writable->isWritable()) {
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
    public function seek(int $offset, int $whence = SEEK_SET): bool
    {
        return $this->readable->seek($offset, $whence);
    }

    // WritableStreamInterface methods

    /**
     * @inheritdoc
     */
    public function write(string $data): bool
    {
        if ($this->closed) {
            $this->emit('error', [new \RuntimeException('Stream is closed')]);
            return false;
        }

        return $this->writable->write($data);
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): void
    {
        if ($this->closed) {
            return;
        }

        $this->readable->pause();
        $this->writable->end($data);
    }

    /**
     * @inheritdoc
     */
    public function isWritable(): bool
    {
        return ! $this->closed && $this->writable->isWritable();
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

        if ($this->readable->isReadable()) {
            $this->readable->close();
        }

        if ($this->writable->isWritable()) {
            $this->writable->close();
        }

        $this->emit('close');
        $this->removeAllListeners();
    }

    private function setupEventForwarding(): void
    {
        // Forward readable events
        $this->forwardEvents($this->readable, ['data', 'end', 'pause', 'resume', 'pipe', 'unpipe']);
        
        // Forward writable events
        $this->forwardEvents($this->writable, ['drain', 'finish']);

        // Forward error events from both
        $this->readable->on('error', fn (...$args) => $this->emit('error', $args));
        $this->writable->on('error', fn (...$args) => $this->emit('error', $args));

        // Auto-close when both sides are closed
        $this->readable->on('close', function () {
            if (! $this->closed && ! $this->writable->isWritable()) {
                $this->close();
            }
        });

        $this->writable->on('close', function () {
            if (! $this->closed && ! $this->readable->isReadable()) {
                $this->close();
            }
        });
    }

    /**
     * @param ReadableStreamInterface|WritableStreamInterface $source
     * @param array<string> $events
     */
    private function forwardEvents(object $source, array $events): void
    {
        foreach ($events as $event) {
            $source->on($event, fn (...$args) => $this->emit($event, $args));
        }
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}