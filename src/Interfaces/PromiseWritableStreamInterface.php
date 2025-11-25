<?php

declare(strict_types=1);

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;

/**
 * Defines the contract for promise-based writable stream operations.
 * Provides async methods for writing data using promises.
 */
interface PromiseWritableStreamInterface extends WritableStreamInterface
{
    /**
     * Asynchronously writes data to the stream's buffer. The promise resolves when the data is buffered.
     *
     * @param string $data The chunk of data to write.
     * @return CancellablePromiseInterface<int> Resolves with the number of bytes successfully buffered.
     */
    public function writeAsync(string $data): CancellablePromiseInterface;

    /**
     * Asynchronously writes a string of data to the stream, automatically appending a newline.
     *
     * @param string $data The line of data to write without a trailing newline.
     * @return CancellablePromiseInterface<int>
     */
    public function writeLineAsync(string $data): CancellablePromiseInterface;

    /**
     * Gracefully ends the stream after writing any final data. This signals that no more data will be written.
     *
     * @param string|null $data An optional final chunk of data to write before closing.
     * @return CancellablePromiseInterface<void> Resolves when all buffered data has been flushed.
     */
    public function endAsync(?string $data = null): CancellablePromiseInterface;
}
