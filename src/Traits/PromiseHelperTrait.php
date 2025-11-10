<?php

declare(strict_types=1);

namespace Hibla\Stream\Traits;

use Hibla\Promise\CancellablePromise;
use Hibla\Promise\Interfaces\CancellablePromiseInterface;

trait PromiseHelperTrait
{
    /**
     * Create a resolved CancellablePromise
     *
     * @template TValue
     * @param TValue $value
     * @return CancellablePromiseInterface<TValue>
     */
    private function createResolvedPromise(mixed $value): CancellablePromiseInterface
    {
        /** @var CancellablePromise<TValue> $promise */
        $promise = new CancellablePromise();
        $promise->resolve($value);

        return $promise;
    }

    /**
     * Create a rejected CancellablePromise
     *
     * @return CancellablePromiseInterface<never>
     */
    private function createRejectedPromise(\Throwable $reason): CancellablePromiseInterface
    {
        /** @var CancellablePromise<never> $promise */
        $promise = new CancellablePromise();
        $promise->reject($reason);

        return $promise;
    }

    /**
     * Create a resolved void CancellablePromise
     *
     * @return CancellablePromiseInterface<void>
     */
    private function createResolvedVoidPromise(): CancellablePromiseInterface
    {
        /** @var CancellablePromise<void> $promise */
        $promise = new CancellablePromise();
        $promise->resolve(null);

        return $promise;
    }
}
