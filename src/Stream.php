<?php

declare(strict_types=1);

namespace Hibla\Stream;

use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\Interfaces\DuplexStreamInterface;
use Hibla\Stream\Interfaces\ReadableStreamInterface;
use Hibla\Stream\Interfaces\WritableStreamInterface;

class Stream
{
    /**
     * Creates a stream to process a file's contents without loading the entire file into memory.
     * This is ideal for reading large files asynchronously.
     */
    public static function readableFile(string $path, int $chunkSize = 65536): ReadableStreamInterface
    {
        $resource = @fopen($path, 'rb');

        if ($resource === false) {
            throw new StreamException("Failed to open file for reading: {$path}");
        }

        return new ReadableStream($resource, $chunkSize);
    }

    /**
     * Creates a stream to write data to a file asynchronously.
     * This is useful for efficiently piping data from another source directly to the filesystem.
     */
    public static function writableFile(string $path, bool $append = false, int $softLimit = 65536): WritableStreamInterface
    {
        $mode = $append ? 'ab' : 'wb';
        $resource = @fopen($path, $mode);

        if ($resource === false) {
            throw new StreamException("Failed to open file for writing: {$path}");
        }

        return new WritableStream($resource, $softLimit);
    }

    /**
     * Creates a stream for performing both read and write operations on the same file handle.
     * This is necessary for tasks like updating a file in place.
     */
    public static function duplexFile(string $path, int $readChunkSize = 65536, int $writeSoftLimit = 65536): DuplexStreamInterface
    {
        $resource = @fopen($path, 'r+b');

        if ($resource === false) {
            throw new StreamException("Failed to open file for read/write: {$path}");
        }

        return new DuplexStream($resource, $readChunkSize, $writeSoftLimit);
    }

    /**
     * Wraps an existing PHP stream resource with the non-blocking, event-driven `ReadableStreamInterface`.
     * Use this to adapt generic resources like sockets or in-memory streams for use in an async application.
     *
     * @param resource $resource
     */
    public static function readable($resource, int $chunkSize = 8192): ReadableStreamInterface
    {
        return new ReadableStream($resource, $chunkSize);
    }

    /**
     * Provides a non-blocking, event-driven interface for writing to an existing PHP stream resource.
     * This adds important features like backpressure handling to a generic writable resource.
     *
     * @param resource $resource
     */
    public static function writable($resource, int $softLimit = 65536): WritableStreamInterface
    {
        return new WritableStream($resource, $softLimit);
    }

    /**
     * Manages a single read-write capable resource (like a TCP socket) with a unified, non-blocking interface.
     *
     * @param resource $resource
     */
    public static function duplex($resource, int $readChunkSize = 8192, int $writeSoftLimit = 65536): DuplexStreamInterface
    {
        return new DuplexStream($resource, $readChunkSize, $writeSoftLimit);
    }

    /**
     * Combines two independent, one-way streams into a single logical duplex stream.
     * This is essential for scenarios like process I/O where STDIN and STDOUT are separate resources.
     */
    public static function composite(
        ReadableStreamInterface $readable,
        WritableStreamInterface $writable
    ): DuplexStreamInterface {
        return new CompositeStream($readable, $writable);
    }

    /**
     * Creates a stream that can be placed in the middle of a pipe chain to modify data as it passes through.
     * This is perfect for tasks like filtering, compression, or protocol encoding.
     */
    public static function through(?callable $transformer = null): ThroughStream
    {
        return new ThroughStream($transformer);
    }

    /**
     * Provides a non-blocking stream to consume input from the standard input of a process.
     */
    public static function stdin(int $chunkSize = 8192): ReadableStreamInterface
    {
        return new ReadableStream(STDIN, $chunkSize);
    }

    /**
     * Provides an asynchronous stream to write to the standard output of a process, respecting backpressure.
     */
    public static function stdout(int $softLimit = 65536): WritableStreamInterface
    {
        return new WritableStream(STDOUT, $softLimit);
    }

    /**
     * Provides an asynchronous stream for writing error information to the standard error output of a process.
     */
    public static function stderr(int $softLimit = 65536): WritableStreamInterface
    {
        return new WritableStream(STDERR, $softLimit);
    }

    /**
     * Models the standard I/O of a command-line application as a single duplex stream.
     * This simplifies the handling of interactive console input and output.
     */
    public static function stdio(int $readChunkSize = 8192, int $writeSoftLimit = 65536): DuplexStreamInterface
    {
        return new CompositeStream(
            new ReadableStream(STDIN, $readChunkSize),
            new WritableStream(STDOUT, $writeSoftLimit)
        );
    }
}
