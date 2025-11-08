<?php

use Hibla\EventLoop\EventLoop;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
*/

uses()->beforeEach(function () {
    EventLoop::reset();
})->in('Feature');

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

function cleanupTestFiles(array $files): void
{
    foreach ($files as $file) {
        if (file_exists($file)) {
            @unlink($file);
        }
    }
}