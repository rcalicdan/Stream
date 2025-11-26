<?php

declare(strict_types=1);

use Hibla\Stream\PromiseReadableStream;
use Hibla\Stream\PromiseWritableStream;

test('pipes readable to writable stream', function () {
    $source = createTempFile('Pipe test content');
    $dest = createTempFile();

    $rsrc = fopen($source, 'r');
    $wsrc = fopen($dest, 'w');
    stream_set_blocking($wsrc, false);

    $readable = PromiseReadableStream::fromResource($rsrc);
    $writable = PromiseWritableStream::fromResource($wsrc);

    $bytes = $readable->pipeAsync($writable)->await();
    $result = file_get_contents($dest);

    cleanupFile($source);
    cleanupFile($dest);

    expect($result)->toBe('Pipe test content')
        ->and($bytes)->toBe(17)
    ;
});
