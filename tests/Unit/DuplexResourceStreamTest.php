<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Stream\DuplexResourceStream;
use Hibla\Stream\Exceptions\StreamException;

describe('DuplexResourceStream', function () {
    beforeEach(function () {
        Loop::reset();
    });

    test('can be created successfully', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        expect($stream)->toBeInstanceOf(DuplexResourceStream::class);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write and read data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $writeData = "Hello, DuplexStream!\n";
        $dataReceived = '';

        $stream->on('data', function ($data) use (&$dataReceived) {
            $dataReceived .= $data;
        });

        $stream->on('drain', function () use ($stream, $resource) {
            // After write is drained, seek and read
            fseek($resource, 0, SEEK_SET);
            $stream->resume();
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->write($writeData);

        Loop::run();

        expect($dataReceived)->toContain('Hello');

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write line with newline', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $finishEmitted = false;

        $stream->on('drain', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $stream->write('Second line');
        $stream->write("\n");

        Loop::run();

        fseek($resource, 0, SEEK_SET);
        $content = fread($resource, 1024);
        expect($content)->toBe("Second line\n");

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write multiple lines and read them', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $line1 = "Hello, DuplexStream!\n";
        $line2 = "Second line\n";
        $dataReceived = '';

        $stream->on('data', function ($data) use (&$dataReceived) {
            $dataReceived .= $data;
        });

        $stream->on('drain', function () use ($stream, $resource) {
            // After writes are drained, seek and read
            fseek($resource, 0, SEEK_SET);
            $stream->resume();
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->write($line1);
        $stream->write($line2);

        Loop::run();

        expect($dataReceived)->toContain('Hello');
        expect($dataReceived)->toContain('Second line');

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits data event when reading', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $dataEmitted = false;
        $emittedData = null;

        $stream->on('data', function ($data) use (&$dataEmitted, &$emittedData) {
            $dataEmitted = true;
            $emittedData = $data;
        });

        $stream->on('drain', function () use ($stream, $resource) {
            fseek($resource, 0, SEEK_SET);
            $stream->resume();
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $writeData = "Test data\n";
        $stream->write($writeData);

        Loop::run();

        expect($dataEmitted)->toBeTrue();
        expect($emittedData)->toContain('Test data');

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits drain event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $drainEmitted = false;

        $stream->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
            Loop::stop();
        });

        $stream->write('Test');

        Loop::run();

        expect($drainEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits close event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $closeEmitted = false;

        $stream->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $stream->close();

        expect($closeEmitted)->toBeTrue();

        cleanupTempFile($tempPath);
    });

    test('reports correct state', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can pause and resume', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $stream->resume();
        $stream->pause();
        $stream->resume();

        expect($stream->isReadable())->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits pause event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $pauseEmitted = false;

        $stream->on('pause', function () use (&$pauseEmitted) {
            $pauseEmitted = true;
        });

        $stream->resume();
        $stream->pause();

        expect($pauseEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits resume event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $resumeEmitted = false;

        $stream->on('resume', function () use (&$resumeEmitted) {
            $resumeEmitted = true;
        });

        $stream->resume();

        expect($resumeEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write after pause and resume', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $stream->pause();
        $stream->resume();

        $writeSuccess = $stream->write("Test after pause/resume\n");

        expect($writeSuccess)->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can end with data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $finishEmitted = false;

        $stream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $stream->end("Final line\n");

        Loop::run();

        expect($stream->isWritable())->toBeFalse();
        expect($finishEmitted)->toBeTrue();

        cleanupTempFile($tempPath);
    });

    test('can end without data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $finishEmitted = false;

        $stream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $stream->write("Some data\n");
        $stream->end();

        Loop::run();

        expect($stream->isWritable())->toBeFalse();
        expect($finishEmitted)->toBeTrue();

        cleanupTempFile($tempPath);
    });

    test('emits finish event on end', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $finishEmitted = false;

        $stream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $stream->end("Final data\n");

        Loop::run();

        expect($finishEmitted)->toBeTrue();

        cleanupTempFile($tempPath);
    });

    test('cannot write after ending', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $stream->on('finish', function () {
            Loop::stop();
        });

        $stream->end();

        Loop::run();

        $writeSuccess = $stream->write('Should fail');

        expect($writeSuccess)->toBeFalse();

        cleanupTempFile($tempPath);
    });

    test('cannot write after closing', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $stream->close();

        $writeSuccess = $stream->write('Should fail');

        expect($writeSuccess)->toBeFalse();

        cleanupTempFile($tempPath);
    });

    test('multiple close calls are safe', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $stream->close();
        $stream->close();
        $stream->close();

        expect($stream->isReadable())->toBeFalse();
        expect($stream->isWritable())->toBeFalse();

        cleanupTempFile($tempPath);
    });

    test('handles complete write-read cycle', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $testData = "Line 1\nLine 2\nLine 3\n";
        $readData = '';

        $stream->on('data', function ($chunk) use (&$readData) {
            $readData .= $chunk;
        });

        $stream->on('drain', function () use ($stream, $resource) {
            fseek($resource, 0, SEEK_SET);
            $stream->resume();
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->write($testData);

        Loop::run();

        expect($readData)->toBe($testData);

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('handles sequential writes', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $drainEmitted = false;

        $stream->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
            Loop::stop();
        });

        $stream->write("First\n");
        $stream->write("Second\n");
        $stream->write("Third\n");

        Loop::run();

        fseek($resource, 0, SEEK_SET);
        $content = stream_get_contents($resource);

        expect($content)->toBe("First\nSecond\nThird\n");

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('throws on invalid resource', function () {
        expect(fn () => new DuplexResourceStream('not a resource'))
            ->toThrow(StreamException::class, 'Invalid resource')
        ;
    });

    test('throws on write-only resource', function () {
        $tempPath = createTempFile();
        $writeOnlyResource = fopen($tempPath, 'w');

        expect(fn () => new DuplexResourceStream($writeOnlyResource))
            ->toThrow(StreamException::class, 'read+write mode')
        ;

        fclose($writeOnlyResource);
        cleanupTempFile($tempPath);
    });

    test('throws on read-only resource', function () {
        $tempPath = createTempFile();
        file_put_contents($tempPath, 'test data');

        $readOnlyResource = fopen($tempPath, 'r');

        expect(fn () => new DuplexResourceStream($readOnlyResource))
            ->toThrow(StreamException::class, 'read+write mode')
        ;

        fclose($readOnlyResource);
        cleanupTempFile($tempPath);
    });

    test('handles EOF correctly', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $endEmitted = false;

        $stream->on('drain', function () use ($stream, $resource) {
            fseek($resource, 0, SEEK_SET);
            $stream->resume();
        });

        $stream->on('end', function () use (&$endEmitted) {
            $endEmitted = true;
            Loop::stop();
        });

        $stream->write('test');

        Loop::run();

        expect($endEmitted)->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write large data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $largeData = str_repeat('x', 100000);

        $stream->on('drain', function () {
            Loop::stop();
        });

        $stream->write($largeData);

        Loop::run();

        fseek($resource, 0, SEEK_SET);
        $content = stream_get_contents($resource);
        expect(strlen($content))->toBe(100000);

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('handles empty write', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $writeSuccess = $stream->write('');

        expect($writeSuccess)->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('handles write with special characters', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        $specialData = "Hello\tWorld\nWith\rSpecial\0Chars\n";
        $dataReceived = '';

        $stream->on('data', function ($data) use (&$dataReceived) {
            $dataReceived .= $data;
        });

        $stream->on('drain', function () use ($stream, $resource) {
            fseek($resource, 0, SEEK_SET);
            $stream->resume();
        });

        $stream->on('end', function () {
            Loop::stop();
        });

        $stream->write($specialData);

        Loop::run();

        expect($dataReceived)->toBe($specialData);

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('state changes correctly through lifecycle', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexResourceStream($resource);

        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();

        $stream->on('finish', function () {
            Loop::stop();
        });

        $stream->write('test');
        expect($stream->isWritable())->toBeTrue();

        $stream->end();

        Loop::run();

        expect($stream->isWritable())->toBeFalse();

        $stream->close();
        expect($stream->isReadable())->toBeFalse();
        expect($stream->isWritable())->toBeFalse();

        cleanupTempFile($tempPath);
    });

    test('can pipe to writable stream', function () {
        $content = 'Pipe test data';
        $sourceFile = createTempFile($content);
        $destFile = createTempFile();

        $sourceResource = fopen($sourceFile, 'r+');
        $destResource = fopen($destFile, 'w');

        $readStream = new DuplexResourceStream($sourceResource);
        $writeStream = new \Hibla\Stream\WritableResourceStream($destResource);

        $finishEmitted = false;

        $writeStream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $readStream->pipe($writeStream);

        Loop::run();

        expect($finishEmitted)->toBeTrue();
        expect(file_get_contents($destFile))->toBe($content);

        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });
});