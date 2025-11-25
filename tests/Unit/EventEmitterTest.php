<?php

declare(strict_types=1);

use Hibla\Stream\Traits\EventEmitterTrait;

class TestEmitter
{
    use EventEmitterTrait;

    public function triggerEvent(string $event, mixed ...$args): void
    {
        $this->emit($event, ...$args);
    }

    public function checkHasListeners(string $event): bool
    {
        return $this->hasListeners($event);
    }

    public function clearListeners(?string $event = null): void
    {
        $this->removeAllListeners($event);
    }
}

describe('on() method', function () {
    it('attaches a callback to an event', function () {
        $emitter = new TestEmitter();
        $called = false;

        $emitter->on('test', function () use (&$called) {
            $called = true;
        });

        $emitter->triggerEvent('test');

        expect($called)->toBeTrue();
    });

    it('allows multiple listeners on the same event', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $emitter->on('test', function () use (&$counter) {
            $counter++;
        });

        $emitter->on('test', function () use (&$counter) {
            $counter++;
        });

        $emitter->triggerEvent('test');

        expect($counter)->toBe(2);
    });

    it('passes arguments to the callback', function () {
        $emitter = new TestEmitter();
        $received = null;

        $emitter->on('test', function ($arg) use (&$received) {
            $received = $arg;
        });

        $emitter->triggerEvent('test', 'hello');

        expect($received)->toBe('hello');
    });

    it('passes multiple arguments to the callback', function () {
        $emitter = new TestEmitter();
        $received = [];

        $emitter->on('test', function (...$args) use (&$received) {
            $received = $args;
        });

        $emitter->triggerEvent('test', 'hello', 42, true);

        expect($received)->toBe(['hello', 42, true]);
    });

    it('returns self for method chaining', function () {
        $emitter = new TestEmitter();
        $result = $emitter->on('test', function () {});

        expect($result)->toBe($emitter);
    });
});

describe('once() method', function () {
    it('executes callback only once', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $emitter->once('test', function () use (&$counter) {
            $counter++;
        });

        $emitter->triggerEvent('test');
        $emitter->triggerEvent('test');
        $emitter->triggerEvent('test');

        expect($counter)->toBe(1);
    });

    it('passes arguments to the callback', function () {
        $emitter = new TestEmitter();
        $received = null;

        $emitter->once('test', function ($arg) use (&$received) {
            $received = $arg;
        });

        $emitter->triggerEvent('test', 'data');

        expect($received)->toBe('data');
    });

    it('automatically removes the listener after execution', function () {
        $emitter = new TestEmitter();

        $emitter->once('test', function () {});
        $emitter->triggerEvent('test');

        expect($emitter->checkHasListeners('test'))->toBeFalse();
    });

    it('returns self for method chaining', function () {
        $emitter = new TestEmitter();
        $result = $emitter->once('test', function () {});

        expect($result)->toBe($emitter);
    });

    it('works correctly with multiple once listeners', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $emitter->once('test', function () use (&$counter) {
            $counter++;
        });

        $emitter->once('test', function () use (&$counter) {
            $counter++;
        });

        $emitter->triggerEvent('test');
        $emitter->triggerEvent('test');

        expect($counter)->toBe(2);
    });
});

