<?php

use Hibla\Stream\Stream;

describe('Stream Piping', function () {
    test('can pipe from readable to writable stream', function () {
        $sourceContent = 'Piped content from source to destination';
        $sourceFile = createTempFile($sourceContent);
        $destFile = createTempFile();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $bytesTransferred = $source->pipe($dest)->await(false);
        
        expect($bytesTransferred)->toBe(strlen($sourceContent));
        expect(file_get_contents($destFile))->toBe($sourceContent);
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe handles 1MB file efficiently', function () {
        $size = 1024 * 1024; 
        
        $sourceFile = tempnam(sys_get_temp_dir(), 'pipe_test_');
        $handle = fopen($sourceFile, 'wb');
        $chunk = str_repeat('X', 8192);
        for ($i = 0; $i < $size / 8192; $i++) {
            fwrite($handle, $chunk);
        }
        fclose($handle);
        unset($chunk); 
        
        $destFile = createTempFile();
        
        gc_collect_cycles();
        $startMem = memory_get_usage();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $bytesTransferred = $source->pipe($dest)->await(false);
        
        $memUsed = memory_get_usage() - $startMem;
        
        expect($bytesTransferred)->toBe($size);
        expect(filesize($destFile))->toBe($size);
        expect($memUsed)->toBeLessThan(2 * 1024 * 1024);
        
        $handle = fopen($destFile, 'rb');
        expect(fread($handle, 1))->toBe('X');
        fseek($handle, -1, SEEK_END);
        expect(fread($handle, 1))->toBe('X');
        fclose($handle);
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe handles 100MB file with constant memory', function () {
        $size = 100 * 1024 * 1024; // 100MB
        $chunkSize = 8192;
        
        $sourceFile = tempnam(sys_get_temp_dir(), 'pipe_test_');
        $handle = fopen($sourceFile, 'wb');
        $chunk = str_repeat('A', $chunkSize);
        $chunksNeeded = (int)ceil($size / $chunkSize);
        
        for ($i = 0; $i < $chunksNeeded; $i++) {
            $writeSize = min($chunkSize, $size - ($i * $chunkSize));
            fwrite($handle, substr($chunk, 0, $writeSize));
        }
        fclose($handle);
        unset($chunk);
        
        $destFile = createTempFile();
        
        gc_collect_cycles();
        $startMem = memory_get_usage();
        
        $source = Stream::readableFile($sourceFile, $chunkSize);
        $dest = Stream::writableFile($destFile);
        
        $bytesTransferred = $source->pipe($dest)->await(false);
        
        $memUsed = memory_get_usage() - $startMem;
        
        expect($bytesTransferred)->toBe($size);
        expect(filesize($destFile))->toBe($size);
        expect($memUsed)->toBeLessThan(3 * 1024 * 1024);
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    })->skip(fn() => getenv('SKIP_LARGE_TESTS') === '1', 'Large file tests disabled');

    test('memory usage scales sub-linearly with file size', function () {
        $tests = [
            ['size' => 1024 * 1024, 'label' => '1MB'],
            ['size' => 10 * 1024 * 1024, 'label' => '10MB'],
            ['size' => 50 * 1024 * 1024, 'label' => '50MB'],
        ];
        
        $results = [];
        
        foreach ($tests as $test) {
            $sourceFile = tempnam(sys_get_temp_dir(), 'scale_');
            $destFile = createTempFile();
            
            $handle = fopen($sourceFile, 'wb');
            $chunk = str_repeat('X', 8192);
            $written = 0;
            while ($written < $test['size']) {
                fwrite($handle, $chunk);
                $written += 8192;
            }
            fclose($handle);
            unset($chunk);
            
            gc_collect_cycles();
            $startMem = memory_get_usage();
            
            $source = Stream::readableFile($sourceFile);
            $dest = Stream::writableFile($destFile);
            
            $source->pipe($dest)->await(false);
            
            $memUsed = memory_get_usage() - $startMem;
            $results[] = [
                'label' => $test['label'],
                'size' => $test['size'],
                'memory' => $memUsed,
            ];
            
            cleanupTempFile($sourceFile);
            cleanupTempFile($destFile);
        }
        
        $memGrowth = $results[2]['memory'] / $results[0]['memory'];
        
        expect($memGrowth)->toBeLessThan(2.0);
        
    })->skip(fn() => getenv('SKIP_LARGE_TESTS') === '1', 'Large file tests disabled');

    test('pipe can be configured not to end destination', function () {
        $sourceFile = createTempFile('First part');
        $destFile = createTempFile();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $source->pipe($dest, ['end' => false])->await(false);
        
        expect($dest->isWritable())->toBeTrue();
        
        $dest->write(' Second part')->await(false);
        $dest->end()->await(false);
        
        expect(file_get_contents($destFile))->toBe('First part Second part');
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe can be cancelled', function () {
        $sourceFile = tempnam(sys_get_temp_dir(), 'pipe_test_');
        $handle = fopen($sourceFile, 'wb');
        for ($i = 0; $i < 128; $i++) {
            fwrite($handle, str_repeat('X', 8192));
        }
        fclose($handle);
        
        $destFile = createTempFile();
        
        $source = Stream::readableFile($sourceFile);
        $dest = Stream::writableFile($destFile);
        
        $promise = $source->pipe($dest);
        
        $promise->cancel();
        
        expect($promise->isCancelled())->toBeTrue();
        expect($source->isPaused())->toBeTrue();
        
        $source->close();
        $dest->close();
        
        cleanupTempFile($sourceFile);
        cleanupTempFile($destFile);
    });

    test('pipe handles multiple files without memory leak', function () {
        $iterations = 5;
        $size = 1024 * 1024;
        $memoryReadings = [];
        
        gc_collect_cycles();
        $baselineMem = memory_get_usage();
        
        for ($i = 0; $i < $iterations; $i++) {
            $sourceFile = tempnam(sys_get_temp_dir(), 'multi_');
            $destFile = createTempFile();
            
            $handle = fopen($sourceFile, 'wb');
            fwrite($handle, str_repeat('X', $size));
            fclose($handle);
            
            $source = Stream::readableFile($sourceFile);
            $dest = Stream::writableFile($destFile);
            
            $source->pipe($dest)->await(false);
            
            $memoryReadings[] = memory_get_usage() - $baselineMem;
            
            cleanupTempFile($sourceFile);
            cleanupTempFile($destFile);
            
            gc_collect_cycles();
        }
        
        $avgMem = array_sum($memoryReadings) / count($memoryReadings);
        $maxMem = max($memoryReadings);
        $minMem = min($memoryReadings);
        $variance = $maxMem - $minMem;
        
        expect($variance)->toBeLessThan(500 * 1024);
    });
});