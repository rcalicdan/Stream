<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Evenement\EventEmitterInterface;

/**
 * Defines the contract for a stream that can be written to.
 * It provides an abstraction for writing data asynchronously and handling backpressure.
 */
interface WritableStreamInterface extends EventEmitterInterface
{
    /**
     * Writes data to the stream. Returns false if the internal buffer is full (backpressure).
     *
     * @param string $data The chunk of data to write.
     * @return bool Returns false if you should stop writing (buffer full), true otherwise.
     */
    public function write(string $data): bool;

    /**
     * Gracefully ends the stream after writing any final data. This signals that no more data will be written.
     *
     * @param string|null $data An optional final chunk of data to write before closing.
     * @return void
     */
    public function end(?string $data = null): void;

    /**
     * Determines if the stream is currently open and available for writing.
     */
    public function isWritable(): bool;

    /**
     * Checks if the stream is in the process of closing after `end()` has been called.
     * During this state, new writes are not accepted, but the buffer is still being flushed.
     */
    public function isEnding(): bool;

    /**
     * Forcefully terminates the stream and closes the underlying resource, discarding any buffered data.
     */
    public function close(): void;
}
