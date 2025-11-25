<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

class ReadLineHandler
{
    /**
     * @param callable(int): CancellablePromiseInterface<string|null> $readCallback
     * @param callable(string): void $prependBufferCallback
     */
    public function __construct(
        private $readCallback,
        private $prependBufferCallback
    ) {
    }

    public function findLineInBuffer(string &$buffer, int $maxLength): ?string
    {
        $newlinePos = strpos($buffer, "\n");

        if ($newlinePos !== false) {
            $line = substr($buffer, 0, $newlinePos + 1);
            $buffer = substr($buffer, $newlinePos + 1);

            return $line;
        }

        if (\strlen($buffer) >= $maxLength) {
            $line = substr($buffer, 0, $maxLength);
            $buffer = substr($buffer, $maxLength);

            return $line;
        }

        return null;
    }

    /**
     * @return CancellablePromiseInterface<string|null>
     */
    public function readLineFromStream(string $initialBuffer, int $maxLength): CancellablePromiseInterface
    {
        /** @var CancellablePromise<string|null> $promise */
        $promise = new CancellablePromise();
        $lineBuffer = $initialBuffer;
        $cancelled = false;

        $promise->setCancelHandler(function () use (&$cancelled) {
            $cancelled = true;
        });

        $readMore = function () use ($promise, $maxLength, &$lineBuffer, &$readMore, &$cancelled) {
            if ($cancelled) {
                return;
            }

            $readPromise = ($this->readCallback)(1024);

            $readPromise->then(function ($data) use ($promise, $maxLength, &$lineBuffer, &$readMore, &$cancelled) {
                /** @phpstan-ignore if.alwaysFalse */
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
                /** @phpstan-ignore if.alwaysFalse */
                if ($cancelled) {
                    return;
                }
                $promise->reject($error);
            });
        };

        $readMore();

        return $promise;
    }
}
