<?php

require 'vendor/autoload.php';

use Hibla\Stream\Stream;

function formatBytes($bytes, $precision = 2) {
    $units = ['B', 'KB', 'MB', 'GB'];
    $bytes = max($bytes, 0);
    $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
    $pow = min($pow, count($units) - 1);
    $bytes /= pow(1024, $pow);
    return round($bytes, $precision) . ' ' . $units[$pow];
}

function createLargeFile($path, $size, $chunkSize = 65536) {
    echo "  Creating " . formatBytes($size) . " file...\n";
    $startTime = microtime(true);
    
    $handle = fopen($path, 'wb');
    $chunk = str_repeat('A', $chunkSize);
    $written = 0;
    $lastReport = 0;
    
    while ($written < $size) {
        $toWrite = min($chunkSize, $size - $written);
        fwrite($handle, substr($chunk, 0, $toWrite));
        $written += $toWrite;
        
        // Progress report every 100MB
        if ($written - $lastReport >= 100 * 1024 * 1024) {
            $progress = ($written / $size) * 100;
            echo "    Progress: " . number_format($progress, 1) . "% (" . formatBytes($written) . ")\n";
            $lastReport = $written;
        }
    }
    
    fclose($handle);
    $duration = microtime(true) - $startTime;
    
    echo "  File created in " . number_format($duration, 2) . " seconds\n";
    echo "  Write speed: " . formatBytes($size / $duration) . "/s\n\n";
    
    unset($chunk);
    gc_collect_cycles();
}

function testStreaming($label, $size, $chunkSize = 8192) {
    echo str_repeat("=", 70) . "\n";
    echo "Testing: {$label} (" . formatBytes($size) . ")\n";
    echo str_repeat("=", 70) . "\n\n";
    
    $sourceFile = tempnam(sys_get_temp_dir(), 'gigabyte_test_');
    $destFile = tempnam(sys_get_temp_dir(), 'gigabyte_dest_');
    
    // Create source file
    createLargeFile($sourceFile, $size);
    
    // Measure streaming
    echo "Starting stream transfer...\n";
    gc_collect_cycles();
    
    $memBefore = memory_get_usage();
    $startTime = microtime(true);
    
    $source = Stream::readableFile($sourceFile, $chunkSize);
    $dest = Stream::writableFile($destFile);
    
    $bytesTransferred = 0;
    $lastReport = 0;
    $reportInterval = 100 * 1024 * 1024; // Report every 100MB
    
    // Track memory during transfer
    $memSamples = [];
    $sampleCount = 0;
    
    $source->on('data', function($data) use (&$bytesTransferred, &$lastReport, $reportInterval, $size, &$memSamples, &$sampleCount) {
        $bytesTransferred += strlen($data);
        
        // Sample memory every 100 chunks
        if ($sampleCount++ % 100 === 0) {
            $memSamples[] = memory_get_usage();
        }
        
        if ($bytesTransferred - $lastReport >= $reportInterval) {
            $progress = ($bytesTransferred / $size) * 100;
            $currentMem = memory_get_usage();
            echo "  Progress: " . number_format($progress, 1) . "% (" . formatBytes($bytesTransferred) . 
                 ") - Memory: " . formatBytes($currentMem) . "\n";
            $lastReport = $bytesTransferred;
        }
    });
    
    $bytesTransferred = $source->pipe($dest)->await(false);
    
    $duration = microtime(true) - $startTime;
    $memAfter = memory_get_usage();
    $memUsed = $memAfter - $memBefore;
    
    // Calculate memory statistics
    $avgMem = !empty($memSamples) ? array_sum($memSamples) / count($memSamples) : 0;
    $maxMem = !empty($memSamples) ? max($memSamples) : 0;
    $minMem = !empty($memSamples) ? min($memSamples) : 0;
    $memVariance = $maxMem - $minMem;
    
    // Verify integrity
    echo "\nVerifying file integrity...\n";
    $sourceHash = hash_file('xxh3', $sourceFile); // xxh3 is faster than md5 for large files
    $destHash = hash_file('xxh3', $destFile);
    $integrityCheck = $sourceHash === $destHash ? "✓ PASS" : "✗ FAIL";
    
    // Results
    echo "\n" . str_repeat("-", 70) . "\n";
    echo "RESULTS\n";
    echo str_repeat("-", 70) . "\n";
    echo sprintf("%-30s: %s\n", "File size", formatBytes($size));
    echo sprintf("%-30s: %s\n", "Bytes transferred", formatBytes($bytesTransferred));
    echo sprintf("%-30s: %s\n", "Transfer time", number_format($duration, 2) . " seconds");
    echo sprintf("%-30s: %s/s\n", "Transfer speed", formatBytes($size / $duration));
    echo sprintf("%-30s: %s\n", "Memory used", formatBytes($memUsed));
    echo sprintf("%-30s: %s\n", "Avg memory during transfer", formatBytes($avgMem - $memBefore));
    echo sprintf("%-30s: %s\n", "Memory variance", formatBytes($memVariance));
    echo sprintf("%-30s: %.6f%%\n", "Memory efficiency", ($memUsed / $size) * 100);
    echo sprintf("%-30s: %s\n", "Ratio", "1 KB per " . formatBytes($size / max($memUsed, 1)));
    echo sprintf("%-30s: %s\n", "Integrity check", $integrityCheck);
    echo str_repeat("-", 70) . "\n\n";
    
    // Cleanup
    echo "Cleaning up...\n";
    @unlink($sourceFile);
    @unlink($destFile);
    
    gc_collect_cycles();
    
    return [
        'label' => $label,
        'size' => $size,
        'duration' => $duration,
        'memUsed' => $memUsed,
        'avgMem' => $avgMem - $memBefore,
        'memVariance' => $memVariance,
        'efficiency' => ($memUsed / $size) * 100,
        'speed' => $size / $duration,
        'integrity' => $sourceHash === $destHash,
    ];
}

