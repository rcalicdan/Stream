<?php
// simple-comparison.php

require 'vendor/autoload.php';

use Hibla\Stream\Stream;

function formatBytes($bytes) {
    return number_format($bytes / 1024, 2) . ' KB';
}

function createLargeFile($path, $size) {
    $handle = fopen($path, 'wb');
    $chunk = str_repeat('X', 8192);
    $written = 0;
    while ($written < $size) {
        $toWrite = min(8192, $size - $written);
        fwrite($handle, substr($chunk, 0, $toWrite));
        $written += $toWrite;
    }
    fclose($handle);
    unset($chunk);
}

echo "=== Hibla Stream Memory Usage Test ===\n\n";

$tests = [
    ['label' => '100KB', 'size' => 100 * 1024],
    ['label' => '1MB', 'size' => 1024 * 1024],
    ['label' => '10MB', 'size' => 10 * 1024 * 1024],
    ['label' => '100MB', 'size' => 100 * 1024 * 1024],
];

$results = [];

foreach ($tests as $test) {
    $label = $test['label'];
    $size = $test['size'];
    
    echo "Testing {$label} file...\n";
    
    $sourceFile = tempnam(sys_get_temp_dir(), 'test_');
    $destFile = tempnam(sys_get_temp_dir(), 'dest_');
    
    createLargeFile($sourceFile, $size);
    
    gc_collect_cycles();
    $startMem = memory_get_usage();
    $peakStart = memory_get_peak_usage();
    
    $source = Stream::readableFile($sourceFile);
    $dest = Stream::writableFile($destFile);
    
    $bytes = $source->pipe($dest)->await(false);
    
    $used = memory_get_usage() - $startMem;
    $peak = memory_get_peak_usage() - $peakStart;
    
    $results[] = [
        'label' => $label,
        'size' => $size,
        'bytes' => $bytes,
        'used' => $used,
        'peak' => $peak,
    ];
    
    echo "  Transferred: " . number_format($bytes) . " bytes\n";
    echo "  Peak memory: " . formatBytes($peak) . "\n";
    echo "  Ratio: " . number_format(($peak / $size) * 100, 3) . "%\n\n";
    
    @unlink($sourceFile);
    @unlink($destFile);
}

echo "\n=== Summary ===\n";
printf("%-10s %-15s %-15s %-10s\n", "File Size", "Peak Memory", "Memory Used", "Ratio");
echo str_repeat("-", 60) . "\n";

foreach ($results as $r) {
    printf(
        "%-10s %-15s %-15s %-10s\n",
        $r['label'],
        formatBytes($r['peak']),
        formatBytes($r['used']),
        number_format(($r['peak'] / $r['size']) * 100, 3) . "%"
    );
}

echo "\n=== Analysis ===\n";

// Check if memory scales with file size or stays constant
$firstPeak = $results[0]['peak'];
$lastPeak = $results[count($results) - 1]['peak'];
$growth = $lastPeak - $firstPeak;
$growthPercent = ($growth / $firstPeak) * 100;

echo "Memory growth from smallest to largest: " . formatBytes($growth) . " (" . number_format($growthPercent, 1) . "%)\n";

if ($growthPercent < 50) {
    echo "✓ Good: Memory usage is relatively constant regardless of file size\n";
    echo "  This indicates efficient streaming with minimal buffering.\n";
} else {
    echo "⚠ Warning: Memory usage scales with file size\n";
    echo "  This suggests data is being buffered instead of streamed.\n";
}

echo "\nThe ~2-2.5MB overhead you see is normal for:\n";
echo "  - Event loop infrastructure\n";
echo "  - Promise chains and closures\n";
echo "  - Stream buffers and metadata\n";
echo "  - PHP's internal structures\n";