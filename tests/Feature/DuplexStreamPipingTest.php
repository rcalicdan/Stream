<?php

declare(strict_types=1);

use Hibla\Stream\DuplexStream;
use Hibla\Stream\WritableStream;

describe('DuplexStream Piping', function () {

    test('can pipe DuplexStream to WritableStream', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $testData = "Line 1\nLine 2\nLine 3\nLine 4\nLine 5\n";

        $bytes = $sourceStream->write($testData)->await();
        expect($bytes)->toBe(strlen($testData));

        fseek($sourceResource, 0, SEEK_SET);

        $totalBytes = $sourceStream->pipe($destStream, ['end' => true])->await();
        expect($totalBytes)->toBe(strlen($testData));

        $destContent = file_get_contents($destPath);
        expect($destContent)->toBe($testData);
        expect(strlen($destContent))->toBe(35);

        $sourceStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('can pipe DuplexStream to another DuplexStream', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w+');
        $destStream = new DuplexStream($destResource);

        $testData = "First chunk\n" . str_repeat('x', 100) . "\nSecond chunk\n";

        $bytes = $sourceStream->write($testData)->await();
        expect($bytes)->toBe(strlen($testData));

        fseek($sourceResource, 0, SEEK_SET);

        $receivedChunks = [];
        $sourceStream->on('data', function ($data) use (&$receivedChunks) {
            $receivedChunks[] = $data;
        });

        $totalBytes = $sourceStream->pipe($destStream, ['end' => true])->await();
        expect($totalBytes)->toBe(strlen($testData));
        expect($totalBytes)->toBe(126);

        $destContent = file_get_contents($destPath);
        expect(strlen($destContent))->toBe(126);
        expect($destContent)->toBe($testData);

        expect($receivedChunks)->not->toBeEmpty();

        $totalReceived = implode('', $receivedChunks);
        expect($totalReceived)->toBe($testData);

        $sourceStream->close();
        $destStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('can pipe large data efficiently', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $largeData = str_repeat("This is a test line with some content.\n", 25000);
        $expectedSize = strlen($largeData);

        expect($expectedSize)->toBe(975000);

        $bytes = $sourceStream->write($largeData)->await();
        expect($bytes)->toBe($expectedSize);

        fseek($sourceResource, 0, SEEK_SET);

        $startTime = microtime(true);
        $totalBytes = $sourceStream->pipe($destStream, ['end' => true])->await();
        $elapsed = microtime(true) - $startTime;

        expect($totalBytes)->toBe($expectedSize);
        expect($elapsed)->toBeLessThan(1.0);

        $actualSize = filesize($destPath);
        expect($actualSize)->toBe($expectedSize);

        $sourceStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    })->group('stress');

    test('pipe ends destination stream by default', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $testData = "Test data\n";
        $sourceStream->write($testData)->await();
        fseek($sourceResource, 0, SEEK_SET);

        $finishEmitted = false;

        $destStream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
        });

        $sourceStream->pipe($destStream, ['end' => true])->await();

        waitForLoop(50);

        expect($finishEmitted)->toBeTrue('Destination should emit finish event');
        expect($destStream->isWritable())->toBeFalse('Destination should be ended');

        $sourceStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('pipe does not end destination when end option is false', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w+');
        $destStream = new DuplexStream($destResource);

        $testData = "Test data\n";
        $sourceStream->write($testData)->await();
        fseek($sourceResource, 0, SEEK_SET);

        $finishEmitted = false;
        $destStream->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
        });

        $totalBytes = $sourceStream->pipe($destStream, ['end' => false])->await();

        expect($totalBytes)->toBe(strlen($testData));
        expect($finishEmitted)->toBeFalse('Destination should not emit finish');
        expect($destStream->isWritable())->toBeTrue('Destination should still be writable');

        $moreBytes = $destStream->write("More data\n")->await();
        expect($moreBytes)->toBeGreaterThan(0);

        $sourceStream->close();
        $destStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('pipe handles empty source', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $totalBytes = $sourceStream->pipe($destStream, ['end' => true])->await();

        expect($totalBytes)->toBe(0);
        expect(filesize($destPath))->toBe(0);

        $sourceStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('pipe rejects when source is not readable', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $sourceStream->close();

        try {
            $sourceStream->pipe($destStream)->await();

            throw new Exception('Should have thrown');
        } catch (Throwable $e) {
            expect($e->getMessage())->toContain('not readable');
        }
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('pipe rejects when destination is not writable', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $sourceStream->write('test')->await();
        fseek($sourceResource, 0, SEEK_SET);

        $destStream->close();

        try {
            $sourceStream->pipe($destStream)->await();

            throw new Exception('Should have thrown');
        } catch (Throwable $e) {
            expect($e->getMessage())->toContain('not writable');
        }

        $sourceStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('pipe can be cancelled', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w');
        $destStream = new WritableStream($destResource);

        $largeData = str_repeat('x', 100000);
        $sourceStream->write($largeData)->await();
        fseek($sourceResource, 0, SEEK_SET);

        $pipePromise = $sourceStream->pipe($destStream, ['end' => true]);

        $pipePromise->cancel();

        try {
            $pipePromise->await();
        } catch (Throwable $e) {
            // expected if we try to resolve a cancelled promise
        }

        expect($pipePromise->isCancelled())->toBeTrue();

        $sourceStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });

    test('pipe handles multiple small chunks', function () {
        $sourcePath = createTempFile();
        $sourceResource = fopen($sourcePath, 'w+');
        $sourceStream = new DuplexStream($sourceResource);

        $destPath = createTempFile();
        $destResource = fopen($destPath, 'w+');
        $destStream = new DuplexStream($destResource);

        $chunks = ['chunk1', 'chunk2', 'chunk3', 'chunk4', 'chunk5'];
        foreach ($chunks as $chunk) {
            $sourceStream->write($chunk . "\n")->await();
        }

        fseek($sourceResource, 0, SEEK_SET);

        $receivedChunks = [];
        $sourceStream->on('data', function ($data) use (&$receivedChunks) {
            $receivedChunks[] = $data;
        });

        $totalBytes = $sourceStream->pipe($destStream, ['end' => true])->await();

        $expectedData = implode("\n", $chunks) . "\n";
        expect($totalBytes)->toBe(strlen($expectedData));
        expect($receivedChunks)->not->toBeEmpty();

        $totalReceived = implode('', $receivedChunks);
        expect($totalReceived)->toBe($expectedData);

        $destContent = file_get_contents($destPath);
        expect($destContent)->toBe($expectedData);

        $sourceStream->close();
        $destStream->close();
        cleanupTempFile($sourcePath);
        cleanupTempFile($destPath);
    });
});
