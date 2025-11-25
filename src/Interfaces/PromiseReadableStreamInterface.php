<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Defines the contract for promise-based stream operations.
 * Provides async methods for reading data using promises.
 */
interface PromiseReadableStreamInterface extends ReadableStreamInterface
{
    /**
     * Asynchronously reads a chunk of data. The promise resolves with the data when available.
     *
     * @param int|null $length Maximum bytes to read. Defaults to the stream's preferred chunk size.
     * @return CancellablePromiseInterface<string|null> Resolves with data, or null if the stream has ended.
     */
    public function readAsync(?int $length = null): CancellablePromiseInterface;

    /**
     * Asynchronously reads data until a newline character is encountered.
     *
     * @param int|null $maxLength A safeguard to limit the line length.
     * @return CancellablePromiseInterface<string|null> Resolves with the line, including the newline character.
     */
    public function readLineAsync(?int $maxLength = null): CancellablePromiseInterface;

    /**
     * Asynchronously reads the entire stream into a single string until its end.
     *
     * @param int $maxLength A safeguard to prevent excessive memory usage.
     * @return CancellablePromiseInterface<string> Resolves with the complete contents of the stream.
     */
    public function readAllAsync(int $maxLength = 1048576): CancellablePromiseInterface;

    /**
     * Forwards all data from this stream to a destination, automatically handling backpressure.
     * This is a highly efficient way to transfer data between streams.
     *
     * @param WritableStreamInterface $destination The stream to receive the data.
     * @param array{end?: bool} $options Configure piping behavior, such as whether to end the destination stream.
     * @return CancellablePromiseInterface<int> Resolves with the total number of bytes piped.
     */
    public function pipeAsync(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface;
}
