<?php 

namespace Hibla\Stream\Interfaces;

use Hibla\Promise\Interfaces\CancellablePromiseInterface;

interface WritableStreamInterface
{
    /**
     * Write data to the stream
     * 
     * @param string $data Data to write
     * @return CancellablePromiseInterface<int> Resolves with bytes written
     */
    public function write(string $data): CancellablePromiseInterface;

    /**
     * Write a line to the stream
     * 
     * @param string $data Data to write (newline added automatically)
     * @return CancellablePromiseInterface<int>
     */
    public function writeLine(string $data): CancellablePromiseInterface;

    /**
     * End the stream (optionally writing final data)
     * 
     * @param string|null $data Optional final data
     * @return CancellablePromiseInterface<void>
     */
    public function end(?string $data = null): CancellablePromiseInterface;

    /**
     * Check if stream is writable
     */
    public function isWritable(): bool;

    /**
     * Check if stream is ending
     */
    public function isEnding(): bool;

    /**
     * Close the stream
     */
    public function close(): void;

    /**
     * Register event listener
     * 
     * Events: drain, error, close, finish
     */
    public function on(string $event, callable $callback): self;

    /**
     * Register one-time event listener
     */
    public function once(string $event, callable $callback): self;

    /**
     * Remove event listener
     */
    public function off(string $event, callable $callback): self;
}