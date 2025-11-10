<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;
use Hibla\Stream\Traits\EventEmitterTrait;
use Hibla\Stream\Traits\PromiseHelperTrait;

class CompositeStream implements DuplexStreamInterface
{
    use EventEmitterTrait;
    use PromiseHelperTrait;

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
     * @inheritdoc
     */
    public function read(?int $length = null): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is closed'));
        }

        return $this->readable->read($length);
    }

    /**
     * @inheritdoc
     */
    public function readLine(?int $maxLength = null): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is closed'));
        }

        return $this->readable->readLine($maxLength);
    }

    /**
     * @inheritdoc
     */
    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is closed'));
        }

        return $this->readable->readAll($maxLength);
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is closed'));
        }

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
    public function write(string $data): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is closed'));
        }

        return $this->writable->write($data);
    }

    /**
     * @inheritdoc
     */
    public function writeLine(string $data): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createRejectedPromise(new StreamException('Stream is closed'));
        }

        return $this->writable->writeLine($data);
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): CancellablePromiseInterface
    {
        if ($this->closed) {
            return $this->createResolvedVoidPromise();
        }

        $this->readable->pause();

        return $this->writable->end($data);
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

    /**
     * @inheritdoc
     */
    public function getReadable(): ReadableStreamInterface
    {
        return $this->readable;
    }

    /**
     * @inheritdoc
     */
    public function getWritable(): WritableStreamInterface
    {
        return $this->writable;
    }

    private function setupEventForwarding(): void
    {
        $this->forwardEvents($this->readable, ['data', 'end', 'pause', 'resume']);
        $this->forwardEvents($this->writable, ['drain', 'finish']);

        $this->readable->on('error', fn ($error) => $this->emit('error', $error));
        $this->writable->on('error', fn ($error) => $this->emit('error', $error));

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
