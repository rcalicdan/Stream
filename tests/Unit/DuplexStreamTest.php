<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Promise\Promise;
use Hibla\Stream\DuplexStream;
use Hibla\Stream\Exceptions\StreamException;

describe('DuplexStream', function () {

    test('can be created successfully', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        expect($stream)->toBeInstanceOf(DuplexStream::class);
        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $writeData = "Hello, DuplexStream!\n";

            return $stream->write($writeData)
                ->then(function ($bytes) use ($writeData) {
                    expect($bytes)->toBe(strlen($writeData));
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write line with newline', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            return $stream->writeLine('Second line')
                ->then(function ($bytes) use ($resource) {
                    expect($bytes)->toBe(strlen("Second line\n"));

                    fseek($resource, 0, SEEK_SET);
                    $content = fread($resource, 1024);
                    expect($content)->toBe("Second line\n");
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write and read data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $writeData = "Hello, DuplexStream!\n";

            return $stream->write($writeData)
                ->then(function () use ($stream, $resource, $writeData) {
                    fseek($resource, 0, SEEK_SET);

                    return $stream->read();
                })
                ->then(function ($data) use ($writeData) {
                    expect($data)->not->toBeNull();
                    expect(trim($data))->toBe(trim($writeData));
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write multiple lines and read them', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $line1 = "Hello, DuplexStream!\n";

            return $stream->write($line1)
                ->then(function () use ($stream) {
                    return $stream->writeLine('Second line');
                })
                ->then(function () use ($stream, $resource) {
                    fseek($resource, 0, SEEK_SET);

                    return $stream->read();
                })
                ->then(function ($data1) use ($stream) {
                    expect($data1)->not->toBeNull();
                    expect($data1)->toContain('Hello');

                    return $stream->read();
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits data event when reading', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $dataEmitted = false;
            $emittedData = null;

            $stream->on('data', function ($data) use (&$dataEmitted, &$emittedData) {
                $dataEmitted = true;
                $emittedData = $data;
            });

            $writeData = "Test data\n";

            return $stream->write($writeData)
                ->then(function () use ($stream, $resource) {
                    fseek($resource, 0, SEEK_SET);

                    return $stream->read();
                })
                ->then(function () use (&$dataEmitted, &$emittedData) {
                    return new Promise(function ($resolve) use (&$dataEmitted, &$emittedData) {
                        Loop::nextTick(function () use ($resolve, &$dataEmitted, &$emittedData) {
                            expect($dataEmitted)->toBeTrue();
                            expect($emittedData)->toContain('Test data');
                            $resolve();
                        });
                    });
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits drain event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $drainEmitted = false;

            $stream->on('drain', function () use (&$drainEmitted) {
                $drainEmitted = true;
            });

            return $stream->write('Test')
                ->then(function () use (&$drainEmitted) {
                    return new Promise(function ($resolve) use (&$drainEmitted) {
                        Loop::nextTick(function () use ($resolve, &$drainEmitted) {
                            expect($drainEmitted)->toBeTrue();
                            $resolve();
                        });
                    });
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits close event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $closeEmitted = false;

            $stream->on('close', function () use (&$closeEmitted) {
                $closeEmitted = true;
            });

            $stream->close();

            return new Promise(function ($resolve) use (&$closeEmitted) {
                Loop::nextTick(function () use ($resolve, &$closeEmitted) {
                    expect($closeEmitted)->toBeTrue();
                    $resolve();
                });
            });
        });

        cleanupTempFile($tempPath);
    });

    test('reports correct state', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        expect($stream->isReadable())->toBeTrue();
        expect($stream->isWritable())->toBeTrue();
        expect($stream->isPaused())->toBeTrue();
        expect($stream->isEnding())->toBeFalse();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can pause and resume', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        $stream->resume();
        expect($stream->isPaused())->toBeFalse();

        $stream->pause();
        expect($stream->isPaused())->toBeTrue();

        $stream->resume();
        expect($stream->isPaused())->toBeFalse();

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits pause event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $pauseEmitted = false;

            $stream->on('pause', function () use (&$pauseEmitted) {
                $pauseEmitted = true;
            });

            $stream->resume();
            $stream->pause();

            return new Promise(function ($resolve) use (&$pauseEmitted) {
                Loop::nextTick(function () use ($resolve, &$pauseEmitted) {
                    expect($pauseEmitted)->toBeTrue();
                    $resolve();
                });
            });
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('emits resume event', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $resumeEmitted = false;

            $stream->on('resume', function () use (&$resumeEmitted) {
                $resumeEmitted = true;
            });

            $stream->resume();

            return new Promise(function ($resolve) use (&$resumeEmitted) {
                Loop::nextTick(function () use ($resolve, &$resumeEmitted) {
                    expect($resumeEmitted)->toBeTrue();
                    $resolve();
                });
            });
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write after pause and resume', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $stream->pause();
            $stream->resume();

            return $stream->write("Test after pause/resume\n")
                ->then(function ($bytes) {
                    expect($bytes)->toBeGreaterThan(0);
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can end with data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            return $stream->end("Final line\n")
                ->then(function () use ($stream) {
                    expect($stream->isEnding())->toBeTrue();
                    expect($stream->isWritable())->toBeFalse();
                })
            ;
        });

        cleanupTempFile($tempPath);
    });

    test('can end without data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            return $stream->write("Some data\n")
                ->then(function () use ($stream) {
                    return $stream->end();
                })
                ->then(function () use ($stream) {
                    expect($stream->isEnding())->toBeTrue();
                    expect($stream->isWritable())->toBeFalse();
                })
            ;
        });

        cleanupTempFile($tempPath);
    });

    test('emits finish event on end', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            $finishEmitted = false;

            $stream->on('finish', function () use (&$finishEmitted) {
                $finishEmitted = true;
            });

            return $stream->end("Final data\n")
                ->then(function () use (&$finishEmitted) {
                    return new Promise(function ($resolve) use (&$finishEmitted) {
                        Loop::nextTick(function () use ($resolve, &$finishEmitted) {
                            Loop::nextTick(function () use ($resolve, &$finishEmitted) {
                                expect($finishEmitted)->toBeTrue();
                                $resolve();
                            });
                        });
                    });
                })
            ;
        });

        cleanupTempFile($tempPath);
    });

    test('cannot write after ending', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            return $stream->end()
                ->then(function () use ($stream) {
                    try {
                        $stream->write('Should fail')->await(false);
                        $this->fail('Should have thrown an exception');
                    } catch (StreamException $e) {
                        expect($e->getMessage())->toContain('not writable');
                    }
                })
            ;
        });

        cleanupTempFile($tempPath);
    });

    test('cannot write after closing', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        $stream->close();

        try {
            $stream->write('Should fail')->await(false);

            throw new Exception('Should have thrown an exception');
        } catch (StreamException $e) {
            expect($e->getMessage())->toContain('not writable');
        }

        cleanupTempFile($tempPath);
    });

    test('cannot read after closing', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        $stream->close();

        try {
            $stream->read()->await(false);

            throw new Exception('Should have thrown an exception');
        } catch (StreamException $e) {
            expect($e->getMessage())->toContain('not readable');
        }

        cleanupTempFile($tempPath);
    });

    test('multiple close calls are safe', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        $stream->close();
        $stream->close();
        $stream->close();

        expect($stream->isReadable())->toBeFalse();
        expect($stream->isWritable())->toBeFalse();

        cleanupTempFile($tempPath);
    });

    test('handles complete write-read cycle', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $testData = "Line 1\nLine 2\nLine 3\n";

            return $stream->write($testData)
                ->then(function ($bytes) use ($stream, $resource, $testData) {
                    expect($bytes)->toBe(strlen($testData));

                    fseek($resource, 0, SEEK_SET);

                    $readData = '';

                    $readNext = function () use ($stream, &$readNext, &$readData, $testData) {
                        return $stream->read()
                            ->then(function ($chunk) use (&$readNext, &$readData, $testData) {
                                if ($chunk !== null) {
                                    $readData .= $chunk;

                                    return $readNext();
                                } else {
                                    expect($readData)->toBe($testData);
                                }
                            })
                        ;
                    };

                    return $readNext();
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('handles sequential writes', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            return $stream->write("First\n")
                ->then(function () use ($stream) {
                    return $stream->write("Second\n");
                })
                ->then(function () use ($stream) {
                    return $stream->write("Third\n");
                })
                ->then(function () use ($resource) {
                    fseek($resource, 0, SEEK_SET);
                    $content = stream_get_contents($resource);

                    expect($content)->toBe("First\nSecond\nThird\n");
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can read line by line', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            return $stream->write("Line 1\nLine 2\nLine 3\n")
                ->then(function () use ($stream, $resource) {
                    fseek($resource, 0, SEEK_SET);

                    return $stream->readLine();
                })
                ->then(function ($line1) use ($stream) {
                    expect($line1)->toBe("Line 1\n");

                    return $stream->readLine();
                })
                ->then(function ($line2) use ($stream) {
                    expect($line2)->toBe("Line 2\n");

                    return $stream->readLine();
                })
                ->then(function ($line3) {
                    expect($line3)->toBe("Line 3\n");
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can read all data at once', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $testData = "All the data in one go\n";

            return $stream->write($testData)
                ->then(function () use ($stream, $resource) {
                    fseek($resource, 0, SEEK_SET);

                    return $stream->readAll();
                })
                ->then(function ($allData) use ($testData) {
                    expect($allData)->toBe($testData);
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('throws on invalid resource', function () {
        expect(fn () => new DuplexStream('not a resource'))
            ->toThrow(StreamException::class, 'Invalid resource')
        ;
    });

    test('throws on write-only resource', function () {
        $tempPath = createTempFile();
        $writeOnlyResource = fopen($tempPath, 'w');

        expect(fn () => new DuplexStream($writeOnlyResource))
            ->toThrow(StreamException::class, 'read+write mode')
        ;

        fclose($writeOnlyResource);
        cleanupTempFile($tempPath);
    });

    test('throws on read-only resource', function () {
        $tempPath = createTempFile();
        file_put_contents($tempPath, 'test data');

        $readOnlyResource = fopen($tempPath, 'r');

        expect(fn () => new DuplexStream($readOnlyResource))
            ->toThrow(StreamException::class, 'read+write mode')
        ;

        fclose($readOnlyResource);
        cleanupTempFile($tempPath);
    });

    test('handles EOF correctly', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            return $stream->write('test')
                ->then(function () use ($stream, $resource) {
                    fseek($resource, 0, SEEK_SET);

                    return $stream->read();
                })
                ->then(function () use ($stream) {
                    return $stream->read();
                })
                ->then(function ($data) use ($stream) {
                    expect($data)->toBeNull();
                    expect($stream->isEof())->toBeTrue();
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('can write large data', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $largeData = str_repeat('x', 100000);

            return $stream->write($largeData)
                ->then(function ($bytes) use ($largeData, $resource) {
                    expect($bytes)->toBe(strlen($largeData));

                    fseek($resource, 0, SEEK_SET);
                    $content = stream_get_contents($resource);
                    expect(strlen($content))->toBe(100000);
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('handles empty write', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            return $stream->write('')
                ->then(function ($bytes) {
                    expect($bytes)->toBe(0);
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('handles write with special characters', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream, $resource) {
            $specialData = "Hello\tWorld\nWith\rSpecial\0Chars\n";

            return $stream->write($specialData)
                ->then(function ($bytes) use ($stream, $resource, $specialData) {
                    expect($bytes)->toBe(strlen($specialData));

                    fseek($resource, 0, SEEK_SET);

                    return $stream->read();
                })
                ->then(function ($data) use ($specialData) {
                    expect($data)->toBe($specialData);
                })
            ;
        });

        $stream->close();
        cleanupTempFile($tempPath);
    });

    test('state changes correctly through lifecycle', function () {
        $tempPath = createTempFile();
        $resource = fopen($tempPath, 'w+');
        $stream = new DuplexStream($resource);

        asyncTest(function () use ($stream) {
            expect($stream->isReadable())->toBeTrue();
            expect($stream->isWritable())->toBeTrue();
            expect($stream->isEnding())->toBeFalse();

            return $stream->write('test')
                ->then(function () use ($stream) {
                    expect($stream->isWritable())->toBeTrue();

                    return $stream->end();
                })
                ->then(function () use ($stream) {
                    expect($stream->isEnding())->toBeTrue();
                    expect($stream->isWritable())->toBeFalse();
                    expect($stream->isReadable())->toBeFalse();

                    $stream->close();
                    expect($stream->isReadable())->toBeFalse();
                    expect($stream->isWritable())->toBeFalse();
                })
            ;
        });

        cleanupTempFile($tempPath);
    });
});
