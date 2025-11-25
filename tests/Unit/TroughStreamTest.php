<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\ThroughStream;
use Hibla\Stream\WritableResourceStream;

describe('ThroughStream', function () {
    beforeEach(function () {
        Loop::reset();
    });

    test('can be created without transformer', function () {
        $stream = new ThroughStream();

        expect($stream)->toBeInstanceOf(ThroughStream::class);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();

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

        $writeSuccess = $stream->write('Hello World');

        expect($received)->toBe('Hello World');
        expect($writeSuccess)->toBeTrue();

        $stream->close();
    });

    test('can write multiple chunks', function () {
        $stream = new ThroughStream();

        $chunks = [];
        $stream->on('data', function ($data) use (&$chunks) {
            $chunks[] = $data;
        });

        $stream->write('chunk1');
        $stream->write('chunk2');
        $stream->write('chunk3');

        expect($chunks)->toBe(['chunk1', 'chunk2', 'chunk3']);

        $stream->close();
    });

    test('transforms data when transformer is provided', function () {
        $stream = new ThroughStream(fn($data) => strtoupper($data));

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->write('hello world');

        expect($received)->toBe('HELLO WORLD');

        $stream->close();
    });

    test('transformer can modify data length', function () {
        $stream = new ThroughStream(fn($data) => str_repeat($data, 2));

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->write('test');

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

        $stream->end();

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

        $stream->write('first');
        $stream->end('last');

        expect($chunks)->toBe(['first', 'last']);
    });

    test('ending multiple times is safe', function () {
        $stream = new ThroughStream();

        $stream->end();
        $stream->end();
        $stream->end();

        expect($stream->isWritable())->toBeFalse();
    });

    test('cannot write after ending', function () {
        $stream = new ThroughStream();

        $stream->end();

        $writeSuccess = $stream->write('test');

        expect($writeSuccess)->toBeFalse();
        expect($stream->isWritable())->toBeFalse();
    });

    test('cannot write after closing', function () {
        $stream = new ThroughStream();

        $stream->close();

        $writeSuccess = $stream->write('test');

        expect($writeSuccess)->toBeFalse();
        expect($stream->isWritable())->toBeFalse();
    });

    test('can pause stream', function () {
        $stream = new ThroughStream();

        $paused = false;
        $stream->on('pause', function () use (&$paused) {
            $paused = true;
        });

        $stream->pause();

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

        expect($resumed)->toBeTrue();

        $stream->close();
    });

    test('emits drain after resume', function () {
        $stream = new ThroughStream();

        $drained = false;
        $stream->on('drain', function () use (&$drained) {
            $drained = true;
        });

        $stream->pause();
        $stream->write('test');
        $stream->resume();

        expect($drained)->toBeTrue();

        $stream->close();
    });

    test('pause when not readable does nothing', function () {
        $stream = new ThroughStream();

        $stream->end();
        $stream->pause();

        expect($stream->isReadable())->toBeFalse();
    });

    test('resume when not readable does nothing', function () {
        $stream = new ThroughStream();

        $stream->end();
        $stream->resume();

        expect($stream->isReadable())->toBeFalse();
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

    test('emits error when transformer throws', function () {
        $stream = new ThroughStream(function ($data) {
            throw new Exception('Transform error');
        });

        $error = null;
        $closed = false;

        $stream->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $stream->on('close', function () use (&$closed) {
            $closed = true;
        });

        $writeSuccess = $stream->write('test');

        expect($error)->toBeInstanceOf(Exception::class);
        expect($error->getMessage())->toBe('Transform error');
        expect($writeSuccess)->toBeFalse();
        expect($closed)->toBeTrue();
    });

    test('write returns false when paused', function () {
        $stream = new ThroughStream();

        $stream->pause();

        $result = $stream->write('test');

        expect($result)->toBeFalse();

        $stream->close();
    });

    test('write returns true when not paused', function () {
        $stream = new ThroughStream();

        $result = $stream->write('test data');

        expect($result)->toBeTrue();

        $stream->close();
    });

    test('can pipe to writable stream', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableResourceStream($resource);

        $stream = new ThroughStream();

        $finishEmitted = false;

        $destination->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $stream->pipe($destination);
        $stream->write('test data');
        $stream->end();

        Loop::run();

        expect(file_get_contents($file))->toBe('test data');
        expect($finishEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('pipe ends destination by default', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableResourceStream($resource);

        $stream = new ThroughStream();

        $closed = false;
        $destination->on('close', function () use (&$closed) {
            $closed = true;
            Loop::stop();
        });

        $stream->pipe($destination);
        $stream->end();

        Loop::run();

        expect($closed)->toBeTrue();

        cleanupTempFile($file);
    });

    test('pipe does not end destination when end option is false', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableResourceStream($resource);

        $stream = new ThroughStream();

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->pipe($destination, ['end' => false]);
        $stream->end();

        Loop::run();

        expect($destination->isWritable())->toBeTrue();

        $destination->close();
        cleanupTempFile($file);
    });

    test('handles empty write', function () {
        $stream = new ThroughStream();

        $dataEmitted = false;
        $stream->on('data', function () use (&$dataEmitted) {
            $dataEmitted = true;
        });

        $stream->write('');

        expect($dataEmitted)->toBeTrue();

        $stream->close();
    });

    test('maintains data order', function () {
        $stream = new ThroughStream();

        $received = [];
        $stream->on('data', function ($data) use (&$received) {
            $received[] = $data;
        });

        for ($i = 1; $i <= 10; $i++) {
            $stream->write("chunk$i");
        }

        expect($received)->toBe([
            'chunk1',
            'chunk2',
            'chunk3',
            'chunk4',
            'chunk5',
            'chunk6',
            'chunk7',
            'chunk8',
            'chunk9',
            'chunk10',
        ]);

        $stream->close();
    });

    test('removes all listeners on close', function () {
        $stream = new ThroughStream();

        $stream->on('data', fn() => null);
        $stream->on('end', fn() => null);
        $stream->on('error', fn() => null);

        $stream->close();

        $errorEmitted = false;

        $stream->on('error', function () use (&$errorEmitted) {
            $errorEmitted = true;
        });

        $writeSuccess = $stream->write('test');

        expect($writeSuccess)->toBeFalse();
        expect($errorEmitted)->toBeTrue();
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

        $stream->write('Normal Text');
        $stream->write('ERROR: Something went wrong');

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

        $stream->write('unchanged');

        expect($received)->toBe('unchanged');

        $stream->close();
    });

    test('pipe handles backpressure', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');
        $destination = new WritableResourceStream($resource, 1024); // Small buffer

        $stream = new ThroughStream();

        $pauseEmitted = false;
        $drainEmitted = false;

        $stream->on('pause', function () use (&$pauseEmitted) {
            $pauseEmitted = true;
        });

        $destination->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
        });

        $destination->on('finish', function () {
            Loop::stop();
        });

        $stream->pipe($destination);

        $largeData = str_repeat('x', 100000);
        $stream->write($largeData);
        $stream->end();

        Loop::run();

        expect(file_get_contents($file))->toBe($largeData);
        expect($pauseEmitted)->toBeTrue();
        expect($drainEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('transformer can return empty string', function () {
        $stream = new ThroughStream(fn($data) => '');

        $received = null;
        $stream->on('data', function ($data) use (&$received) {
            $received = $data;
        });

        $stream->write('test');

        expect($received)->toBe('');

        $stream->close();
    });

    test('end with transformer applies transformation', function () {
        $stream = new ThroughStream(fn($data) => strtoupper($data));

        $chunks = [];
        $stream->on('data', function ($data) use (&$chunks) {
            $chunks[] = $data;
        });

        $stream->end('final');

        expect($chunks)->toBe(['FINAL']);
    });

    test('error in transformer during end closes stream', function () {
        $stream = new ThroughStream(function ($data) {
            throw new Exception('End error');
        });

        $error = null;
        $closed = false;

        $stream->on('error', function ($e) use (&$error) {
            $error = $e;
        });

        $stream->on('close', function () use (&$closed) {
            $closed = true;
        });

        $stream->end('data');

        expect($error)->toBeInstanceOf(Exception::class);
        expect($error->getMessage())->toBe('End error');
        expect($closed)->toBeTrue();
        expect($stream->isWritable())->toBeFalse();
        expect($stream->isReadable())->toBeFalse();
    });
});
