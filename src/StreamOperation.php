<?php

namespace Hibla\Stream;

use Hibla\Async\AsyncOperations;
use Hibla\EventLoop\EventLoop;
use Hibla\Promise\Interfaces\PromiseInterface;
use Hibla\Promise\Promise;
use Hibla\Stream\Stream\Exceptions\StreamException;
use Hibla\Stream\Stream\Exceptions\TimeoutException;

/**
 * Single entry point for all asynchronous stream operations.
 *
 * This class provides methods for opening streams, reading from streams,
 * writing to streams, seeking, and other stream operations in a non-blocking manner
 * using promises and an event loop system.
 */
class StreamOperation
{
    /**
     * The event loop instance for managing asynchronous operations.
     */
    private EventLoop $loop;

    /**
     * Asynchronous operations helper.
     */
    private AsyncOperations $asyncOps;

    /**
     * Default byte size for read operations.
     */
    private const DEFAULT_BYTE_SIZE = 8192;

    /**
     * Maximum buffer size for readAll operations.
     */
    private const MAX_BUFFER_SIZE = 16777216; // 16MB

    /**
     * Constructor initializes the event loop and async operations.
     */
    public function __construct()
    {
        $this->loop = EventLoop::getInstance();
        $this->asyncOps = new AsyncOperations;
    }

    /**
     * Get the async operations helper instance.
     *
     * @return AsyncOperations The async operations instance
     */
    public function getAsyncOps(): AsyncOperations
    {
        return $this->asyncOps;
    }

    /**
     * Open a stream resource asynchronously.
     *
     * Creates a non-blocking stream that resolves when the stream
     * is ready for operations or rejects if opening fails or times out.
     *
     * @param  string  $filename  The file or URL to open
     * @param  string  $mode  The mode for opening the stream (e.g., 'r', 'w', 'a')
     * @param  float|null  $timeout  Open timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @param  array  $contextOptions  Additional context options for the stream
     * @return PromiseInterface<resource> A promise that resolves with a stream resource on successful opening
     *
     * @throws StreamException If the stream cannot be opened
     * @throws TimeoutException If the open operation times out
     */
    public function open(string $filename, string $mode = 'r', ?float $timeout = 10.0, array $contextOptions = []): PromiseInterface
    {
        return new Promise(function ($resolve, $reject) use ($filename, $mode, $timeout, $contextOptions) {
            $context = null;
            if (!empty($contextOptions)) {
                $context = stream_context_create($contextOptions);
            }

            $stream = @fopen($filename, $mode, false, $context);
            if ($stream === false) {
                $reject(new StreamException("Failed to open stream: {$filename}"));
                return;
            }

            stream_set_blocking($stream, false);

            // For network streams, we might need to wait for connection
            if ($this->isNetworkStream($filename)) {
                $this->waitForStreamReady($stream, $resolve, $reject, $timeout);
            } else {
                $resolve($stream);
            }
        });
    }

