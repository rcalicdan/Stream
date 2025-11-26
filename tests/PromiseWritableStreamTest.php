<?php

declare(strict_types=1);

use Hibla\Stream\PromiseWritableStream;

test('writes data to file', function () {
    $file = createTempFile();
    $resource = fopen($file, 'w');
    stream_set_blocking($resource, false);
    $stream = PromiseWritableStream::fromResource($resource);

    $stream->writeAsync('Hello, World!')->await();
    $stream->endAsync()->await();
    $content = file_get_contents($file);
    cleanupFile($file);

    expect($content)->toBe('Hello, World!');
});

test('writes multiple chunks', function () {
    $file = createTempFile();
    $resource = fopen($file, 'w');
    stream_set_blocking($resource, false);
    $stream = PromiseWritableStream::fromResource($resource);

    $stream->writeAsync('First ')->await();
    $stream->writeAsync('Second ')->await();
    $stream->writeAsync('Third')->await();
    $stream->endAsync()->await();
    $content = file_get_contents($file);
    cleanupFile($file);

    expect($content)->toBe('First Second Third');
});

test('writes line with newline', function () {
    $file = createTempFile();
    $resource = fopen($file, 'w');
    stream_set_blocking($resource, false);
    $stream = PromiseWritableStream::fromResource($resource);

    $stream->writeLineAsync('Line 1')->await();
    $stream->writeLineAsync('Line 2')->await();
    $stream->endAsync()->await();
    $content = file_get_contents($file);
    cleanupFile($file);

    expect($content)->toBe("Line 1\nLine 2\n");
});

test('ends stream with data', function () {
    $file = createTempFile();
    $resource = fopen($file, 'w');
    stream_set_blocking($resource, false);
    $stream = PromiseWritableStream::fromResource($resource);

    $stream->writeAsync('First')->await();
    $stream->endAsync(' Last')->await();
    $content = file_get_contents($file);
    cleanupFile($file);

    expect($content)->toBe('First Last');
});

test('writes large data', function () {
    $file = createTempFile();
    $resource = fopen($file, 'w');
    stream_set_blocking($resource, false);
    $stream = PromiseWritableStream::fromResource($resource);

    $content = str_repeat('Z', 50000);
    $stream->writeAsync($content)->await();
    $stream->endAsync()->await();
    $written = file_get_contents($file);
    cleanupFile($file);

    expect(strlen($written))->toBe(50000);
});

test('checks writable stream state', function () {
    $file = createTempFile();
    $resource = fopen($file, 'w');
    stream_set_blocking($resource, false);
    $stream = PromiseWritableStream::fromResource($resource);

    $writable = $stream->isWritable();
    $stream->endAsync()->await();
    $notWritable = ! $stream->isWritable();
    cleanupFile($file);

    expect($writable)->toBeTrue()
        ->and($notWritable)->toBeTrue()
    ;
});
