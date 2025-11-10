<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

class ReadLineHandler
{
    private $readCallback;
    private $prependBufferCallback;

    public function __construct(callable $readCallback, callable $prependBufferCallback)
    {
        $this->readCallback = $readCallback;
        $this->prependBufferCallback = $prependBufferCallback;
    }

    public function findLineInBuffer(string &$buffer, int $maxLength): ?string
    {
        $newlinePos = strpos($buffer, "\n");

        if ($newlinePos !== false) {
            $line = substr($buffer, 0, $newlinePos + 1);
            $buffer = substr($buffer, $newlinePos + 1);

            return $line;
        }

        if (strlen($buffer) >= $maxLength) {
            $line = substr($buffer, 0, $maxLength);
            $buffer = substr($buffer, $maxLength);

            return $line;
        }

        return null;
    }

    public function readLineFromStream(string $initialBuffer, int $maxLength): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $lineBuffer = $initialBuffer;
        $cancelled = false;

        $readMore = function () use ($promise, $maxLength, &$lineBuffer, &$readMore, &$cancelled) {
            if ($cancelled) {
                return;
            }

            ($this->readCallback)(1024)->then(function ($data) use ($promise, $maxLength, &$lineBuffer, &$readMore, &$cancelled) {
                if ($cancelled) {
                    return;
                }

                if ($data === null) {
                    $promise->resolve($lineBuffer === '' ? null : $lineBuffer);

                    return;
                }

                $lineBuffer .= $data;

                // Check for newline
                $newlinePos = strpos($lineBuffer, "\n");
                if ($newlinePos !== false) {
                    $line = substr($lineBuffer, 0, $newlinePos + 1);
                    $remaining = substr($lineBuffer, $newlinePos + 1);

                    ($this->prependBufferCallback)($remaining);

                    $promise->resolve($line);

                    return;
                }

                // Check if exceeded max length
                if (strlen($lineBuffer) >= $maxLength) {
                    $line = substr($lineBuffer, 0, $maxLength);
                    $remaining = substr($lineBuffer, $maxLength);

                    ($this->prependBufferCallback)($remaining);

                    $promise->resolve($line);

                    return;
                }

                $readMore();
            })->catch(function ($error) use ($promise, &$cancelled) {
                if (! $cancelled) {
                    $promise->reject($error);
                }
            });
        };

        $promise->setCancelHandler(function () use (&$cancelled) {
            $cancelled = true;
        });

        $readMore();

        return $promise;
    }
}
