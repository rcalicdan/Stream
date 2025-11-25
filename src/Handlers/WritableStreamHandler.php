<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\EventLoop\Loop;
use Hibla\EventLoop\ValueObjects\StreamWatcher;
use Hibla\Stream\Exceptions\StreamException;

class WritableStreamHandler
{
    private string $writeBuffer = '';
    private ?string $watcherId = null;
    private int $totalWritten = 0;

    /**
     * @param resource $resource
     * @param callable(string, mixed=): void $emitCallback
     * @param callable(): void $closeCallback
     * @param callable(): bool $isEndingCallback
     */
    public function __construct(
        private $resource,
        private int $softLimit,
        private $emitCallback,
        private $closeCallback,
        private $isEndingCallback
    ) {
    }

    public function getBufferLength(): int
    {
        return \strlen($this->writeBuffer);
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
            fn () => $this->handleWritable(),
            StreamWatcher::TYPE_WRITE
        );
    }

    public function stopWatching(): void
    {
        if ($this->watcherId !== null) {
            Loop::removeStreamWatcher($this->watcherId);
            $this->watcherId = null;
        }
    }

    public function handleWritable(): void
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

        $wasAboveLimit = \strlen($this->writeBuffer) >= $this->softLimit;
        $this->writeBuffer = substr($this->writeBuffer, $written);
        $this->totalWritten += $written;
        $isNowBelowLimit = \strlen($this->writeBuffer) < $this->softLimit;

        // Emit drain when buffer goes below soft limit
        if ($wasAboveLimit && $isNowBelowLimit) {
            ($this->emitCallback)('drain');
        }

        // Check if fully drained
        if ($this->writeBuffer === '') {
            $this->stopWatching();
            ($this->emitCallback)('drain');

            // Check if we're ending using the callback
            if (($this->isEndingCallback)()) {
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