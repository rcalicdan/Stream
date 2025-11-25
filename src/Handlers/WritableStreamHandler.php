<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\Stream\Exceptions\StreamException;

class WritableStreamHandler
{
    /** @var resource */
    private $resource;
    private int $softLimit;
    private string $writeBuffer = '';
    private ?string $watcherId = null;
    private int $totalWritten = 0;

    /** @var callable(string, mixed=): void */
    private $emitCallback;

    /** @var callable(): void */
    private $closeCallback;

    /**
     * @param resource $resource
     */
    public function __construct($resource, int $softLimit, callable $emitCallback, callable $closeCallback)
    {
        $this->resource = $resource;
        $this->softLimit = $softLimit;
        $this->emitCallback = $emitCallback;
        $this->closeCallback = $closeCallback;
    }

    public function getBufferLength(): int
    {
        return strlen($this->writeBuffer);
    }

    public function bufferData(string $data): void
    {
        $this->writeBuffer .= $data;
    }

    public function clearBuffer(): void
    {
        $this->writeBuffer = '';
    }

    public function startWatching(bool $writable, bool $ending, bool $closed): void
    {
        if ($this->watcherId !== null || $closed || $this->writeBuffer === '') {
            return;
        }

        if (! $writable && ! $ending) {
            return;
        }

        $this->watcherId = Loop::addStreamWatcher(
            $this->resource,
            fn () => $this->handleWritable($ending),
            'write'
        );
    }

    public function stopWatching(): void
    {
        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    public function handleWritable(bool $ending = false): void
    {
        if ($this->writeBuffer === '') {
            return;
        }

        $written = @fwrite($this->resource, $this->writeBuffer);

        if ($written === false || $written === 0) {
            $error = new StreamException('Failed to write to stream');
            ($this->emitCallback)('error', $error);
            ($this->closeCallback)();

            return;
        }

        $wasAboveLimit = strlen($this->writeBuffer) >= $this->softLimit;
        $this->writeBuffer = substr($this->writeBuffer, $written);
        $this->totalWritten += $written;
        $isNowBelowLimit = strlen($this->writeBuffer) < $this->softLimit;

        // Emit drain when buffer goes below soft limit
        if ($wasAboveLimit && $isNowBelowLimit) {
            ($this->emitCallback)('drain');
        }

        // Check if fully drained
        if ($this->writeBuffer === '') {
            $this->stopWatching();
            ($this->emitCallback)('drain');

            // If ending, emit finish
            if ($ending) {
                ($this->emitCallback)('finish');
            }
        }
    }

    public function isFullyDrained(): bool
    {
        return $this->writeBuffer === '';
    }

    public function getTotalWritten(): int
    {
        return $this->totalWritten;
    }
}
