<?php

declare(strict_types=1);

use Hibla\EventLoop\Loop;
use Hibla\Stream\CompositeStream;
use Hibla\Stream\ReadableResourceStream;
use Hibla\Stream\ThroughStream;
use Hibla\Stream\WritableResourceStream;

describe('CompositeStream', function () {
    beforeEach(function () {
        Loop::reset();
    });

    test('creates a duplex stream from separate readable and writable streams', function () {
        [$readSocket, $writeSocket] = createSocketPair();

        $readable = new ReadableResourceStream($readSocket);
        $writable = new WritableResourceStream($writeSocket);

        $composite = new CompositeStream($readable, $writable);

        expect($composite->isReadable())->toBeTrue();
        expect($composite->isWritable())->toBeTrue();

        $composite->close();
        closeSocketPair([$readSocket, $writeSocket]);
    });

    test('forwards data events from readable stream', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $receivedData = '';

        $composite->on('data', function ($data) use (&$receivedData) {
            $receivedData .= $data;
            Loop::stop();
        });

        // Write to socket2 so it can be read from socket1
        fwrite($socket2, 'Hello from composite!');

        $composite->resume();

        Loop::run();

        expect($receivedData)->toBe('Hello from composite!');

        $composite->close();
        closeSocketPair([$socket1, $socket2]);
    });

    test('writes data through writable stream', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $drainEmitted = false;

        $composite->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
            Loop::stop();
        });

        $writeSuccess = $composite->write('Test message');

        Loop::run();

        expect($writeSuccess)->toBeTrue();
        expect($drainEmitted)->toBeTrue();

        // Read from socket1 to verify data was written
        stream_set_blocking($socket1, false);
        usleep(10000); // Small delay to ensure data is available
        $data = fread($socket1, 1024);
        expect($data)->toBe('Test message');

        $composite->close();
        closeSocketPair([$socket1, $socket2]);
    });

    test('supports bidirectional communication', function () {
        [$socket1, $socket2] = createSocketPair();

        $composite1 = new CompositeStream(
            new ReadableResourceStream($socket1),
            new WritableResourceStream($socket1)
        );

        $composite2 = new CompositeStream(
            new ReadableResourceStream($socket2),
            new WritableResourceStream($socket2)
        );

        $received1 = '';
        $received2 = '';
        $messagesReceived = 0;

        $composite2->on('data', function ($data) use (&$received1, &$messagesReceived, $composite2) {
            $received1 .= $data;
            $messagesReceived++;

            // Reply back
            $composite2->write('Hello from side 2!');
        });

        $composite1->on('data', function ($data) use (&$received2, &$messagesReceived) {
            $received2 .= $data;
            $messagesReceived++;

            if ($messagesReceived === 2) {
                Loop::stop();
            }
        });

        $composite2->resume();
        $composite1->resume();

        // Start the communication
        $composite1->write('Hello from side 1!');

        Loop::run();

        expect($received1)->toBe('Hello from side 1!');
        expect($received2)->toBe('Hello from side 2!');
        expect($messagesReceived)->toBe(2);

        $composite1->close();
        $composite2->close();
        closeSocketPair([$socket1, $socket2]);
    });

    test('pipes data through composite stream', function () {
        [$socket1, $socket2] = createSocketPair();
        [$socket3, $socket4] = createSocketPair();

        $composite = new CompositeStream(
            new ReadableResourceStream($socket1),
            new WritableResourceStream($socket2)
        );

        $destination = new WritableResourceStream($socket3);

        stream_set_blocking($socket4, false);

        $finishEmitted = false;

        $destination->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $composite->pipe($destination);

        // Write data to socket2 so it appears on socket1 (readable side)
        fwrite($socket2, 'Pipe test data');
        fclose($socket2); // Close to trigger end

        Loop::run();

        // Read from socket4 to verify piped data
        usleep(10000);
        $data = fread($socket4, 1024);

        expect($finishEmitted)->toBeTrue();
        expect($data)->toContain('Pipe test');

        closeSocketPair([$socket1, $socket2]);
        closeSocketPair([$socket3, $socket4]);
    });

    test('closes when both streams close', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $closeEmitted = false;

        $composite->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $composite->close();

        expect($closeEmitted)->toBeTrue();
        expect($composite->isReadable())->toBeFalse();
        expect($composite->isWritable())->toBeFalse();

        closeSocketPair([$socket1, $socket2]);
    });

    test('can pause and resume', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $pauseEmitted = false;
        $resumeEmitted = false;

        $composite->on('pause', function () use (&$pauseEmitted) {
            $pauseEmitted = true;
        });

        $composite->on('resume', function () use (&$resumeEmitted) {
            $resumeEmitted = true;
        });

        $composite->resume();
        $composite->pause();
        $composite->resume();

        expect($pauseEmitted)->toBeTrue();
        expect($resumeEmitted)->toBeTrue();

        $composite->close();
        closeSocketPair([$socket1, $socket2]);
    });

    test('end method ends writable stream', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $finishEmitted = false;

        $composite->on('finish', function () use (&$finishEmitted) {
            $finishEmitted = true;
            Loop::stop();
        });

        $composite->end('Final data');

        Loop::run();

        expect($finishEmitted)->toBeTrue();
        expect($composite->isWritable())->toBeFalse();

        closeSocketPair([$socket1, $socket2]);
    });

    test('cannot write after closing', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $composite->close();

        $writeSuccess = $composite->write('test');

        expect($writeSuccess)->toBeFalse();
        expect($composite->isWritable())->toBeFalse();

        closeSocketPair([$socket1, $socket2]);
    });

    test('multiple close calls are safe', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $closeCount = 0;

        $composite->on('close', function () use (&$closeCount) {
            $closeCount++;
        });

        $composite->close();
        $composite->close();
        $composite->close();

        expect($closeCount)->toBe(1);

        closeSocketPair([$socket1, $socket2]);
    });

    test('forwards drain event from writable stream', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $drainEmitted = false;

        $composite->on('drain', function () use (&$drainEmitted) {
            $drainEmitted = true;
            Loop::stop();
        });

        $composite->write('test');

        Loop::run();

        expect($drainEmitted)->toBeTrue();

        $composite->close();
        closeSocketPair([$socket1, $socket2]);
    });

    test('forwards end event from readable stream', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $endEmitted = false;

        $composite->on('end', function () use (&$endEmitted) {
            $endEmitted = true;
            Loop::stop();
        });

        $composite->resume();

        // Close socket2 to trigger end on socket1
        fclose($socket2);

        Loop::run();

        expect($endEmitted)->toBeTrue();

        $composite->close();
        closeSocketPair([$socket1, $socket2]);
    });

    test('auto-closes when readable closes and writable is already closed', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $writable = new WritableResourceStream($socket2);

        $composite = new CompositeStream($readable, $writable);

        $closeEmitted = false;

        $composite->on('close', function () use (&$closeEmitted) {
            $closeEmitted = true;
        });

        $writable->close();

        usleep(10000); // Small delay

        $readable->close();

        expect($closeEmitted)->toBeTrue();

        closeSocketPair([$socket1, $socket2]);
    });

    test('works with through stream as writable', function () {
        [$socket1, $socket2] = createSocketPair();

        $readable = new ReadableResourceStream($socket1);
        $through = new ThroughStream(fn($data) => strtoupper($data));

        $composite = new CompositeStream($readable, $through);

        $receivedData = '';

        $composite->on('data', function ($data) use (&$receivedData) {
            $receivedData .= $data;
            Loop::stop();
        });

        fwrite($socket2, 'hello');

        $composite->resume();

        Loop::run();

        expect($receivedData)->toBe('hello');

        $composite->close();
        closeSocketPair([$socket1, $socket2]);
    });
});
