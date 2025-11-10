<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Defines the contract for a stream that can be written to.
 * It provides an abstraction for writing data asynchronously and handling backpressure.
 */
interface WritableStreamInterface
{
    /**
     * Asynchronously writes data to the stream's buffer. The promise resolves when the data is buffered.
     *
     * @param string $data The chunk of data to write.
     * @return CancellablePromiseInterface<int> Resolves with the number of bytes successfully buffered.
     */
    public function write(string $data): CancellablePromiseInterface;

    /**
     * Asynchronously writes a string of data to the stream, automatically appending a newline.
     *
     * @param string $data The line of data to write without a trailing newline.
     * @return CancellablePromiseInterface<int>
     */
    public function writeLine(string $data): CancellablePromiseInterface;

    /**
     * Gracefully ends the stream after writing any final data. This signals that no more data will be written.
     *
     * @param string|null $data An optional final chunk of data to write before closing.
     * @return CancellablePromiseInterface<void> Resolves when all buffered data has been flushed.
     */
    public function end(?string $data = null): CancellablePromiseInterface;

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

    /**
     * Attaches a listener to a specific event.
     *
     * Available events:
     * - 'data': Emitted when data is read (ReadableStream)
     * - 'end': Emitted when stream reaches EOF (ReadableStream)
     * - 'error': Emitted on stream errors
     * - 'close': Emitted when stream is closed
     * - 'drain': Emitted when write buffer is drained (WritableStream)
     * - 'finish': Emitted when stream is finished writing (WritableStream)
     * - 'pause': Emitted when stream is paused (ReadableStream)
     * - 'resume': Emitted when stream is resumed (ReadableStream)
     * - 'pipe': Emitted when piping starts (ReadableStream)
     * - 'unpipe': Emitted when piping stops (ReadableStream)
     *
     * @return static
     */
    public function on(string $event, callable $callback);

    /**
     * Attaches a listener that will be executed only once for a specific event.
     *
     * @return static
     */
    public function once(string $event, callable $callback);

    /**
     * Detaches a specific listener from an event.
     *
     * @return static
     */
    public function off(string $event, callable $callback);
}
