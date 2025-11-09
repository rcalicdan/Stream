<?php

use Hibla\EventLoop\Loop;
use Hibla\Stream\ThroughStream;
use Hibla\Stream\WritableStream;
use Hibla\Stream\Exceptions\StreamException;

describe('ThroughStream', function () {
    test('can be created without transformer', function () {
        $stream = new ThroughStream();

        expect($stream)->toBeInstanceOf(ThroughStream::class);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();
        expect($stream->isPaused())->toBeFalse();
        expect($stream->isEnding())->toBeFalse();

        $stream->close();
    });

    test('can be created with transformer', function () {
        $stream = new ThroughStream(fn($data) => strtoupper($data));

        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();

        $stream->close();
    });

    test('can write data', function () {
        $stream = new ThroughStream();

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $bytesWritten = $stream->write('Hello World')->await();

        expect($received)->toBe('Hello World');
        expect($bytesWritten)->toBeGreaterThan(0);

        $stream->close();
    });

    test('can write multiple chunks', function () {
        $stream = new ThroughStream();

        $chunks = [];
        $stream->on('data', function ($data) use (&$chunks) {
            $chunks[] = $data;
        });

        $stream->write('chunk1')->await();
        $stream->write('chunk2')->await();
        $stream->write('chunk3')->await();

        expect($chunks)->toBe(['chunk1', 'chunk2', 'chunk3']);

        $stream->close();
    });

    test('writeLine adds newline', function () {
        $stream = new ThroughStream();

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->writeLine('Hello')->await();

        expect($received)->toBe("Hello\n");

        $stream->close();
    });

    test('transforms data when transformer is provided', function () {
        $stream = new ThroughStream(fn($data) => strtoupper($data));

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->write('hello world')->await();

        expect($received)->toBe('HELLO WORLD');

        $stream->close();
    });

    test('transformer can modify data length', function () {
        $stream = new ThroughStream(fn($data) => str_repeat($data, 2));

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->write('test')->await();

        expect($received)->toBe('testtest');

        $stream->close();
    });

    test('can end stream', function () {
        $stream = new ThroughStream();

        $ended = false;
        $finished = false;
        $closed = false;

        $stream->on('end', function () use (&$ended) {
            $ended = true;
        });

        $stream->on('finish', function () use (&$finished) {
            $finished = true;
        });

        $stream->on('close', function () use (&$closed) {
            $closed = true;
        });

        $stream->end()->await();

        expect($ended)->toBeTrue();
        expect($finished)->toBeTrue();
        expect($closed)->toBeTrue();
        expect($stream->isReadable())->toBeFalse();
        expect($stream->isWritable())->toBeFalse();
    });

    test('can end with final data', function () {
        $stream = new ThroughStream();

        $chunks = [];
        $stream->on('data', function ($data) use (&$chunks) {
            $chunks[] = $data;
        });

        $stream->write('first')->await();
        $stream->end('last')->await();

        expect($chunks)->toBe(['first', 'last']);
    });

    test('ending multiple times is safe', function () {
        $stream = new ThroughStream();

        $stream->end()->await();
        $stream->end()->await();
        $stream->end()->await();

        expect($stream->isEnding())->toBeTrue();
        expect($stream->isWritable())->toBeFalse();
    });

    test('cannot write after ending', function () {
        $stream = new ThroughStream();

        $stream->end()->await();

        $rejected = false;
        try {
            $stream->write('test')->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('not writable');
        }

        expect($rejected)->toBeTrue();
    });

    test('cannot write after closing', function () {
        $stream = new ThroughStream();

        $stream->close();

        $rejected = false;
        try {
            $stream->write('test')->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('not writable');
        }

        expect($rejected)->toBeTrue();
    });

    test('can pause stream', function () {
        $stream = new ThroughStream();

        $paused = false;
        $stream->on('pause', function () use (&$paused) {
            $paused = true;
        });

        $stream->pause();

        expect($stream->isPaused())->toBeTrue();
        expect($paused)->toBeTrue();

        $stream->close();
    });

    test('can resume stream', function () {
        $stream = new ThroughStream();

        $resumed = false;
        $stream->on('resume', function () use (&$resumed) {
            $resumed = true;
        });

        $stream->pause();
        $stream->resume();

        expect($stream->isPaused())->toBeFalse();
        expect($resumed)->toBeTrue();

        $stream->close();
    });

    test('emits drain after resume when draining', function () {
        $stream = new ThroughStream();

        $drained = false;
        $stream->on('drain', function () use (&$drained) {
            $drained = true;
        });

        $stream->pause();
        $stream->write('test')->await(); 
        $stream->resume();

        expect($drained)->toBeTrue();

        $stream->close();
    });

    test('pause when not readable does nothing', function () {
        $stream = new ThroughStream();

        $stream->end()->await();
        $stream->pause();

        expect($stream->isReadable())->toBeFalse();
    });

    test('resume when not readable does nothing', function () {
        $stream = new ThroughStream();

        $stream->end()->await();
        $stream->resume();

        expect($stream->isReadable())->toBeFalse();
    });

    test('isEof returns correct state', function () {
        $stream = new ThroughStream();

        expect($stream->isEof())->toBeFalse();

        $stream->end()->await();

        expect($stream->isEof())->toBeTrue();
    });

    test('can close stream', function () {
        $stream = new ThroughStream();

        $closed = false;
        $stream->on('close', function () use (&$closed) {
            $closed = true;
        });

        $stream->close();

        expect($closed)->toBeTrue();
        expect($stream->isReadable())->toBeFalse();
        expect($stream->isWritable())->toBeFalse();
    });

    test('closing multiple times is safe', function () {
        $stream = new ThroughStream();

        $closeCount = 0;
        $stream->on('close', function () use (&$closeCount) {
            $closeCount++;
        });

        $stream->close();
        $stream->close();
        $stream->close();

        expect($closeCount)->toBe(1);
    });

    test('read method throws error', function () {
        $stream = new ThroughStream();

        $rejected = false;
        try {
            $stream->read()->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('does not support read()');
        }

        expect($rejected)->toBeTrue();
        $stream->close();
    });

    test('readLine method throws error', function () {
        $stream = new ThroughStream();

        $rejected = false;
        try {
            $stream->readLine()->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('does not support readLine()');
        }

        expect($rejected)->toBeTrue();
        $stream->close();
    });

    test('readAll method throws error', function () {
        $stream = new ThroughStream();

        $rejected = false;
        try {
            $stream->readAll()->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('does not support readAll()');
        }

        expect($rejected)->toBeTrue();
        $stream->close();
    });

    test('emits error when transformer throws', function () {
        $stream = new ThroughStream(function ($data) {
            throw new \Exception('Transform error');
        });

        $error = null;
        $stream->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $rejected = false;
        try {
            $stream->write('test')->await();
        } catch (\Throwable $e) {
            $rejected = true;
        }

        expect($error)->toBeInstanceOf(\Exception::class);
        expect($error->getMessage())->toBe('Transform error');
        expect($rejected)->toBeTrue();
    });

    test('handles backpressure with pause', function () {
        $stream = new ThroughStream();

        $stream->pause();

        $result = $stream->write('test')->await();

        expect($result)->toBe(0); // Indicates backpressure
        expect($stream->isPaused())->toBeTrue();

        $stream->close();
    });

    test('write returns data length when not paused', function () {
        $stream = new ThroughStream();

        $result = $stream->write('test data')->await();

        expect($result)->toBe(strlen('test data'));

        $stream->close();
    });

    test('can pipe to writable stream', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableStream($resource);

        $stream = new ThroughStream();

        $totalBytes = 0;
        $stream->pipe($destination)->then(function ($bytes) use (&$totalBytes) {
            $totalBytes = $bytes;
        });

        $stream->write('test data')->await();
        $stream->end()->await();

        Loop::run();

        expect(file_get_contents($file))->toBe('test data');
        expect($totalBytes)->toBeGreaterThan(0);

        cleanupTempFile($file);
    });

    test('pipe ends destination by default', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableStream($resource);

        $stream = new ThroughStream();

        $closed = false;
        $destination->on('close', function () use (&$closed) {
            $closed = true;
        });

        $stream->pipe($destination);
        $stream->end()->await();

        Loop::run();

        expect($closed)->toBeTrue();

        cleanupTempFile($file);
    });

    test('pipe does not end destination when end option is false', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableStream($resource);

        $stream = new ThroughStream();

        $stream->pipe($destination, ['end' => false]);
        $stream->end()->await();

        Loop::run();

        expect($destination->isWritable())->toBeTrue();

        $destination->close();
        cleanupTempFile($file);
    });

    test('cannot pipe when not readable', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableStream($resource);

        $stream = new ThroughStream();
        $stream->end()->await();

        $rejected = false;
        try {
            $stream->pipe($destination)->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('not readable');
        }

        expect($rejected)->toBeTrue();

        $destination->close();
        cleanupTempFile($file);
    });

    test('handles empty write', function () {
        $stream = new ThroughStream();

        $dataEmitted = false;
        $stream->on('data', function () use (&$dataEmitted) {
            $dataEmitted = true;
        });

        $stream->write('')->await();

        expect($dataEmitted)->toBeTrue(); // Empty string should still emit

        $stream->close();
    });

    test('maintains data order', function () {
        $stream = new ThroughStream();

        $received = [];
        $stream->on('data', function ($data) use (&$received) {
            $received[] = $data;
        });

        for ($i = 1; $i <= 10; $i++) {
            $stream->write("chunk$i")->await();
        }

        expect($received)->toBe([
            'chunk1', 'chunk2', 'chunk3', 'chunk4', 'chunk5',
            'chunk6', 'chunk7', 'chunk8', 'chunk9', 'chunk10'
        ]);

        $stream->close();
    });

    test('removes all listeners on close', function () {
        $stream = new ThroughStream();

        $stream->on('data', fn() => null);
        $stream->on('end', fn() => null);
        $stream->on('error', fn() => null);

        $stream->close();

        $rejected = false;
        try {
            $stream->write('test')->await();
        } catch (\Throwable $e) {
            $rejected = true;
            expect($e)->toBeInstanceOf(StreamException::class);
        }

        expect($rejected)->toBeTrue();
    });

    test('transformer with conditional logic', function () {
        $stream = new ThroughStream(function ($data) {
            if (str_starts_with($data, 'ERROR:')) {
                return strtoupper($data);
            }
            return strtolower($data);
        });

        $results = [];
        $stream->on('data', function ($data) use (&$results) {
            $results[] = $data;
        });

        $stream->write('Normal Text')->await();
        $stream->write('ERROR: Something went wrong')->await();

        expect($results[0])->toBe('normal text');
        expect($results[1])->toBe('ERROR: SOMETHING WENT WRONG');

        $stream->close();
    });

    test('handles null transformer as passthrough', function () {
        $stream = new ThroughStream(null);

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->write('unchanged')->await();

        expect($received)->toBe('unchanged');

        $stream->close();
    });
});