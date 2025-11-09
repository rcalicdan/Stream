<?php

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;

interface ReadableStreamInterface
{
    /**
     * Read data from the stream
     * 
     * @param int|null $length Maximum bytes to read (null for default chunk size)
     * @return CancellablePromiseInterface<string|null> Resolves with data or null on EOF
     */
    public function read(?int $length = null): CancellablePromiseInterface;

    /**
     * Read a line from the stream
     * 
     * @param int|null $maxLength Maximum bytes to read
     * @return CancellablePromiseInterface<string|null>
     */
    public function readLine(?int $maxLength = null): CancellablePromiseInterface;

    /**
     * Read all remaining data from the stream
     * 
     * @param int $maxLength Maximum total bytes to read
     * @return CancellablePromiseInterface<string>
     */
    public function readAll(int $maxLength = 1048576): CancellablePromiseInterface;

    /**
     * Pipe this stream to a writable stream
     * 
     * @param WritableStreamInterface $destination
     * @param array{end?: bool} $options Options: end (default true)
     * @return CancellablePromiseInterface<int> Resolves with bytes piped
     */
    public function pipe(WritableStreamInterface $destination, array $options = []): CancellablePromiseInterface;

    /**
     * Check if stream is readable
     */
    public function isReadable(): bool;

    /**
     * Check if stream is at EOF
     */
    public function isEof(): bool;

    /**
     * Pause reading from the stream
     */
    public function pause(): void;

    /**
     * Resume reading from the stream
     */
    public function resume(): void;

    /**
     * Check if stream is paused
     */
    public function isPaused(): bool;

    /**
     * Close the stream
     */
    public function close(): void;

    /**
     * Register event listener
     * 
     * Events: data, end, error, close, pause, resume
     * 
     * @return static
     */
    public function on(string $event, callable $callback);

    /**
     * Register one-time event listener
     * 
     * @return static
     */
    public function once(string $event, callable $callback);

    /**
     * Remove event listener
     * 
     * @return static
     */
    public function off(string $event, callable $callback);
}