    /**
     * Read data asynchronously from a stream.
     *
     * Reads up to the specified number of bytes from the stream. The promise
     * resolves when data is available or the stream reaches EOF.
     *
     * @param  resource  $stream  The stream to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<string|null> A promise that resolves with the data read, or null if stream is at EOF
     *
     * @throws StreamException If the stream is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public function read($stream, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        $readLength = $length ?? self::DEFAULT_BYTE_SIZE;

        return new Promise(function ($resolve, $reject) use ($stream, $readLength, $timeout) {
            $timerId = null;

            $readCallback = function () use ($stream, $readLength, $resolve, $reject, &$timerId) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                    $timerId = null;
                }
                $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));

                $data = @fread($stream, $readLength);
                if ($data === false) {
                    $reject(new StreamException('Failed to read from stream.'));
                } elseif ($data === '' && feof($stream)) {
                    $resolve(null);
                } else {
                    $resolve($data);
                }
            };

            $watcherId = $this->loop->addStreamWatcher($stream, $readCallback, 'read');

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($reject, $stream, $timeout, $watcherId) {
                    $this->loop->removeStreamWatcher($watcherId);
                    $reject(new TimeoutException("Read operation timed out after {$timeout} seconds."));
                });
            }
        });
    }

    /**
     * Read a line asynchronously from a stream.
     *
     * Reads data until a newline character is encountered or the specified
     * length is reached. The promise resolves when a complete line is available.
     *
     * @param  resource  $stream  The stream to read from
     * @param  int|null  $length  Maximum number of bytes to read (default: 8192)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<string|null> A promise that resolves with the line read, or null if stream is at EOF
     *
     * @throws StreamException If the stream is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public function readLine($stream, ?int $length = null, ?float $timeout = 10.0): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        $readLength = $length ?? self::DEFAULT_BYTE_SIZE;

        return new Promise(function ($resolve, $reject) use ($stream, $readLength, $timeout) {
            $timerId = null;
            $buffer = '';

            $readCallback = function () use ($stream, $readLength, $resolve, $reject, &$timerId, &$buffer) {
                $data = @fread($stream, 1024); // Read in chunks
                
                if ($data === false) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $reject(new StreamException('Failed to read from stream.'));
                    return;
                }

                if ($data === '' && feof($stream)) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $resolve($buffer === '' ? null : $buffer);
                    return;
                }

                $buffer .= $data;
                
                // Check if we have a complete line
                $newlinePos = strpos($buffer, "\n");
                if ($newlinePos !== false) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $line = substr($buffer, 0, $newlinePos + 1);
                    $resolve($line);
                    return;
                }

                // Check if we've reached the max length
                if (strlen($buffer) >= $readLength) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $resolve($buffer);
                    return;
                }

                // Continue reading
            };

            $watcherId = $this->loop->addStreamWatcher($stream, $readCallback, 'read');

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($reject, $stream, $timeout, $watcherId) {
                    $this->loop->removeStreamWatcher($watcherId);
                    $reject(new TimeoutException("Read line operation timed out after {$timeout} seconds."));
                });
            }
        });
    }

    /**
     * Read all data asynchronously from a stream.
     *
     * Continues reading data until EOF is reached. The promise resolves
     * with all data read from the stream.
     *
     * @param  resource  $stream  The stream to read from
     * @param  int  $maxLength  Maximum total bytes to read (default: 1MB)
     * @param  float|null  $timeout  Read timeout in seconds. Null or 0 for no timeout. Defaults to 30.0 seconds
     * @return PromiseInterface<string> A promise that resolves with all data read from the stream
     *
     * @throws StreamException If the stream is closed or reading fails
     * @throws TimeoutException If the read operation times out
     */
    public function readAll($stream, int $maxLength = 1048576, ?float $timeout = 30.0): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        if ($maxLength > self::MAX_BUFFER_SIZE) {
            return $this->asyncOps->rejected(new StreamException('Maximum buffer size exceeded.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream, $maxLength, $timeout) {
            $buffer = '';
            $timerId = null;
            $startTime = microtime(true);

            $readCallback = function () use ($stream, $maxLength, $timeout, $resolve, $reject, &$buffer, &$timerId, $startTime) {
                if ($timeout > 0 && (microtime(true) - $startTime) > $timeout) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $reject(new TimeoutException("Read all operation timed out after {$timeout} seconds."));
                    return;
                }

                $data = @fread($stream, min(self::DEFAULT_BYTE_SIZE, $maxLength - strlen($buffer)));
                
                if ($data === false) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $reject(new StreamException('Failed to read from stream.'));
                    return;
                }

                $buffer .= $data;

                if (feof($stream) || strlen($buffer) >= $maxLength) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
                    $resolve($buffer);
                    return;
                }
            };

