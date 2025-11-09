<?php

use Hibla\EventLoop\Loop;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function () {
})->in('Unit', 'Feature');

uses()->afterEach(function () {
    Loop::stop();
    Loop::reset();
})->in('Unit', 'Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
*/

expect()->extend('toBeResource', function () {
    return $this->toBeResource();
});

expect()->extend('toBeSocketPair', function () {
    return $this->toBeArray()
        ->and(count($this->value))->toBe(2);
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

function createSocketPair(): array
{
    return stream_socket_pair(
        STREAM_PF_UNIX,
        STREAM_SOCK_STREAM,
        STREAM_IPPROTO_IP
    );
}

function closeSocketPair(array $pair): void
{
    foreach ($pair as $socket) {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }
}

function waitForLoop(int $milliseconds = 10): void
{
    usleep($milliseconds * 1000);
    Loop::run();
}