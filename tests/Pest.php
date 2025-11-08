<?php

use Hibla\EventLoop\Loop;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function () {
    // Ensure clean state before each test
})->in('Unit', 'Feature');

uses()->afterEach(function () {
    // Clean up after each test
    Loop::stop();
})->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeResource', function () {
    return $this->toBeResource();
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
*/

function createTempFile(string $content = ''): string
{
    $file = tempnam(sys_get_temp_dir(), 'stream_test_');
    if ($content) {
        file_put_contents($file, $content);
    }
    return $file;
}

function cleanupTempFile(string $file): void
{
    if (file_exists($file)) {
        @unlink($file);
    }
}