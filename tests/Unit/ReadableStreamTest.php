<?php

use Hibla\EventLoop\Loop;
use Hibla\Stream\ReadableStream;
use Hibla\Stream\Exceptions\StreamException;

describe('ReadableStream', function () {
    beforeEach(function () {
       Loop::reset();
    });
    
    test('can be created from a readable resource', function () {
        $file = createTempFile('test data');
        $resource = fopen($file, 'r');

        $stream = new ReadableStream($resource);

        expect($stream)->toBeInstanceOf(ReadableStream::class);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->isPaused())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('throws exception for invalid resource', function () {
        new ReadableStream('not a resource');
    })->throws(StreamException::class, 'Invalid resource provided');

    test('throws exception for non-readable resource', function () {
        $file = createTempFile();
        $resource = fopen($file, 'w');

        try {
            new ReadableStream($resource);
        } finally {
            fclose($resource);
            cleanupTempFile($file);
        }
    })->throws(StreamException::class, 'Resource is not readable');

    test('can read data', function () {
        $content = 'Hello, World!';
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $result = $stream->read()->await();

        expect($result)->toBe($content);

        $stream->close();
        cleanupTempFile($file);
    });

    test('can read data in chunks', function () {
        $content = str_repeat('X', 20000);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource, 8192);

        $chunks = [];

        while (($data = $stream->read()->await()) !== null) {
            $chunks[] = $data;
        }

        expect(count($chunks))->toBeGreaterThan(1);
        expect(implode('', $chunks))->toBe($content);

        $stream->close();
        cleanupTempFile($file);
    });

    test('can read line by line', function () {
        $lines = ["First line\n", "Second line\n", "Third line"];
        $content = implode('', $lines);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $readLines = [];

        while (($line = $stream->readLine()->await()) !== null) {
            $readLines[] = $line;
        }

        expect($readLines)->toBe($lines);

        $stream->close();
        cleanupTempFile($file);
    });

    test('can read all content', function () {
        $content = 'Complete file content';
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $result = $stream->readAll()->await();

        expect($result)->toBe($content);

        $stream->close();
        cleanupTempFile($file);
    });

    test('emits data events when resumed', function () {
        $content = 'Event data';
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $emittedData = '';
        $endEmitted = false;

        $stream->on('data', function ($data) use (&$emittedData) {
            $emittedData .= $data;
        });

        $stream->on('end', function () use (&$endEmitted) {
            $endEmitted = true;
        });

        $stream->resume();

        // Wait for stream to complete
        usleep(100000); 
        Loop::run();

        expect($emittedData)->toBe($content);
        expect($endEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('can pause and resume', function () {
        $content = str_repeat('X', 50000);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $dataCount = 0;
        $paused = false;
        $allData = '';
        $pauseTriggered = false; 

        $stream->on('data', function ($data) use ($stream, &$dataCount, &$paused, &$allData, &$pauseTriggered) {
            if ($pauseTriggered) {
                return; 
            }

            $dataCount++;
            $allData .= $data;

            if ($dataCount === 2 && !$paused) {
                expect($stream->isPaused())->toBeFalse();
                $stream->pause();
                expect($stream->isPaused())->toBeTrue();
                $paused = true;
                $pauseTriggered = true; 

                Loop::addTimer(0.05, function () use ($stream, &$pauseTriggered) {
                    $pauseTriggered = false; 
                    $stream->resume();
                });
            }
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->resume();
        Loop::run();

        expect($dataCount)->toBeGreaterThanOrEqual(2);
        expect($paused)->toBeTrue();
        expect($allData)->toBe($content);

        $stream->close();
        cleanupTempFile($file);
    });

    test('detects EOF correctly', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        expect($stream->isEof())->toBeFalse();

        $stream->read()->await();

        expect($stream->isEof())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('returns null on EOF', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $first = $stream->read()->await();
        $second = $stream->read()->await();

        expect($first)->toBe('test');
        expect($second)->toBeNull();

        $stream->close();
        cleanupTempFile($file);
    });

    test('can be closed', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        expect($stream->isReadable())->toBeTrue();

        $stream->close();

        expect($stream->isReadable())->toBeFalse();

        cleanupTempFile($file);
    });

    test('emits close event', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $closeEmitted = false;
        $stream->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $stream->close();

        expect($closeEmitted)->toBeTrue();

        cleanupTempFile($file);
    });

    test('read throws exception after close', function () {
        $file = createTempFile('test');
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $stream->close();

        try {
            $stream->read()->await();
        } catch (\Throwable $e) {
            expect($e)->toBeInstanceOf(StreamException::class);
            expect($e->getMessage())->toContain('not readable');
        }

        cleanupTempFile($file);
    });

    test('can cancel read operation', function () {
        $content = str_repeat('X', 100000);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource, 8192);

        $promise = $stream->read();

        // Cancel immediately
        $promise->cancel();

        expect($promise->isCancelled())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('handles empty file', function () {
        $file = createTempFile('');
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $result = $stream->read()->await();

        expect($result)->toBeNull();
        expect($stream->isEof())->toBeTrue();

        $stream->close();
        cleanupTempFile($file);
    });

    test('readLine handles lines without newline at end', function () {
        $content = "Line 1\nLine 2";
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $line1 = $stream->readLine()->await();
        $line2 = $stream->readLine()->await();
        $line3 = $stream->readLine()->await();

        expect($line1)->toBe("Line 1\n");
        expect($line2)->toBe("Line 2");
        expect($line3)->toBeNull();

        $stream->close();
        cleanupTempFile($file);
    });

    test('readLine respects max length', function () {
        $content = str_repeat('X', 100);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $line = $stream->readLine(50)->await();

        expect(strlen($line))->toBe(50);

        $stream->close();
        cleanupTempFile($file);
    });

    test('readAll respects max length', function () {
        $content = str_repeat('X', 100000);
        $file = createTempFile($content);
        $resource = fopen($file, 'r');
        $stream = new ReadableStream($resource);

        $data = $stream->readAll(50000)->await();

        expect(strlen($data))->toBe(50000);

        $stream->close();
        cleanupTempFile($file);
    });
});
