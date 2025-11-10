<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

class ReadAllHandler
{
    private int $chunkSize;
    private $readCallback;

    public function __construct(int $chunkSize, callable $readCallback)
    {
        $this->chunkSize = $chunkSize;
        $this->readCallback = $readCallback;
    }

    public function readAll(string $initialBuffer, int $maxLength): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $buffer = $initialBuffer;
        $cancelled = false;

        $readMore = function () use ($promise, $maxLength, &$buffer, &$readMore, &$cancelled) {
            if ($cancelled) {
                return;
            }

            if (strlen($buffer) >= $maxLength) {
                $promise->resolve($buffer);

                return;
            }

            ($this->readCallback)(min($this->chunkSize, $maxLength - strlen($buffer)))->then(
                function ($data) use ($promise, $maxLength, &$buffer, &$readMore, &$cancelled) {
                    if ($cancelled) {
                        return;
                    }

                    if ($data === null) {
                        $promise->resolve($buffer);

                        return;
                    }

                    $buffer .= $data;
                    $readMore();
                }
            )->catch(function ($error) use ($promise, &$cancelled) {
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
