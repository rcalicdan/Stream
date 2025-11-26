<?php

declare(strict_types=1);

function cleanupTempFile(string $file): void
{
    if (file_exists($file)) {
        @unlink($file);
    }
}

function createSocketPair(): array
{
    if (stripos(PHP_OS, 'WIN') === 0) {
        $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
        if (! $server) {
            throw new RuntimeException("Failed to create server socket: $errstr");
        }

        $serverAddress = stream_socket_get_name($server, false);

        $client = stream_socket_client("tcp://$serverAddress", $errno, $errstr, 5);
        if (! $client) {
            fclose($server);

            throw new RuntimeException("Failed to create client socket: $errstr");
        }

        $accepted = stream_socket_accept($server, 5);
        if (! $accepted) {
            fclose($client);
            fclose($server);

            throw new RuntimeException('Failed to accept connection');
        }

        fclose($server);

        return [$client, $accepted];
    }

    $pair = stream_socket_pair(
        STREAM_PF_UNIX,
        STREAM_SOCK_STREAM,
        STREAM_IPPROTO_IP
    );

    if ($pair === false) {
        throw new RuntimeException('Failed to create socket pair');
    }

    return $pair;
}

function closeSocketPair(array $pair): void
{
    foreach ($pair as $socket) {
        if (is_resource($socket)) {
            @fclose($socket);
        }
    }
}

function createTempFile(string $content = ''): string
{
    $file = tempnam(sys_get_temp_dir(), 'test_');
    file_put_contents($file, $content);

    return $file;
}

function cleanupFile(string $file): void
{
    if (file_exists($file)) {
        unlink($file);
    }
}
