<?php

use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ReadableStream;
use Hibla\Stream\WritableStream;
use Hibla\Stream\Stream;

describe('CompositeStream', function () {

    it('creates a duplex stream from separate readable and writable streams', function () {
        asyncTest(function () {
            [$readSocket, $writeSocket] = createSocketPair();

            $readable = new ReadableStream($readSocket);
            $writable = new WritableStream($writeSocket);

            $composite = new CompositeStream($readable, $writable);

            expect($composite->isReadable())->toBeTrue();
            expect($composite->isWritable())->toBeTrue();

            $composite->close();
            closeSocketPair([$readSocket, $writeSocket]);

            return Promise::resolved(true);
        });
    });

    it('forwards data events from readable stream', function () {
        asyncTest(function () {
            [$socket1, $socket2] = createSocketPair();

            $readable = new ReadableStream($socket1);
            $writable = new WritableStream($socket2);

            $composite = new CompositeStream($readable, $writable);
            $composite->resume();

            $cleanup = function () use ($composite, $socket1, $socket2) {
                $composite->close();
                closeSocketPair([$socket1, $socket2]);
            };

            return new Promise(function ($resolve, $reject) use ($composite, $socket2, $cleanup) {
                $receivedData = '';

                $composite->on('data', function ($data) use (&$receivedData, $cleanup, $resolve) {
                    $receivedData .= $data;

                    // Assert immediately when data is received
                    Loop::nextTick(function () use (&$receivedData, $cleanup, $resolve) {
                        expect($receivedData)->toBe("Hello from composite!", 'Should receive data from composite stream');
                        $cleanup();
                        $resolve(true);
                    });
                });

                // Write to socket2 so it can be read from socket1
                Loop::nextTick(function () use ($socket2) {
                    fwrite($socket2, "Hello from composite!");
                });
            });
        }, 5000);
    });

    it('writes data through writable stream', function () {
        asyncTest(function () {
            [$socket1, $socket2] = createSocketPair();

            $readable = new ReadableStream($socket1);
            $writable = new WritableStream($socket2);

            $composite = new CompositeStream($readable, $writable);

            $cleanup = function () use ($composite, $socket1, $socket2) {
                $composite->close();
                closeSocketPair([$socket1, $socket2]);
            };

            return $composite->write("Test message")
                ->then(function ($bytes) use ($socket1, $cleanup) {
                    expect($bytes)->toBe(12);

                    return new Promise(function ($resolve, $reject) use ($socket1, $cleanup) {
                        Loop::addTimer(0.2, function () use ($socket1, $cleanup, $resolve, $reject) {
                            try {
                                $data = fread($socket1, 1024);
                                expect($data)->toBe("Test message", 'Should read written data');
                                $cleanup();
                                $resolve(true);
                            } catch (\Exception $e) {
                                $cleanup();
                                $reject($e);
                            }
                        });
                    });
                })
                ->catch(function ($error) use ($cleanup) {
                    $cleanup();
                    throw $error;
                });
        }, 5000);
    });

    it('supports bidirectional communication', function () {
        asyncTest(function () {
            [$socket1, $socket2] = createSocketPair();

            $composite1 = new CompositeStream(
                new ReadableStream($socket1),
                new WritableStream($socket1)
            );

            $composite2 = new CompositeStream(
                new ReadableStream($socket2),
                new WritableStream($socket2)
            );

            $composite2->resume();
            $composite1->resume();

            $cleanup = function () use ($composite1, $composite2, $socket1, $socket2) {
                $composite1->close();
                $composite2->close();
                closeSocketPair([$socket1, $socket2]);
            };

            return new Promise(function ($resolve, $reject) use ($composite1, $composite2, $cleanup) {
                $received1 = '';
                $received2 = '';
                $messagesReceived = 0;

                $composite2->on('data', function ($data) use (&$received1, &$messagesReceived, $composite2, &$received2, $cleanup, $resolve) {
                    $received1 .= $data;

                    Loop::nextTick(function () use (&$received1, &$messagesReceived, $composite2, &$received2, $cleanup, $resolve) {
                        expect($received1)->toBe("Hello from side 1!", 'Side 2 should receive message from side 1');
                        $messagesReceived++;

                        $composite2->write("Hello from side 2!");
                    });
                });

                $composite1->on('data', function ($data) use (&$received2, &$messagesReceived, $cleanup, $resolve) {
                    $received2 .= $data;

                    Loop::nextTick(function () use (&$received2, &$messagesReceived, $cleanup, $resolve) {
                        expect($received2)->toBe("Hello from side 2!", 'Side 1 should receive message from side 2');
                        $messagesReceived++;

                        if ($messagesReceived === 2) {
                            $cleanup();
                            $resolve(true);
                        }
                    });
                });

                Loop::nextTick(function () use ($composite1) {
                    $composite1->write("Hello from side 1!");
                });
            });
        }, 5000);
    });

    it('pipes data through composite stream', function () {
        asyncTest(function () {
            [$socket1, $socket2] = createSocketPair();
            [$socket3, $socket4] = createSocketPair();

            $composite = new CompositeStream(
                new ReadableStream($socket1),
                new WritableStream($socket2)
            );

            $destination = new WritableStream($socket3);

            stream_set_blocking($socket4, false);

            $cleanup = function () use ($composite, $destination, $socket1, $socket2, $socket3, $socket4) {
                $composite->close();
                $destination->close();
                closeSocketPair([$socket1, $socket2]);
                closeSocketPair([$socket3, $socket4]);
            };

            $pipePromise = $composite->pipe($destination, ['end' => false]);

            expect($pipePromise)->toBeInstanceOf(Promise::class);

            fwrite($socket2, "Pipe test data more data");

            return new Promise(function ($resolve) use ($socket4, $cleanup) {
                Loop::addTimer(0.3, function () use ($socket4, $cleanup, $resolve) {
                    $data = fread($socket4, 1024);

                    if ($data === false || $data === '') {
                        expect(false)->toBeTrue('Failed to read piped data - pipe may not be working');
                    } else {
                        expect(strlen($data))->toBeGreaterThan(0);
                        expect($data)->toContain("Pipe test");
                    }

                    $cleanup();
                    $resolve(true);
                });
            });
        }, 5000);
    });

    it('closes when both streams close', function () {
        asyncTest(function () {
            [$socket1, $socket2] = createSocketPair();

            $readable = new ReadableStream($socket1);
            $writable = new WritableStream($socket2);

            $composite = new CompositeStream($readable, $writable);

            return new Promise(function ($resolve, $reject) use ($composite, $socket1, $socket2) {
                $cleanup = function () use ($socket1, $socket2) {
                    closeSocketPair([$socket1, $socket2]);
                };

                $composite->on('close', function () use ($composite, $cleanup, $resolve, $reject) {
                    try {
                        expect($composite->isReadable())->toBeFalse();
                        expect($composite->isWritable())->toBeFalse();
                        $cleanup();
                        $resolve(true);
                    } catch (\Exception $e) {
                        $cleanup();
                        $reject($e);
                    }
                });

                $composite->close();
            });
        }, 5000);
    });

    it('creates composite stream using Stream factory', function () {
        asyncTest(function () {
            [$socket1, $socket2] = createSocketPair();

            $readable = Stream::readable($socket1);
            $writable = Stream::writable($socket2);

            $composite = Stream::composite($readable, $writable);

            expect($composite->isReadable())->toBeTrue();
            expect($composite->isWritable())->toBeTrue();

            $composite->close();
            closeSocketPair([$socket1, $socket2]);

            return Promise::resolved(true);
        });
    });

    it('handles stdio composite stream', function () {
        $stdio = Stream::stdio();

        expect($stdio)->toBeInstanceOf(CompositeStream::class);
        expect($stdio->isReadable())->toBeTrue();
        expect($stdio->isWritable())->toBeTrue();
    });
});