describe('off() method', function () {
    it('removes a specific listener', function () {
        $emitter = new TestEmitter();
        $called = false;

        $callback = function () use (&$called) {
            $called = true;
        };

        $emitter->on('test', $callback);
        $emitter->off('test', $callback);
        $emitter->triggerEvent('test');

        expect($called)->toBeFalse();
    });

    it('only removes the specified listener, not others', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $callback1 = function () use (&$counter) {
            $counter++;
        };

        $callback2 = function () use (&$counter) {
            $counter++;
        };

        $emitter->on('test', $callback1);
        $emitter->on('test', $callback2);
        $emitter->off('test', $callback1);
        $emitter->triggerEvent('test');

        expect($counter)->toBe(1);
    });

    it('does nothing if event does not exist', function () {
        $emitter = new TestEmitter();
        $callback = function () {};

        expect(fn () => $emitter->off('nonexistent', $callback))
            ->not->toThrow(Exception::class)
        ;
    });

    it('does nothing if callback is not registered', function () {
        $emitter = new TestEmitter();
        $callback1 = function () {};
        $callback2 = function () {};

        $emitter->on('test', $callback1);

        expect(fn () => $emitter->off('test', $callback2))
            ->not->toThrow(Exception::class)
        ;
    });

    it('removes the event key when no listeners remain', function () {
        $emitter = new TestEmitter();
        $callback = function () {};

        $emitter->on('test', $callback);
        $emitter->off('test', $callback);

        expect($emitter->checkHasListeners('test'))->toBeFalse();
    });

    it('returns self for method chaining', function () {
        $emitter = new TestEmitter();
        $result = $emitter->off('test', function () {});

        expect($result)->toBe($emitter);
    });

    it('removes all instances of the same callback', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $callback = function () use (&$counter) {
            $counter++;
        };

        $emitter->on('test', $callback);
        $emitter->on('test', $callback);
        $emitter->on('test', $callback);

        $emitter->off('test', $callback);
        $emitter->triggerEvent('test');

        expect($counter)->toBe(0);
    });
});

describe('emit() method', function () {
    it('triggers all registered listeners', function () {
        $emitter = new TestEmitter();
        $results = [];

        $emitter->on('test', function () use (&$results) {
            $results[] = 'first';
        });

        $emitter->on('test', function () use (&$results) {
            $results[] = 'second';
        });

        $emitter->triggerEvent('test');

        expect($results)->toBe(['first', 'second']);
    });

    it('does nothing if no listeners are registered', function () {
        $emitter = new TestEmitter();

        expect(fn () => $emitter->triggerEvent('test'))
            ->not->toThrow(Exception::class)
        ;
    });

    it('emits error event when listener throws exception', function () {
        $emitter = new TestEmitter();
        $errorCaught = null;

        $emitter->on('error', function ($e) use (&$errorCaught) {
            $errorCaught = $e;
        });

        $emitter->on('test', function () {
            throw new RuntimeException('Test error');
        });

        $emitter->triggerEvent('test');

        expect($errorCaught)
            ->toBeInstanceOf(RuntimeException::class)
            ->getMessage()->toBe('Test error')
        ;
    });

    it('continues executing other listeners after one throws', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $emitter->on('error', function () {});

        $emitter->on('test', function () {
            throw new RuntimeException('Error');
        });

        $emitter->on('test', function () use (&$counter) {
            $counter++;
        });

        $emitter->triggerEvent('test');

        expect($counter)->toBe(1);
    });

    it('executes listeners in order of registration', function () {
        $emitter = new TestEmitter();
        $order = [];

        $emitter->on('test', function () use (&$order) {
            $order[] = 1;
        });

        $emitter->on('test', function () use (&$order) {
            $order[] = 2;
        });

        $emitter->on('test', function () use (&$order) {
            $order[] = 3;
        });

        $emitter->triggerEvent('test');

        expect($order)->toBe([1, 2, 3]);
    });
});

describe('hasListeners() method', function () {
    it('returns true when listeners exist', function () {
        $emitter = new TestEmitter();
        $emitter->on('test', function () {});

        expect($emitter->checkHasListeners('test'))->toBeTrue();
    });

    it('returns false when no listeners exist', function () {
        $emitter = new TestEmitter();

        expect($emitter->checkHasListeners('test'))->toBeFalse();
    });

    it('returns false after all listeners are removed', function () {
        $emitter = new TestEmitter();
        $callback = function () {};

        $emitter->on('test', $callback);
        $emitter->off('test', $callback);

        expect($emitter->checkHasListeners('test'))->toBeFalse();
    });

    it('checks specific event only', function () {
        $emitter = new TestEmitter();

        $emitter->on('test1', function () {});

        expect($emitter->checkHasListeners('test1'))->toBeTrue();
        expect($emitter->checkHasListeners('test2'))->toBeFalse();
    });
});

