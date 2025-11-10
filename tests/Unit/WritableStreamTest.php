<?php

declare(strict_types=1);

use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\WritableStream;

describe('WritableStream', function () {
    test('can be created from a writable resource', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');

        $stream = new WritableStream($resource);

        expect($stream)->toBeInstanceOf(WritableStream::class);
        expect($stream->isWritable())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('throws exception for invalid resource', function () {
        new WritableStream('not a resource');
    })->throws(StreamException::class, 'Invalid resource provided');

    test('throws exception for non-writable resource', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');

        try {
            new WritableStream($resource);
        } finally {
            fclose($resource);
            cleanupTempFile($file);
        }
    })->throws(StreamException::class, 'Resource is not writable');

    test('can write data', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $content = 'Hello, World!';
        $bytesWritten = $stream->write($content)->await();

        $stream->close();

        expect($bytesWritten)->toBe(strlen($content));
        expect(file_get_contents($file))->toBe($content);

        cleanupTempFile($file);
    });

    test('can write multiple chunks', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $chunks = ['First ', 'Second ', 'Third'];
        $totalWritten = 0;

        foreach ($chunks as $chunk) {
            $totalWritten += $stream->write($chunk)->await();
        }

        $stream->close();

        expect($totalWritten)->toBe(strlen(implode('', $chunks)));
        expect(file_get_contents($file))->toBe(implode('', $chunks));

        cleanupTempFile($file);
    });

    test('can write lines', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $lines = ['First line', 'Second line'];

        $stream->writeLine($lines[0])->await();
        $stream->writeLine($lines[1])->await();

        $stream->close();

        $expected = implode("\n", $lines) . "\n";
        expect(file_get_contents($file))->toBe($expected);

        cleanupTempFile($file);
    });

    test('can end stream with data', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $finishEmitted = false;
        $stream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
        });

        $stream->end('Final data')->await();

        expect($finishEmitted)->toBeTrue();
        expect($stream->isWritable())->toBeFalse();
        expect(file_get_contents($file))->toBe('Final data');

        cleanupTempFile($file);
    });

    test('can end stream without data', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $stream->write('Some data')->await();
        $stream->end()->await();

        expect($stream->isWritable())->toBeFalse();
        expect(file_get_contents($file))->toBe('Some data');

        cleanupTempFile($file);
    });

    test('write returns 0 for empty string', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $bytes = $stream->write('')->await();

        expect($bytes)->toBe(0);

        $stream->close();
        cleanupTempFile($file);
    });

    test('emits drain event', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource, 1024); // Small buffer

        $drainEmitted = false;
        $stream->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
        });

        $largeData = str_repeat('X', 5000);
        $stream->write($largeData)->await();

        expect($drainEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('can be closed', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        expect($stream->isWritable())->toBeTrue();

        $stream->close();

        expect($stream->isWritable())->toBeFalse();

        cleanupTempFile($file);
    });

    test('emits close event', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $closeEmitted = false;
        $stream->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $stream->close();

        expect($closeEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('write throws exception after close', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $stream->close();

        try {
            $stream->write('test')->await();
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('not writable');
        }

        cleanupTempFile($file);
    });

    test('can cancel write operation', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $promise = $stream->write(str_repeat('X', 100000));

        // Cancel immediately
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('handles large data efficiently', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $startMem = memory_get_usage();
        $largeData = str_repeat('X', 1024 * 1024); // 1MB

        $stream->write($largeData)->await();
        $stream->close();

        $memUsed = memory_get_usage() - $startMem;

        expect($memUsed)->toBeLessThan(2 * 1024 * 1024); // Less than 2MB
        expect(filesize($file))->toBe(strlen($largeData));

        cleanupTempFile($file);
    });

    test('ending flag is set correctly', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        expect($stream->isEnding())->toBeFalse();

        $promise = $stream->end('test');

        expect($stream->isEnding())->toBeTrue();

        $promise->await();

        cleanupTempFile($file);
    });

    test('cannot write after ending', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $stream = new WritableStream($resource);

        $stream->end()->await();

        try {
            $stream->write('more data')->await();
        } catch (Throwable $e) {
            expect($e)->toBeInstanceOf(StreamException::class);
        }

        cleanupTempFile($file);
    });
});