            $watcherId = $this->loop->addStreamWatcher($stream, $readCallback, 'read');

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($reject, $stream, $timeout, $watcherId) {
                    $this->loop->removeStreamWatcher($watcherId);
                    $reject(new TimeoutException("Read all operation timed out after {$timeout} seconds."));
                });
            }
        });
    }

    /**
     * Write data asynchronously to a stream.
     *
     * Writes the specified data to the stream. Handles partial writes by
     * recursively continuing until all data is written.
     *
     * @param  resource  $stream  The stream to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes written
     *
     * @throws StreamException If the stream is closed or writing fails
     * @throws TimeoutException If the write operation times out
     */
    public function write($stream, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream, $data, $timeout) {
            $timerId = null;
            $totalBytesWritten = 0;

            $this->performWrite($stream, $data, $resolve, $reject, $timerId, $totalBytesWritten);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($stream, $reject) {
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'write'));
                    $reject(new TimeoutException('Write operation timed out.'));
                });
            }
        });
    }

    /**
     * Write a line asynchronously to a stream.
     *
     * Appends a newline character to the data and writes it to the stream.
     *
     * @param  resource  $stream  The stream to write to
     * @param  string  $data  The data to write
     * @param  float|null  $timeout  Write timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes written
     *
     * @throws StreamException If the stream is closed or writing fails
     * @throws TimeoutException If the write operation times out
     */
    public function writeLine($stream, string $data, ?float $timeout = 10.0): PromiseInterface
    {
        return $this->write($stream, $data . "\n", $timeout);
    }

    /**
     * Seek to a position in a stream asynchronously.
     *
     * Moves the stream pointer to the specified position.
     *
     * @param  resource  $stream  The stream to seek in
     * @param  int  $offset  The offset to seek to
     * @param  int  $whence  How to interpret the offset (SEEK_SET, SEEK_CUR, or SEEK_END)
     * @return PromiseInterface<int> A promise that resolves with the new stream position
     *
     * @throws StreamException If the stream doesn't support seeking or seeking fails
     */
    public function seek($stream, int $offset, int $whence = SEEK_SET): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream, $offset, $whence) {
            $this->loop->defer(function () use ($stream, $offset, $whence, $resolve, $reject) {
                $result = @fseek($stream, $offset, $whence);
                
                if ($result === -1) {
                    $reject(new StreamException('Failed to seek in stream.'));
                    return;
                }

                $position = @ftell($stream);
                if ($position === false) {
                    $reject(new StreamException('Failed to get stream position after seek.'));
                    return;
                }

                $resolve($position);
            });
        });
    }

    /**
     * Get the current position in a stream asynchronously.
     *
     * @param  resource  $stream  The stream to get position from
     * @return PromiseInterface<int> A promise that resolves with the current stream position
     *
     * @throws StreamException If getting the position fails
     */
    public function tell($stream): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream) {
            $this->loop->defer(function () use ($stream, $resolve, $reject) {
                $position = @ftell($stream);
                
                if ($position === false) {
                    $reject(new StreamException('Failed to get stream position.'));
                    return;
                }

                $resolve($position);
            });
        });
    }

    /**
     * Check if a stream is at EOF asynchronously.
     *
     * @param  resource  $stream  The stream to check
     * @return PromiseInterface<bool> A promise that resolves with true if at EOF, false otherwise
     *
     * @throws StreamException If checking EOF status fails
     */
    public function eof($stream): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream) {
            $this->loop->defer(function () use ($stream, $resolve) {
                $isEof = feof($stream);
                $resolve($isEof);
            });
        });
    }

    /**
     * Flush a stream asynchronously.
     *
     * Forces any buffered data to be written to the underlying storage.
     *
     * @param  resource  $stream  The stream to flush
     * @param  float|null  $timeout  Flush timeout in seconds. Null or 0 for no timeout. Defaults to 10.0 seconds
     * @return PromiseInterface<bool> A promise that resolves with true on successful flush
     *
     * @throws StreamException If flushing fails
     * @throws TimeoutException If the flush operation times out
     */
    public function flush($stream, ?float $timeout = 10.0): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream, $timeout) {
            $timerId = null;

            $flushCallback = function () use ($stream, $resolve, $reject, &$timerId) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }

                $result = @fflush($stream);
                if ($result === false) {
                    $reject(new StreamException('Failed to flush stream.'));
                } else {
                    $resolve(true);
                }
            };

            $this->loop->defer($flushCallback);

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($reject) {
                    $reject(new TimeoutException('Flush operation timed out.'));
                });
            }
        });
    }

    /**
     * Close a stream resource.
     *
     * Closes the stream and removes all event loop watchers
     * associated with the stream to prevent memory leaks.
     *
     * @param  resource  $stream  The stream to close
     * @return bool True if the stream was successfully closed
     */
    public function close($stream): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        // Remove any watchers for this stream
        $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'read'));
        $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'write'));

        return @fclose($stream);
    }

    /**
     * Copy data from one stream to another asynchronously.
     *
     * @param  resource  $source  The source stream to copy from
     * @param  resource  $destination  The destination stream to copy to
     * @param  int|null  $maxBytes  Maximum number of bytes to copy (null for all)
     * @param  float|null  $timeout  Copy timeout in seconds. Null or 0 for no timeout. Defaults to 30.0 seconds
     * @return PromiseInterface<int> A promise that resolves with the number of bytes copied
     *
     * @throws StreamException If copying fails
     * @throws TimeoutException If the copy operation times out
     */
    public function copy($source, $destination, ?int $maxBytes = null, ?float $timeout = 30.0): PromiseInterface
    {
        if (!is_resource($source) || !is_resource($destination)) {
            return $this->asyncOps->rejected(new StreamException('Source and destination must be valid resources.'));
        }

        return new Promise(function ($resolve, $reject) use ($source, $destination, $maxBytes, $timeout) {
            $totalBytesCopied = 0;
            $timerId = null;
            $startTime = microtime(true);

            $copyCallback = function () use ($source, $destination, $maxBytes, $timeout, $resolve, $reject, &$totalBytesCopied, &$timerId, $startTime) {
                if ($timeout > 0 && (microtime(true) - $startTime) > $timeout) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($source, 'read'));
                    $reject(new TimeoutException("Copy operation timed out after {$timeout} seconds."));
                    return;
                }

                $remainingBytes = $maxBytes ? ($maxBytes - $totalBytesCopied) : self::DEFAULT_BYTE_SIZE;
                $bytesToRead = min(self::DEFAULT_BYTE_SIZE, $remainingBytes);
                
                if ($bytesToRead <= 0) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($source, 'read'));
                    $resolve($totalBytesCopied);
                    return;
                }

                $data = @fread($source, $bytesToRead);
                
                if ($data === false) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($source, 'read'));
                    $reject(new StreamException('Failed to read from source stream.'));
                    return;
                }

                if ($data === '' && feof($source)) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($source, 'read'));
                    $resolve($totalBytesCopied);
                    return;
                }

                $bytesWritten = @fwrite($destination, $data);
                if ($bytesWritten === false) {
                    if ($timerId) {
                        $this->loop->cancelTimer($timerId);
                    }
                    $this->loop->removeStreamWatcher($this->getStreamWatcherId($source, 'read'));
                    $reject(new StreamException('Failed to write to destination stream.'));
                    return;
                }

                $totalBytesCopied += $bytesWritten;

                // Continue copying
            };

            $watcherId = $this->loop->addStreamWatcher($source, $copyCallback, 'read');

            if ($timeout > 0) {
                $timerId = $this->loop->addTimer($timeout, function () use ($reject, $source, $timeout, $watcherId) {
                    $this->loop->removeStreamWatcher($watcherId);
                    $reject(new TimeoutException("Copy operation timed out after {$timeout} seconds."));
                });
            }
        });
    }

    /**
     * Get stream metadata asynchronously.
     *
     * @param  resource  $stream  The stream to get metadata from
     * @return PromiseInterface<array> A promise that resolves with stream metadata
     *
     * @throws StreamException If getting metadata fails
     */
    public function getMetadata($stream): PromiseInterface
    {
        if (!is_resource($stream)) {
            return $this->asyncOps->rejected(new StreamException('Stream is not a valid resource.'));
        }

        return new Promise(function ($resolve, $reject) use ($stream) {
            $this->loop->defer(function () use ($stream, $resolve, $reject) {
                $metadata = @stream_get_meta_data($stream);
                
                if ($metadata === false) {
                    $reject(new StreamException('Failed to get stream metadata.'));
                    return;
                }

                $resolve($metadata);
            });
        });
    }

    /**
     * Set stream blocking mode.
     *
     * @param  resource  $stream  The stream to modify
     * @param  bool  $blocking  Whether the stream should be blocking
     * @return bool True if the blocking mode was set successfully
     */
    public function setBlocking($stream, bool $blocking = false): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        return @stream_set_blocking($stream, $blocking);
    }

    /**
     * Set stream timeout.
     *
     * @param  resource  $stream  The stream to modify
     * @param  int  $seconds  Timeout in seconds
     * @param  int  $microseconds  Additional microseconds for timeout
     * @return bool True if the timeout was set successfully
     */
    public function setTimeout($stream, int $seconds, int $microseconds = 0): bool
    {
        if (!is_resource($stream)) {
            return false;
        }

        return @stream_set_timeout($stream, $seconds, $microseconds);
    }

    /**
     * Check if a filename represents a network stream.
     *
     * @param  string  $filename  The filename to check
     * @return bool True if it's a network stream
     */
    private function isNetworkStream(string $filename): bool
    {
        return str_starts_with($filename, 'http://') || 
               str_starts_with($filename, 'https://') ||
               str_starts_with($filename, 'tcp://') ||
               str_starts_with($filename, 'udp://') ||
               str_starts_with($filename, 'ssl://') ||
               str_starts_with($filename, 'tls://');
    }

    /**
     * Wait for a stream to be ready for operations.
     *
     * @param  resource  $stream  The stream to wait for
     * @param  callable  $resolve  Promise resolve callback
     * @param  callable  $reject  Promise reject callback
     * @param  float|null  $timeout  Timeout in seconds
     */
    private function waitForStreamReady($stream, callable $resolve, callable $reject, ?float $timeout): void
    {
        $timerId = null;

        $readyCallback = function () use ($stream, $resolve, &$timerId) {
            if ($timerId) {
                $this->loop->cancelTimer($timerId);
            }
            $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'write'));
            $resolve($stream);
        };

        $watcherId = $this->loop->addStreamWatcher($stream, $readyCallback, 'write');

        if ($timeout > 0) {
            $timerId = $this->loop->addTimer($timeout, function () use ($stream, $reject, $timeout, $watcherId) {
                $this->loop->removeStreamWatcher($watcherId);
                @fclose($stream);
                $reject(new TimeoutException("Stream open timed out after {$timeout} seconds."));
            });
        }
    }

    /**
     * Perform the actual write operation with support for partial writes.
     *
     * This private method handles the recursive writing of data, continuing
     * to write remaining data if the initial write is partial.
     *
     * @param  resource  $stream  The stream to write to
     * @param  string  $data  The data to write
     * @param  callable  $resolve  Promise resolve callback
     * @param  callable  $reject  Promise reject callback
     * @param  string|null  $timerId  Reference to the timer ID for timeout management
     * @param  int  $totalBytesWritten  Total bytes written so far
     *
     * @throws StreamException If writing to the stream fails
     */
    private function performWrite($stream, string $data, callable $resolve, callable $reject, ?string &$timerId, int &$totalBytesWritten): void
    {
        $writeCallback = function () use ($stream, $data, $resolve, $reject, &$timerId, &$totalBytesWritten) {
            $bytesWritten = @fwrite($stream, $data);

            if ($bytesWritten === false) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }
                $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'write'));
                $reject(new StreamException('Failed to write to stream.'));
                return;
            }

            $totalBytesWritten += $bytesWritten;

            if ($bytesWritten === strlen($data)) {
                if ($timerId) {
                    $this->loop->cancelTimer($timerId);
                }
                $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'write'));
                $resolve($totalBytesWritten);
                return;
            }

            // Partial write - continue with remaining data
            $remainingData = substr($data, $bytesWritten);
            $this->loop->removeStreamWatcher($this->getStreamWatcherId($stream, 'write'));
            $this->performWrite($stream, $remainingData, $resolve, $reject, $timerId, $totalBytesWritten);
        };

        $this->loop->addStreamWatcher($stream, $writeCallback, 'write');
    }

    /**
     * Generate a unique watcher ID for a stream and operation type.
     *
     * @param  resource  $stream  The stream resource
     * @param  string  $type  The operation type ('read' or 'write')
     * @return string The generated watcher ID
     */
    private function getStreamWatcherId($stream, string $type): string
    {
        return 'stream_' . (int)$stream . '_' . $type;
    }
}