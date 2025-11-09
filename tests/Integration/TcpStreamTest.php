<?php

use Hibla\Stream\DuplexStream;
use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;

describe('DuplexStream TCP Integration', function () {

    it('handles TCP echo server and client communication', function () {
        asyncTest(function () {
            $host = '127.0.0.1';
            $port = 9090;

            $serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);

            expect($serverSocket)->not->toBeFalse("Failed to create server: $errstr");

            stream_set_blocking($serverSocket, false);

            return new Promise(function ($resolve, $reject) use ($serverSocket, $host, $port) {
                $serverStream = null;

                $cleanup = function () use (&$serverSocket, &$serverStream) {
                    if ($serverStream) {
                        $serverStream->close();
                    }
                    if (is_resource($serverSocket)) {
                        @fclose($serverSocket);
                    }
                };

                $acceptWatcher = Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$serverStream, &$acceptWatcher) {
                    $clientSocket = @stream_socket_accept($serverSocket, 0);

                    if ($clientSocket) {
                        $serverStream = new DuplexStream($clientSocket);

                        $echoData = function () use (&$serverStream, &$echoData) {
                            $serverStream->read()->then(function ($data) use (&$serverStream, &$echoData) {
                                if ($data === null) {
                                    return;
                                }

                                return $serverStream->write($data)->then(function () use (&$echoData) {
                                    $echoData();
                                });
                            })->catch(function ($error) {
                                if (strpos($error->getMessage(), 'Stream closed') === false) {
                                    throw $error;
                                }
                            });
                        };

                        $echoData();

                        Loop::removeStreamWatcher($acceptWatcher);
                    }
                }, 'read');

                Loop::addTimer(0.05, function () use ($host, $port, $resolve, $reject, $cleanup) {
                    $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

                    if (!$clientSocket) {
                        $cleanup();
                        $reject(new Exception("Client connection failed: $errstr ($errno)"));
                        return;
                    }

                    $clientStream = new DuplexStream($clientSocket);
                    $clientStream->resume();

                    $receivedData = '';

                    $clientStream->on('data', function ($data) use (&$receivedData) {
                        $receivedData .= $data;
                    });

                    $messages = [
                        "Hello, Server!\n",
                        "This is message 2\n",
                        "Final message\n"
                    ];

                    $expectedData = implode('', $messages);

                    // Send messages with delays
                    $clientStream->write($messages[0])
                        ->then(function () use ($clientStream, $messages) {
                            return new Promise(function ($res) use ($clientStream, $messages) {
                                Loop::addTimer(0.1, function () use ($res, $clientStream, $messages) {
                                    $clientStream->write($messages[1])->then($res);
                                });
                            });
                        })
                        ->then(function () use ($clientStream, $messages) {
                            return new Promise(function ($res) use ($clientStream, $messages) {
                                Loop::addTimer(0.1, function () use ($res, $clientStream, $messages) {
                                    $clientStream->write($messages[2])->then($res);
                                });
                            });
                        })
                        ->then(function () use ($expectedData, &$receivedData) {
                            // Wait for echo responses
                            return new Promise(function ($res) use ($expectedData, &$receivedData) {
                                Loop::addTimer(0.5, function () use ($res, $expectedData, &$receivedData) {
                                    expect($receivedData)->toBe($expectedData, 'Echo data should match sent data');
                                    $res(true);
                                });
                            });
                        })
                        ->then(function () use ($clientStream) {
                            return $clientStream->end();
                        })
                        ->then(function () use ($resolve, $cleanup) {
                            Loop::addTimer(0.3, function () use ($resolve, $cleanup) {
                                $cleanup();
                                $resolve(true);
                            });
                        })
                        ->catch(function ($error) use ($reject, $cleanup) {
                            $cleanup();
                            $reject($error);
                        });
                });
            });
        }, 10000);
    });

    it('handles large data transfer over TCP', function () {
        asyncTest(function () {
            $host = '127.0.0.1';
            $port = 9091;

            $serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);

            expect($serverSocket)->not->toBeFalse("Failed to create server");

            stream_set_blocking($serverSocket, false);

            return new Promise(function ($resolve, $reject) use ($serverSocket, $host, $port) {
                $serverStream = null;
                $startTime = null;

                $cleanup = function () use (&$serverSocket, &$serverStream) {
                    if ($serverStream) {
                        $serverStream->close();
                    }
                    if (is_resource($serverSocket)) {
                        @fclose($serverSocket);
                    }
                };

                // Accept connections
                $acceptWatcher = Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$serverStream, &$startTime, &$acceptWatcher, $resolve, $reject, $cleanup) {
                    $clientSocket = @stream_socket_accept($serverSocket, 0);

                    if ($clientSocket) {
                        $serverStream = new DuplexStream($clientSocket);
                        $startTime = microtime(true);

                        $serverStream->readAll(10 * 1024 * 1024)->then(function ($data) use (&$startTime, $resolve, $cleanup) {
                            $elapsed = microtime(true) - $startTime;
                            $receivedBytes = strlen($data);

                            expect($receivedBytes)->toBe(512000, 'Should receive exactly 500KB');
                            expect($elapsed)->toBeLessThan(5.0, 'Transfer should complete within 5 seconds');

                            Loop::addTimer(0.5, function () use ($resolve, $cleanup) {
                                $cleanup();
                                $resolve(true);
                            });
                        })->catch(function ($error) use ($cleanup, $reject) {
                            $cleanup();
                            $reject($error);
                        });

                        Loop::removeStreamWatcher($acceptWatcher);
                    }
                }, 'read');

                // Connect client 
                Loop::addTimer(0.05, function () use ($host, $port, $reject, $cleanup) {
                    $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

                    if (!$clientSocket) {
                        $cleanup();
                        $reject(new Exception("Client connection failed: $errstr ($errno)"));
                        return;
                    }

                    $clientStream = new DuplexStream($clientSocket);

                    $largeData = str_repeat("X", 1024 * 500);

                    $clientStream->write($largeData)
                        ->then(function ($bytes) use ($clientStream) {
                            expect($bytes)->toBe(512000, 'Should send exactly 500KB');
                            return $clientStream->end();
                        })
                        ->catch(function ($error) use ($reject, $cleanup) {
                            $cleanup();
                            $reject($error);
                        });
                });
            });
        }, 15000);
    });

    it('handles bidirectional TCP communication', function () {
        asyncTest(function () {
            $host = '127.0.0.1';
            $port = 9092;

            $serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);

            expect($serverSocket)->not->toBeFalse("Failed to create server");

            stream_set_blocking($serverSocket, false);

            return new Promise(function ($resolve, $reject) use ($serverSocket, $host, $port) {
                $serverStream = null;

                $cleanup = function () use (&$serverSocket, &$serverStream) {
                    if ($serverStream) {
                        $serverStream->close();
                    }
                    if (is_resource($serverSocket)) {
                        @fclose($serverSocket);
                    }
                };

                // Accept connections
                $acceptWatcher = Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$serverStream, &$acceptWatcher) {
                    $clientSocket = @stream_socket_accept($serverSocket, 0);

                    if ($clientSocket) {
                        $serverStream = new DuplexStream($clientSocket);

                        // Server request handler
                        $handleRequest = function () use (&$serverStream, &$handleRequest) {
                            $serverStream->readLine()->then(function ($line) use (&$serverStream, &$handleRequest) {
                                if ($line === null) {
                                    return;
                                }

                                $trimmed = trim($line);
                                $response = "Server response to: $trimmed\n";

                                return $serverStream->write($response)->then(function () use (&$handleRequest) {
                                    $handleRequest();
                                });
                            })->catch(function ($error) {
                                if (strpos($error->getMessage(), 'Stream closed') === false) {
                                    throw $error;
                                }
                            });
                        };

                        $handleRequest();

                        Loop::removeStreamWatcher($acceptWatcher);
                    }
                }, 'read');

                // Connect client
                Loop::addTimer(0.05, function () use ($host, $port, $resolve, $reject, $cleanup) {
                    $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

                    if (!$clientSocket) {
                        $cleanup();
                        $reject(new Exception("Client connection failed: $errstr ($errno)"));
                        return;
                    }

                    $clientStream = new DuplexStream($clientSocket);

                    $serverResponses = [];
                    $messages = ["Hello", "How are you?", "Goodbye"];

                    // Send and receive messages
                    $clientStream->writeLine("Hello")
                        ->then(function () use ($clientStream) {
                            return $clientStream->readLine();
                        })
                        ->then(function ($response) use ($clientStream, &$serverResponses) {
                            expect($response)->not->toBeNull('Should receive response');
                            $serverResponses[] = trim($response);
                            expect($response)->toContain('Hello');
                            return $clientStream->writeLine("How are you?");
                        })
                        ->then(function () use ($clientStream) {
                            return $clientStream->readLine();
                        })
                        ->then(function ($response) use ($clientStream, &$serverResponses) {
                            expect($response)->not->toBeNull('Should receive response');
                            $serverResponses[] = trim($response);
                            expect($response)->toContain('How are you?');
                            return $clientStream->writeLine("Goodbye");
                        })
                        ->then(function () use ($clientStream) {
                            return $clientStream->readLine();
                        })
                        ->then(function ($response) use ($clientStream, &$serverResponses, $messages) {
                            expect($response)->not->toBeNull('Should receive response');
                            $serverResponses[] = trim($response);
                            expect($response)->toContain('Goodbye');

                            expect(count($serverResponses))->toBe(count($messages), 'Should receive all responses');

                            return $clientStream->end();
                        })
                        ->then(function () use ($resolve, $cleanup) {
                            Loop::addTimer(0.5, function () use ($resolve, $cleanup) {
                                $cleanup();
                                $resolve(true);
                            });
                        })
                        ->catch(function ($error) use ($reject, $cleanup) {
                            $cleanup();
                            $reject($error);
                        });
                });
            });
        }, 10000);
    });

    it('handles multiple sequential connections', function () {
        asyncTest(function () {
            $host = '127.0.0.1';
            $port = 9093;

            $serverSocket = @stream_socket_server("tcp://$host:$port", $errno, $errstr);

            expect($serverSocket)->not->toBeFalse("Failed to create server");

            stream_set_blocking($serverSocket, false);

            return new Promise(function ($resolve, $reject) use ($serverSocket, $host, $port) {
                $streams = [];

                $cleanup = function () use (&$serverSocket, &$streams) {
                    foreach ($streams as $stream) {
                        if ($stream) {
                            $stream->close();
                        }
                    }
                    if (is_resource($serverSocket)) {
                        @fclose($serverSocket);
                    }
                };

                // Accept multiple connections
                Loop::addStreamWatcher($serverSocket, function () use ($serverSocket, &$streams) {
                    $clientSocket = @stream_socket_accept($serverSocket, 0);

                    if ($clientSocket) {
                        $stream = new DuplexStream($clientSocket);
                        $streams[] = $stream;

                        // Simple echo
                        $stream->read()->then(function ($data) use ($stream) {
                            if ($data !== null) {
                                return $stream->write($data);
                            }
                        });
                    }
                }, 'read');

                // Connect multiple clients sequentially
                $completedCount = 0;

                $connectClient = function ($index) use ($host, $port, &$connectClient, $resolve, $reject, $cleanup, &$completedCount) {
                    Loop::addTimer(0.05, function () use ($index, $host, $port, &$connectClient, $resolve, $reject, $cleanup, &$completedCount) {
                        $clientSocket = @stream_socket_client("tcp://$host:$port", $errno, $errstr, 5);

                        if (!$clientSocket) {
                            $cleanup();
                            $reject(new Exception("Client $index connection failed"));
                            return;
                        }

                        $clientStream = new DuplexStream($clientSocket);
                        $clientStream->resume();

                        $received = '';
                        $clientStream->on('data', function ($data) use (&$received) {
                            $received .= $data;
                        });

                        $message = "Message from client $index";

                        $clientStream->write($message)
                            ->then(function () use ($clientStream) {
                                return new Promise(function ($res) use ($clientStream) {
                                    Loop::addTimer(0.2, function () use ($res, $clientStream) {
                                        $clientStream->end()->then($res);
                                    });
                                });
                            })
                            ->then(function () use ($message, &$received, $index, &$connectClient, $resolve, $cleanup, &$completedCount) {
                                expect($received)->toBe($message, "Client $index should receive echo");
                                $completedCount++;

                                if ($index < 3) {
                                    $connectClient($index + 1);
                                } else {
                                    Loop::addTimer(0.5, function () use ($resolve, $cleanup, &$completedCount) {
                                        expect($completedCount)->toBe(3, 'Should complete 3 connections');
                                        $cleanup();
                                        $resolve(true);
                                    });
                                }
                            })
                            ->catch(function ($error) use ($reject, $cleanup) {
                                $cleanup();
                                $reject($error);
                            });
                    });
                };

                $connectClient(1);
            });
        }, 15000);
    })->skip(PHP_OS_FAMILY === 'Windows', 'Multiple sequential connections may be unstable on Windows');

    it('handles connection errors gracefully', function () {
        asyncTest(function () {
            $host = '127.0.0.1';
            $port = 9094;

            $errno = 0;
            $errstr = '';

            set_error_handler(function () {}, E_WARNING);
            $clientSocket = stream_socket_client("tcp://$host:$port", $errno, $errstr, 1);
            restore_error_handler();

            expect($clientSocket)->toBeFalse('Connection should fail');
            expect($errno)->not->toBe(0, 'Error code should be set');

            return Promise::resolved(true);
        }, 5000);
    });
});
