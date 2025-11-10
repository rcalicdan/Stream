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
     * Create a readable stream from a file path
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
     * Create a writable stream from a file path
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
     * Create a duplex stream from a file path
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
     * Create a readable stream from a resource
     *
     * @param resource $resource
     */
    public static function readable($resource, int $chunkSize = 8192): ReadableStreamInterface
    {
        return new ReadableStream($resource, $chunkSize);
    }

    /**
     * Create a writable stream from a resource
     *
     * @param resource $resource
     */
    public static function writable($resource, int $softLimit = 65536): WritableStreamInterface
    {
        return new WritableStream($resource, $softLimit);
    }

    /**
     * Create a duplex stream from a resource
     *
     * @param resource $resource
     */
    public static function duplex($resource, int $readChunkSize = 8192, int $writeSoftLimit = 65536): DuplexStreamInterface
    {
        return new DuplexStream($resource, $readChunkSize, $writeSoftLimit);
    }

    /**
     * Create a composite stream from separate readable and writable streams
     */
    public static function composite(
        ReadableStreamInterface $readable,
        WritableStreamInterface $writable
    ): DuplexStreamInterface {
        return new CompositeStream($readable, $writable);
    }

    /**
     * Create a through stream with optional transformer
     */
    public static function through(?callable $transformer = null): ThroughStream
    {
        return new ThroughStream($transformer);
    }

    /**
     * Get STDIN as a readable stream
     */
    public static function stdin(int $chunkSize = 8192): ReadableStreamInterface
    {
        return new ReadableStream(STDIN, $chunkSize);
    }

    /**
     * Get STDOUT as a writable stream
     */
    public static function stdout(int $softLimit = 65536): WritableStreamInterface
    {
        return new WritableStream(STDOUT, $softLimit);
    }

    /**
     * Get STDERR as a writable stream
     */
    public static function stderr(int $softLimit = 65536): WritableStreamInterface
    {
        return new WritableStream(STDERR, $softLimit);
    }

    /**
     * Create a composite stream from STDIN and STDOUT
     */
    public static function stdio(int $readChunkSize = 8192, int $writeSoftLimit = 65536): DuplexStreamInterface
    {
        return new CompositeStream(
            new ReadableStream(STDIN, $readChunkSize),
            new WritableStream(STDOUT, $writeSoftLimit)
        );
    }
}
