<?php

use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * baseWrite() must tell three write outcomes apart:
 *
 *  - 0 bytes on a live stream  -> EAGAIN / backpressure: keep the remainder buffered and wait
 *    for the next writable event (send() already does this with its feof() guard);
 *  - a thrown write error      -> a real failure: tear the connection down;
 *  - a closed stream           -> a real failure: tear the connection down.
 *
 * A controlled stream wrapper is used instead of a saturated socket, so the outcomes are
 * deterministic rather than a race against how fast the kernel drains its buffers.
 */

beforeEach(function () {
    // safeEcho() writes to $outputStream, which is only initialized by a running worker.
    // Give the error paths under test a sink instead of letting feof(null) blow up.
    Worker::$outputStream ??= fopen('php://memory', 'w');
});

final class BaseWriteTestEventLoop implements EventInterface
{
    public array $readEvents = [];
    public array $writeEvents = [];
    private int $timerId = 1;

    public function delay(float $delay, callable $func, array $args = []): int
    {
        return $this->timerId++;
    }

    public function offDelay(int $timerId): bool
    {
        return true;
    }

    public function repeat(float $interval, callable $func, array $args = []): int
    {
        return $this->timerId++;
    }

    public function offRepeat(int $timerId): bool
    {
        return true;
    }

    public function onReadable($stream, callable $func): void
    {
        $this->readEvents[(int)$stream] = [$stream, $func];
    }

    public function offReadable($stream): bool
    {
        unset($this->readEvents[(int)$stream]);
        return true;
    }

    public function onWritable($stream, callable $func): void
    {
        $this->writeEvents[(int)$stream] = [$stream, $func];
    }

    public function offWritable($stream): bool
    {
        unset($this->writeEvents[(int)$stream]);
        return true;
    }

    public function onSignal(int $signal, callable $func): void
    {
    }

    public function offSignal(int $signal): bool
    {
        return false;
    }

    public function deleteAllTimer(): void
    {
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    public function getTimerCount(): int
    {
        return 0;
    }

    public function setErrorHandler(callable $errorHandler): void
    {
    }
}

/** Accepts no bytes but stays open, like a socket whose send buffer is full. */
class BaseWriteZeroByteStream
{
    public $context;

    public function stream_open(string $path, string $mode, int $options, ?string &$openedPath): bool
    {
        return true;
    }

    public function stream_write(string $data): int
    {
        return 0;
    }

    public function stream_eof(): bool
    {
        return false;
    }

    public function stream_flush(): bool
    {
        return true;
    }

    public function stream_close(): void
    {
    }

    public function stream_stat(): array
    {
        return [];
    }
}

/** The write call itself throws while the stream still looks open. */
final class BaseWriteThrowingStream extends BaseWriteZeroByteStream
{
    public function stream_write(string $data): int
    {
        throw new RuntimeException('write failed');
    }
}

/**
 * Connection whose socket and send buffer can be set directly, so a single baseWrite() call can
 * be exercised without going through send() (which has its own inline write path).
 */
function makeBaseWriteTestConnection(EventInterface $event, $socket, string $buffered = ''): TcpConnection
{
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();
    $client = stream_socket_client('tcp://' . stream_socket_get_name($server, false), $errno, $errstr, 1);
    expect($client)->not->toBeFalse();
    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $connection = new class($event, $accepted, (string)stream_socket_get_name($accepted, true)) extends TcpConnection {
        public function useSocket($socket): void
        {
            $this->socket = $socket;
        }

        public function queue(string $data): void
        {
            $this->sendBuffer = $data;
        }

        public function queued(): string
        {
            return $this->sendBuffer;
        }
    };
    $connection->useSocket($socket);
    $connection->queue($buffered);

    fclose($accepted);
    fclose($client);
    fclose($server);

    return $connection;
}

it('keeps a live connection and its buffered remainder when a non-blocking write returns zero', function () {
    stream_wrapper_register('basewrite-zero', BaseWriteZeroByteStream::class);
    $stream = fopen('basewrite-zero://live', 'w');
    expect($stream)->not->toBeFalse();

    $event = new BaseWriteTestEventLoop();
    $connection = makeBaseWriteTestConnection($event, $stream);

    $closed = false;
    $connection->onClose = function () use (&$closed): void {
        $closed = true;
    };

    // send() queues the remainder and arms the writable listener, as it does in production.
    $connection->send('pending');
    expect($connection->queued())->toBe('pending');

    // The next writable callback gets a 0-byte write. The connection must survive it and keep
    // the remainder queued for the next writable event.
    $connection->baseWrite();

    expect($closed)->toBeFalse()
        ->and($connection->getStatus(false))->toBe('ESTABLISHED')
        ->and($connection->queued())->toBe('pending')
        ->and($event->writeEvents)->not->toBe([]);

    $connection->destroy();
    if (is_resource($stream)) {
        fclose($stream);
    }
});

it('destroys the connection when the write call throws', function () {
    stream_wrapper_register('basewrite-throw', BaseWriteThrowingStream::class);
    $stream = fopen('basewrite-throw://live', 'w');
    expect($stream)->not->toBeFalse();

    $event = new BaseWriteTestEventLoop();
    $connection = makeBaseWriteTestConnection($event, $stream, 'pending');

    $closed = false;
    $connection->onClose = function () use (&$closed): void {
        $closed = true;
    };

    $connection->baseWrite();

    // A thrown write error is not backpressure - it must not be retried forever.
    expect($closed)->toBeTrue()
        ->and($connection->getStatus(false))->toBe('CLOSED');

    if (is_resource($stream)) {
        fclose($stream);
    }
});

it('destroys the connection when the stream is already closed', function () {
    $stream = fopen('php://memory', 'r+');
    expect($stream)->not->toBeFalse();
    fclose($stream);

    $event = new BaseWriteTestEventLoop();
    $connection = makeBaseWriteTestConnection($event, $stream, 'pending');

    $closed = false;
    $connection->onClose = function () use (&$closed): void {
        $closed = true;
    };

    $connection->baseWrite();

    expect($closed)->toBeTrue()
        ->and($connection->getStatus(false))->toBe('CLOSED');
});
