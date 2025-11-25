<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Evenement\EventEmitterTrait;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

/**
 * A through stream is a duplex stream that can optionally transform data as it passes through.
 */
class ThroughStream implements DuplexStreamInterface
{
    use EventEmitterTrait;

    private bool $readable = true;
    private bool $writable = true;
    private bool $closed = false;
    private bool $paused = false;
    private bool $ending = false;

    /**
     * Initializes the transform stream with an optional callback.
     * If provided, this callback will be applied to every chunk of data written to the stream before it is emitted.
     *
     * @param callable(string): string|null $transformer A function to process each data chunk.
     */
    public function __construct(
        private $transformer = null
    ) {
    }

    /**
     * @inheritdoc
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): WritableStreamInterface
    {
        // source not readable => NO-OP
        if (!$this->isReadable()) {
            return $destination;
        }

        // destination not writable => just pause() source
        if (!$destination->isWritable()) {
            $this->pause();
            return $destination;
        }

        $destination->emit('pipe', [$this]);

        // forward all source data events as $destination->write()
        $this->on('data', $dataer = function (string $data) use ($destination): void {
            $feedMore = $destination->write($data);
            if (false === $feedMore) {
                $this->pause();
            }
        });

        $destination->on('close', function () use ($dataer): void {
            $this->removeListener('data', $dataer);
            $this->pause();
        });

        // forward destination drain as $this->resume()
        $destination->on('drain', $drainer = function (): void {
            $this->resume();
        });

        $this->on('close', function () use ($destination, $drainer): void {
            $destination->removeListener('drain', $drainer);
        });

        // forward end event from source as $destination->end()
        $end = $options['end'] ?? true;
        if ($end) {
            $this->on('end', $ender = function () use ($destination): void {
                $destination->end();
            });

            $destination->on('close', function () use ($ender): void {
                $this->removeListener('end', $ender);
            });
        }

        return $destination;
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
        $this->emit('resume');
        $this->emit('drain');
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
        return ! $this->readable;
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
        // ThroughStream doesn't support seeking
        return false;
    }

    // WritableStreamInterface methods

    /**
     * @inheritdoc
     */
    public function write(string $data): bool
    {
        if (! $this->isWritable()) {
            $this->emit('error', [new \RuntimeException('Stream is not writable')]);
            return false;
        }

        try {
            $transformedData = $data;
            if ($this->transformer !== null) {
                $transformedData = ($this->transformer)($data);
            }

            $this->emit('data', [$transformedData]);

            return !$this->paused;
        } catch (\Throwable $e) {
            $this->emit('error', [$e]);
            $this->close();
            return false;
        }
    }

    /**
     * @inheritdoc
     */
    public function end(?string $data = null): void
    {
        if (! $this->isWritable() || $this->ending) {
            return;
        }

        $this->ending = true;

        try {
            if ($data !== null && $data !== '') {
                $transformedData = $data;
                if ($this->transformer !== null) {
                    $transformedData = ($this->transformer)($data);
                }

                $this->emit('data', [$transformedData]);
            }

            $this->writable = false;
            $this->readable = false;
            $this->emit('end');
            $this->emit('finish');
            $this->close();
        } catch (\Throwable $e) {
            $this->writable = false;
            $this->readable = false;
            $this->emit('error', [$e]);
            $this->close();
        }
    }

    /**
     * @inheritdoc
     */
    public function isWritable(): bool
    {
        return $this->writable && ! $this->closed;
    }

    /**
     * @inheritdoc
     */
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
        $this->readable = false;
        $this->writable = false;
        $this->paused = false;
        $this->transformer = null;

        $this->emit('close');
        $this->removeAllListeners();
    }

    public function __destruct()
    {
        if (! $this->closed) {
            $this->close();
        }
    }
}