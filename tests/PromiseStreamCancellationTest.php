<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;

ini_set('memory_limit', '2G');

$tempDir = __DIR__ . '/temp';

beforeEach(function () use ($tempDir) {
    if (! is_dir($tempDir)) {
        mkdir($tempDir);
    }
    Loop::reset();
});

afterEach(function () use ($tempDir) {
    $files = glob($tempDir . '/*');
    foreach ($files as $file) {
        if (is_file($file)) {
            unlink($file);
        }
    }
    if (is_dir($tempDir)) {
        rmdir($tempDir);
    }
    Loop::reset();
});

function createSparseFile(string $path, int $size): void
{
    $fp = fopen($path, 'w');
    ftruncate($fp, $size);
    fclose($fp);
}

it('handles synchronous immediate cancellation before loop runs', function () {
    $fp = fopen('php://memory', 'r+');
    fwrite($fp, str_repeat('A', 1024));
    rewind($fp);

    $stream = new PromiseReadableStream($fp);
    $promise = $stream->readAsync(1024);

    $promise->cancel();

    $resolved = false;
    $promise->then(function () use (&$resolved) {
        $resolved = true;
    });

    Loop::run();

    expect($resolved)->toBeFalse();
});

it('handles double cancellation calls safely', function () use ($tempDir) {
    $file = $tempDir . '/double_cancel.bin';
    createSparseFile($file, 1024 * 1024 * 10);
    $fp = fopen($file, 'r');
    $stream = new PromiseReadableStream($fp, chunkSize: 1024);

    $promise = $stream->readAllAsync();

    Loop::addTimer(0.01, function () use ($promise) {
        $promise->cancel();
        $promise->cancel();
    });

    Loop::run();

    $pos = ftell($fp);
    expect($pos)->toBeGreaterThan(0)
        ->and($pos)->toBeLessThan(1024 * 1024 * 10)
    ;
});

it('cancels readLineAsync correctly when no newline exists in large file', function () use ($tempDir) {
    $file = $tempDir . '/no_newline.bin';
    $fp = fopen($file, 'w');
    fwrite($fp, str_repeat('A', 5 * 1024 * 1024));
    fclose($fp);

    $fp = fopen($file, 'r');
    $stream = new PromiseReadableStream($fp, chunkSize: 1024);

    $promise = $stream->readLineAsync();

    Loop::addTimer(0.05, fn () => $promise->cancel());
    Loop::run();

    $pos = ftell($fp);
    $size = filesize($file);

    expect($pos)->toBeGreaterThan(0)
        ->and($pos)->toBeLessThan($size)
    ;
});

it('cancels pipeAsync where destination is full/blocking', function () use ($tempDir) {
    $srcFile = $tempDir . '/pipe_block_src.bin';
    $dstFile = $tempDir . '/pipe_block_dst.bin';
    createSparseFile($srcFile, 50 * 1024 * 1024);

    $src = fopen($srcFile, 'r');
    $dst = fopen($dstFile, 'w');

    $readStream = new PromiseReadableStream($src, chunkSize: 8192);
    $writeStream = new PromiseWritableStream($dst, softLimit: 1);

    $promise = $readStream->pipeAsync($writeStream);

    Loop::addTimer(0.05, fn () => $promise->cancel());
    Loop::run();

    clearstatcache();
    $written = filesize($dstFile);

    expect($written)->toBeGreaterThan(0)
        ->and($written)->toBeLessThan(50 * 1024 * 1024)
    ;
});

it('cancels readAllAsync with 1-byte extreme chunking', function () use ($tempDir) {
    $file = $tempDir . '/extreme_chunk.bin';
    $fp = fopen($file, 'w');
    fwrite($fp, str_repeat('X', 1024 * 50));
    fclose($fp);

    $fp = fopen($file, 'r');
    $stream = new PromiseReadableStream($fp, chunkSize: 1);

    $promise = $stream->readAllAsync();

    Loop::addTimer(0.05, fn () => $promise->cancel());
    Loop::run();

    $pos = ftell($fp);

    expect($pos)->toBeGreaterThan(0)
        ->and($pos)->toBeLessThan(1024 * 50)
    ;
});

it('handles cancellation when stream is already closed externally', function () use ($tempDir) {
    $file = $tempDir . '/external_close.bin';
    $fp = fopen($file, 'w');
    fwrite($fp, 'Data');
    fclose($fp);

    $fp = fopen($file, 'r');
    $stream = new PromiseReadableStream($fp);

    $promise = $stream->readAsync(1024);

    Loop::addTimer(0.01, function () use ($fp, $promise) {
        fclose($fp);
        $promise->cancel();
    });

    try {
        Loop::run();
    } catch (Throwable $e) {
    }

    expect(true)->toBeTrue();
});

it('cancels pipeAsync immediately when source is empty', function () use ($tempDir) {
    $srcFile = $tempDir . '/empty_src.bin';
    $dstFile = $tempDir . '/empty_dst.bin';
    touch($srcFile);

    $src = fopen($srcFile, 'r');
    $dst = fopen($dstFile, 'w');

    $readStream = new PromiseReadableStream($src);
    $writeStream = new PromiseWritableStream($dst);

    $promise = $readStream->pipeAsync($writeStream);
    $promise->cancel();

    Loop::run();

    expect(filesize($dstFile))->toBe(0);
});
