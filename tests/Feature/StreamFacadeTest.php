<?php

declare(strict_types=1);

use Hibla\Stream\ReadableStream;
use Hibla\Stream\Stream;
use Hibla\Stream\WritableStream;

describe('Stream Factory', function () {
    test('can create readable file stream', function () {
        $file = createTempFile('test content');

        $stream = Stream::readableFile($file);

        expect($stream)->toBeInstanceOf(ReadableStream::class);
        expect($stream->isReadable())->toBeTrue();

        $content = $stream->read()->await();

        expect($content)->toBe('test content');

        $stream->close();
        cleanupTempFile($file);
    });

    test('can create writable file stream', function () {
        $file = createTempFile();

        $stream = Stream::writableFile($file);

        expect($stream)->toBeInstanceOf(WritableStream::class);
        expect($stream->isWritable())->toBeTrue();

        $stream->write('test content')->await();
        $stream->close();

        expect(file_get_contents($file))->toBe('test content');

        cleanupTempFile($file);
    });

    test('can create writable file stream in append mode', function () {
        $file = createTempFile('existing ');

        $stream = Stream::writableFile($file, true);

        $stream->write('appended')->await();
        $stream->close();

        expect(file_get_contents($file))->toBe('existing appended');

        cleanupTempFile($file);
    });

    test('can create readable stream from resource', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');

        $stream = Stream::readable($resource);

        expect($stream)->toBeInstanceOf(ReadableStream::class);

        $stream->close();
        cleanupTempFile($file);
    });

    test('can create writable stream from resource', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');

        $stream = Stream::writable($resource);

        expect($stream)->toBeInstanceOf(WritableStream::class);

        $stream->close();
        cleanupTempFile($file);
    });
});