describe('removeAllListeners() method', function () {
    it('removes all listeners for a specific event', function () {
        $emitter = new TestEmitter();

        $emitter->on('test', function () {});
        $emitter->on('test', function () {});
        $emitter->on('other', function () {});

        $emitter->clearListeners('test');

        expect($emitter->checkHasListeners('test'))->toBeFalse();
        expect($emitter->checkHasListeners('other'))->toBeTrue();
    });

    it('removes all listeners for all events when no event specified', function () {
        $emitter = new TestEmitter();

        $emitter->on('test1', function () {});
        $emitter->on('test2', function () {});
        $emitter->on('test3', function () {});

        $emitter->clearListeners();

        expect($emitter->checkHasListeners('test1'))->toBeFalse();
        expect($emitter->checkHasListeners('test2'))->toBeFalse();
        expect($emitter->checkHasListeners('test3'))->toBeFalse();
    });

    it('does nothing if event does not exist', function () {
        $emitter = new TestEmitter();

        expect(fn () => $emitter->clearListeners('nonexistent'))
            ->not->toThrow(Exception::class)
        ;
    });

    it('prevents removed listeners from being called', function () {
        $emitter = new TestEmitter();
        $called = false;

        $emitter->on('test', function () use (&$called) {
            $called = true;
        });

        $emitter->clearListeners('test');
        $emitter->triggerEvent('test');

        expect($called)->toBeFalse();
    });
});

describe('method chaining', function () {
    it('allows fluent interface', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $callback1 = function () use (&$counter) { $counter++; };
        $callback2 = function () use (&$counter) { $counter++; };

        $emitter
            ->on('test', $callback1)
            ->on('test', $callback2)
            ->once('other', function () {})
        ;

        $emitter->triggerEvent('test');

        expect($counter)->toBe(2);
    });

    it('chains on, once, and off methods', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $callback = function () use (&$counter) { $counter++; };

        $result = $emitter
            ->on('test', $callback)
            ->on('test', function () use (&$counter) { $counter++; })
            ->off('test', $callback)
        ;

        $emitter->triggerEvent('test');

        expect($result)->toBe($emitter);
        expect($counter)->toBe(1);
    });
});

describe('memory management', function () {
    it('does not leak memory with many listeners', function () {
        $emitter = new TestEmitter();

        for ($i = 0; $i < 1000; $i++) {
            $emitter->on('test', function () {});
        }

        $emitter->clearListeners();

        expect($emitter->checkHasListeners('test'))->toBeFalse();
    });

    it('properly cleans up once listeners', function () {
        $emitter = new TestEmitter();

        for ($i = 0; $i < 100; $i++) {
            $emitter->once('test', function () {});
        }

        $emitter->triggerEvent('test');

        expect($emitter->checkHasListeners('test'))->toBeFalse();
    });

    it('handles mixed on and once listeners cleanup', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $emitter->on('test', function () use (&$counter) { $counter++; });
        $emitter->once('test', function () use (&$counter) { $counter++; });
        $emitter->once('test', function () use (&$counter) { $counter++; });

        $emitter->triggerEvent('test');

        expect($counter)->toBe(3);
        expect($emitter->checkHasListeners('test'))->toBeTrue();

        $emitter->triggerEvent('test');

        expect($counter)->toBe(4);
    });
});

describe('edge cases', function () {
    it('handles empty event name', function () {
        $emitter = new TestEmitter();
        $called = false;

        $emitter->on('', function () use (&$called) {
            $called = true;
        });

        $emitter->triggerEvent('');

        expect($called)->toBeTrue();
    });

    it('handles listeners added during event emission', function () {
        $emitter = new TestEmitter();
        $counter = 0;

        $emitter->on('test', function () use (&$counter, $emitter) {
            $counter++;
            $emitter->on('test', function () use (&$counter) {
                $counter += 10;
            });
        });

        $emitter->triggerEvent('test');
        expect($counter)->toBe(1);

        $emitter->triggerEvent('test');
        expect($counter)->toBe(12);
    });

    it('handles listeners removed during event emission', function () {
        $emitter = new TestEmitter();
        $results = [];

        $callback2 = null;

        $callback1 = function () use (&$results, $emitter, &$callback2) {
            $results[] = 'first';
            $emitter->off('test', $callback2);
        };

        $callback2 = function () use (&$results) {
            $results[] = 'second';
        };

        $emitter->on('test', $callback1);
        $emitter->on('test', $callback2);

        $emitter->triggerEvent('test');

        expect($results)->toBe(['first', 'second']);

        $results = [];
        $emitter->triggerEvent('test');

        expect($results)->toBe(['first']);
    });
});
