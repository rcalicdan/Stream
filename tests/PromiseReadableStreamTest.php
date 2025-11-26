<?php

declare(strict_types=1);

use Hibla\Stream\PromiseReadableStream;

test('reads data from file', function () {
    $file = createTempFile('Hello, World!');
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $data = $stream->readAsync()->await();
    $stream->close();
    cleanupFile($file);

    expect($data)->toBe('Hello, World!');
});

test('reads in chunks', function () {
    $file = createTempFile('ABCDEFGHIJ');
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource, 5);

    $chunk1 = $stream->readAsync(5)->await();
    $chunk2 = $stream->readAsync(5)->await();
    $stream->close();
    cleanupFile($file);

    expect($chunk1)->toBe('ABCDE')
        ->and($chunk2)->toBe('FGHIJ')
    ;
});

test('reads line by line', function () {
    $file = createTempFile("Line 1\nLine 2\nLine 3");
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $line1 = $stream->readLineAsync()->await();
    $line2 = $stream->readLineAsync()->await();
    $stream->close();
    cleanupFile($file);

    expect($line1)->toBe("Line 1\n")
        ->and($line2)->toBe("Line 2\n")
    ;
});

test('reads all content', function () {
    $content = str_repeat('A', 1000);
    $file = createTempFile($content);
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $data = $stream->readAllAsync()->await();
    $stream->close();
    cleanupFile($file);

    expect($data)->toBe($content);
});

test('returns null at EOF', function () {
    $file = createTempFile('short');
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $stream->readAsync()->await();
    $data = $stream->readAsync()->await();
    $stream->close();
    cleanupFile($file);

    expect($data)->toBeNull();
});

test('handles large files', function () {
    $content = str_repeat('X', 50000);
    $file = createTempFile($content);
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $data = $stream->readAllAsync()->await();
    $stream->close();
    cleanupFile($file);

    expect(strlen($data))->toBe(50000);
});

test('returns null for empty file', function () {
    $file = createTempFile('');
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $data = $stream->readAsync()->await();
    $stream->close();
    cleanupFile($file);

    expect($data)->toBeNull();
});

test('checks stream states', function () {
    $file = createTempFile('test');
    $resource = fopen($file, 'r');
    $stream = PromiseReadableStream::fromResource($resource);

    $readable = $stream->isReadable();
    $paused = $stream->isPaused();
    $stream->close();
    cleanupFile($file);

    expect($readable)->toBeTrue()
        ->and($paused)->toBeTrue()
    ;
});
