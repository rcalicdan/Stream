<?php

declare(strict_types=1);

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
