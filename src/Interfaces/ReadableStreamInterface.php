<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Defines the contract for a stream that can be read from.
 * It provides an abstraction for consuming data asynchronously and managing flow control.
 */
interface ReadableStreamInterface
{
    /**
     * Asynchronously reads a chunk of data. The promise resolves with the data when available.
     *
     * @param int|null $length Maximum bytes to read. Defaults to the stream's preferred chunk size.
     * @return CancellablePromiseInterface<string|null> Resolves with data, or null if the stream has ended.
     */
    public function read(?int $length = null): CancellablePromiseInterface;

    /**
     * Asynchronously reads data until a newline character is encountered.
     *
     * @param int|null $maxLength A safeguard to limit the line length.
     * @return CancellablePromiseInterface<string|null> Resolves with the line, excluding the newline character.
     */
    public function readLine(?int $maxLength = null): CancellablePromiseInterface;

    /**
     * Asynchronously reads the entire stream into a single string until its end.
     *
     * @param int $maxLength A safeguard to prevent excessive memory usage.
     * @return CancellablePromiseInterface<string> Resolves with the complete contents of the stream.
     */
    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface;

    /**
     * Forwards all data from this stream to a destination, automatically handling backpressure.
     * This is a highly efficient way to transfer data between streams.
     *
     * @param WritableStreamInterface $destination The stream to receive the data.
     * @param array{end?: bool} $options Configure piping behavior, such as whether to end the destination stream.
     * @return CancellablePromiseInterface<int> Resolves with the total number of bytes piped.
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface;

    /**
     * Determines if the stream is currently open and available for reading.
     */
    public function isReadable(): bool;

    /**
     * Checks if the end of the stream has been reached and no more data will become available.
     */
    public function isEof(): bool;

    /**
     * Halts the emission of 'data' events, signaling a need to temporarily stop data flow.
     */
    public function pause(): void;

    /**
     * Resumes the emission of 'data' events, allowing data to flow again.
     */
    public function resume(): void;

    /**
     * Determines if the stream is currently paused and not emitting data.
     */
    public function isPaused(): bool;

    /**
     * Forcefully terminates the stream and closes the underlying resource.
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
