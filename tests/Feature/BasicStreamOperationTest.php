<?php

use Hibla\Stream\Exceptions\StreamException;
use Hibla\Stream\StreamOperation;

beforeEach(function () {
    $this->stream = new StreamOperation();
    $this->testDir = __DIR__ . '/../fixtures';
    
    if (!is_dir($this->testDir)) {
        mkdir($this->testDir, 0777, true);
    }
});

afterEach(function () {
    cleanupTestFiles([
        $this->testDir . '/test_output.txt',
        $this->testDir . '/test_lines.txt',
        $this->testDir . '/test_eof.txt',
    ]);
});

test('can open and write to a file', function () {
    $filePath = $this->testDir . '/test_output.txt';
    $testData = "Hello, World!\n";
    
    $result = $this->stream->open($filePath, 'w')
        ->then(fn($fileStream) => $this->stream->write($fileStream, $testData))
        ->then(function ($bytesWritten) use ($filePath) {
            expect($bytesWritten)->toBe(14);
            return $this->stream->open($filePath, 'r');
        })
        ->then(fn($fileStream) => $this->stream->read($fileStream))
        ->await();
    
    expect($result)->toBe($testData);
});

test('can write and read multiple lines', function () {
    $filePath = $this->testDir . '/test_lines.txt';
    
    $lines = $this->stream->open($filePath, 'w')
        ->then(fn($fs) => $this->stream->writeLine($fs, "Line 1")->then(fn() => $fs))
        ->then(function ($fs) {
            $this->stream->close($fs);
            return $this->stream->open($this->testDir . '/test_lines.txt', 'a');
        })
        ->then(fn($fs) => $this->stream->writeLine($fs, "Line 2")->then(fn() => $fs))
        ->then(function ($fs) {
            $this->stream->close($fs);
            return $this->stream->open($this->testDir . '/test_lines.txt', 'r');
        })
        ->then(fn($fs) => $this->stream->readAll($fs))
        ->await();
    
    expect($lines)->toBe("Line 1\nLine 2\n");
});

test('can read line by line', function () {
    $filePath = $this->testDir . '/test_lines.txt';
    file_put_contents($filePath, "First Line\nSecond Line\nThird Line\n");
    
    $firstLine = $this->stream->open($filePath, 'r')
        ->then(fn($fs) => $this->stream->readLine($fs))
        ->await();
    
    expect($firstLine)->toBe("First Line\n");
});

test('can detect end of file', function () {
    $filePath = $this->testDir . '/test_eof.txt';
    file_put_contents($filePath, "Small content");
    
    $isEof = $this->stream->open($filePath, 'r')
        ->then(fn($fs) => $this->stream->readAll($fs)->then(fn() => $fs))
        ->then(fn($fs) => $this->stream->eof($fs))
        ->await();
    
    expect($isEof)->toBeTrue();
});

test('can read all content from stream', function () {
    $filePath = $this->testDir . '/test_output.txt';
    $testData = str_repeat("Test data line\n", 10);
    file_put_contents($filePath, $testData);
    
    $content = $this->stream->open($filePath, 'r')
        ->then(fn($fs) => $this->stream->readAll($fs))
        ->await();
    
    expect($content)->toBe($testData);
});

test('throws exception when opening non-existent file for reading', function () {
    $this->stream->open('/nonexistent/path/file.txt', 'r')
        ->await();
})->throws(StreamException::class);

test('can handle empty file reads', function () {
    $filePath = $this->testDir . '/test_empty.txt';
    touch($filePath);
    
    $content = $this->stream->open($filePath, 'r')
        ->then(fn($fs) => $this->stream->readAll($fs))
        ->await();
    
    expect($content)->toBe('');
    
    unlink($filePath);
});

test('can write large content', function () {
    $filePath = $this->testDir . '/test_large.txt';
    $largeContent = str_repeat("A", 50000);
    
    $bytesWritten = $this->stream->open($filePath, 'w')
        ->then(fn($fs) => $this->stream->write($fs, $largeContent))
        ->await();
    
    expect($bytesWritten)->toBe(50000);
    expect(filesize($filePath))->toBe(50000);
    
    unlink($filePath);
});