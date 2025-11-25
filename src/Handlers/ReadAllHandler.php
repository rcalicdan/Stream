<?php

declare(strict_types=1);

namespace Hibla\Stream\Handlers;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

class ReadAllHandler
{
    /**
     * @param callable(int|null): CancellablePromiseInterface<string|null> $readCallback
     */
    public function __construct(
        private int $chunkSize,
        private $readCallback
    ) {
    }

    /**
     * @return CancellablePromiseInterface<string>
     */
    public function readAll(string $initialBuffer, int $maxLength): CancellablePromiseInterface
    {
        /** @var CancellablePromise<string> $promise */
        $promise = new CancellablePromise();
        $buffer = $initialBuffer;
        $cancelled = false;

        $promise->setCancelHandler(function () use (&$cancelled) {
            $cancelled = true;
        });

        $readMore = function () use ($promise, $maxLength, &$buffer, &$readMore, &$cancelled) {
            if ($cancelled) {
                return;
            }

            if (\strlen($buffer) >= $maxLength) {
                $promise->resolve($buffer);

                return;
            }

            $readPromise = ($this->readCallback)(min($this->chunkSize, $maxLength - strlen($buffer)));

            $readPromise->then(
                function ($data) use ($promise, &$buffer, &$readMore, &$cancelled) {
                    /** @phpstan-ignore if.alwaysFalse */
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
