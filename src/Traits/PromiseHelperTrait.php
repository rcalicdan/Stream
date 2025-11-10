<?php

declare(strict_types=1);

namespace Hibla\Stream\Traits;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

trait PromiseHelperTrait
{
    /**
     * Create a resolved CancellablePromise
     */
    private function createResolvedPromise(mixed $value): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * Create a rejected CancellablePromise
     */
    private function createRejectedPromise(\Throwable $reason): CancellablePromiseInterface
    {
        $promise = new CancellablePromise();
        $promise->reject($reason);

        return $promise;
    }
}