// Main execution
echo "\n";
echo "╔══════════════════════════════════════════════════════════════════════╗\n";
echo "║         HIBLA STREAM - GIGABYTE STREAMING TEST                      ║\n";
echo "╚══════════════════════════════════════════════════════════════════════╝\n";
echo "\n";

echo "This test will verify that Hibla Stream can handle multi-gigabyte files\n";
echo "with constant memory usage, proving true streaming capabilities.\n\n";

$tests = [
    ['label' => '100 MB', 'size' => 100 * 1024 * 1024],
    ['label' => '500 MB', 'size' => 500 * 1024 * 1024],
    ['label' => '1 GB', 'size' => 1024 * 1024 * 1024],
    ['label' => '2 GB', 'size' => 2 * 1024 * 1024 * 1024],
];

// Check available disk space
$tempDir = sys_get_temp_dir();
$freeSpace = disk_free_space($tempDir);
echo "Available disk space: " . formatBytes($freeSpace) . "\n";

if ($freeSpace < 5 * 1024 * 1024 * 1024) {
    echo "⚠ WARNING: Less than 5GB free space. Some tests may be skipped.\n";
}
echo "\n";

$results = [];

foreach ($tests as $test) {
    $requiredSpace = $test['size'] * 2.5; // Source + dest + overhead
    
    if ($freeSpace < $requiredSpace) {
        echo "Skipping {$test['label']} test (insufficient disk space)\n\n";
        continue;
    }
    
    try {
        $result = testStreaming($test['label'], $test['size']);
        $results[] = $result;
        
        // Brief pause between tests
        sleep(1);
        
    } catch (Exception $e) {
        echo "ERROR: " . $e->getMessage() . "\n\n";
    }
}

// Summary
if (!empty($results)) {
    echo "\n";
    echo "╔══════════════════════════════════════════════════════════════════════╗\n";
    echo "║                           SUMMARY                                    ║\n";
    echo "╚══════════════════════════════════════════════════════════════════════╝\n";
    echo "\n";
    
    printf("%-12s %-15s %-15s %-15s %-12s\n", 
        "Test", "Memory Used", "Avg Memory", "Efficiency", "Speed");
    echo str_repeat("-", 75) . "\n";
    
    foreach ($results as $r) {
        printf("%-12s %-15s %-15s %-15s %-12s\n",
            $r['label'],
            formatBytes($r['memUsed']),
            formatBytes($r['avgMem']),
            number_format($r['efficiency'], 6) . '%',
            formatBytes($r['speed']) . '/s'
        );
    }
    
    echo "\n";
    
    // Analysis
    echo "ANALYSIS:\n";
    echo str_repeat("-", 75) . "\n";
    
    if (count($results) > 1) {
        $firstSize = $results[0]['size'];
        $lastSize = $results[count($results) - 1]['size'];
        $firstMem = $results[0]['memUsed'];
        $lastMem = $results[count($results) - 1]['memUsed'];
        
        $sizeGrowth = $lastSize / $firstSize;
        $memGrowth = $lastMem / max($firstMem, 1);
        
        echo sprintf("File size grew %.1fx (%s → %s)\n", 
            $sizeGrowth, 
            formatBytes($firstSize), 
            formatBytes($lastSize)
        );
        echo sprintf("Memory grew %.2fx (%s → %s)\n", 
            $memGrowth,
            formatBytes($firstMem),
            formatBytes($lastMem)
        );
        echo "\n";
        
        if ($memGrowth < 2.0) {
            echo "✓ EXCELLENT: Memory usage remains constant regardless of file size!\n";
            echo "  This proves true streaming with no buffering.\n";
        } else {
            echo "⚠ WARNING: Memory usage is scaling with file size.\n";
            echo "  This suggests buffering instead of streaming.\n";
        }
    }
    
    // Check all passed integrity
    $allPassed = array_reduce($results, fn($carry, $r) => $carry && $r['integrity'], true);
    echo "\n";
    if ($allPassed) {
        echo "✓ All integrity checks passed - no data corruption\n";
    } else {
        echo "✗ Some integrity checks failed - possible data corruption\n";
    }
    
    // Memory efficiency
    $avgEfficiency = array_sum(array_column($results, 'efficiency')) / count($results);
    echo sprintf("\nAverage memory efficiency: %.6f%%\n", $avgEfficiency);
    
    if ($avgEfficiency < 0.5) {
        echo "✓ Outstanding memory efficiency! (<0.5%)\n";
    } elseif ($avgEfficiency < 1.0) {
        echo "✓ Excellent memory efficiency! (<1.0%)\n";
    } elseif ($avgEfficiency < 5.0) {
        echo "✓ Good memory efficiency! (<5.0%)\n";
    } else {
        echo "⚠ Memory efficiency could be improved (>5.0%)\n";
    }
}

echo "\n";
echo "Test completed!\n";