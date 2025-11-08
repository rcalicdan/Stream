<?php

require_once __DIR__ . '/vendor/autoload.php';

use Hibla\Stream\Stream;
use Hibla\EventLoop\Loop;

echo "Quick Large File Test\n";
echo "====================\n\n";

$testFile = sys_get_temp_dir() . '/stream_test_large.txt';
$copyFile = sys_get_temp_dir() . '/stream_test_copy.txt';

// Test 1: Create 50MB file
echo "1. Creating 50MB test file...\n";
$startMem = memory_get_usage();
$writer = Stream::writableFile($testFile);

$size = 50 * 1024 * 1024; // 50MB
$chunk = str_repeat('X', 8192);
$written = 0;

$write = function() use (&$write, $writer, $chunk, &$written, $size, $startMem) {
    if ($written >= $size) {
        $writer->end()->then(function() use ($startMem, $written) {
            $memUsed = memory_get_usage() - $startMem;
            echo "   ✓ Created " . number_format($written) . " bytes\n";
            echo "   Memory used: " . number_format($memUsed / 1024 / 1024, 2) . " MB\n\n";
            
            // Test 2: Copy the file
            testCopy();
        });
        return;
    }
    
    $writer->write($chunk)->then(function($bytes) use (&$written, &$write) {
        $written += $bytes;
        if ($written % (5 * 1024 * 1024) === 0) {
            echo "   Written: " . number_format($written / 1024 / 1024, 1) . " MB\r";
        }
        Loop::defer($write);
    });
};

function testCopy() {
    global $testFile, $copyFile;
    
    echo "2. Copying 50MB file using streams...\n";
    $startMem = memory_get_usage();
    
    $source = Stream::readableFile($testFile);
    $dest = Stream::writableFile($copyFile);
    
    $lastProgress = 0;
    $source->on('data', function($data) use (&$lastProgress) {
        static $total = 0;
        $total += strlen($data);
        $progress = floor($total / 1024 / 1024 / 5) * 5;
        if ($progress > $lastProgress) {
            echo "   Copied: {$progress} MB\r";
            $lastProgress = $progress;
        }
    });
    
    $source->pipe($dest)->then(function($bytes) use ($startMem) {
        $memUsed = memory_get_usage() - $startMem;
        echo "   ✓ Copied " . number_format($bytes) . " bytes\n";
        echo "   Memory used: " . number_format($memUsed / 1024 / 1024, 2) . " MB\n\n";
        
        // Test 3: Read and count lines
        testReadAll();
    });
}

function testReadAll() {
    global $testFile;
    
    echo "3. Reading entire file...\n";
    $startMem = memory_get_usage();
    
    $reader = Stream::readableFile($testFile);
    $totalRead = 0;
    
    $reader->on('data', function($data) use (&$totalRead) {
        $totalRead += strlen($data);
        $mb = floor($totalRead / 1024 / 1024);
        if ($totalRead % (5 * 1024 * 1024) < 8192) {
            echo "   Read: {$mb} MB\r";
        }
    });
    
    $reader->on('end', function() use (&$totalRead, $startMem) {
        $memUsed = memory_get_usage() - $startMem;
        echo "   ✓ Read " . number_format($totalRead) . " bytes\n";
        echo "   Memory used: " . number_format($memUsed / 1024 / 1024, 2) . " MB\n\n";
        
        cleanup();
    });
    
    $reader->resume();
}

function cleanup() {
    global $testFile, $copyFile;
    
    echo "4. Cleanup...\n";
    @unlink($testFile);
    @unlink($copyFile);
    echo "   ✓ Test files removed\n\n";
    
    echo "All tests completed!\n";
    echo "Peak memory: " . number_format(memory_get_peak_usage() / 1024 / 1024, 2) . " MB\n";
    
    Loop::stop();
}

Loop::defer($write);
Loop::run();