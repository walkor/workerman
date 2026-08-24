<?php

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;

class SslConnectionTestEventLoop implements EventInterface
{
    public array $readEvents = [];
    public array $writeEvents = [];
    public array $delayTimers = [];
    private int $timerId = 1;

    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $this->delayTimers[$timerId] = [$func, $args];
        return $timerId;
    }

    public function offDelay(int $timerId): bool
    {
        if (!isset($this->delayTimers[$timerId])) {
            return false;
        }
        unset($this->delayTimers[$timerId]);
        return true;
    }

    public function repeat(float $interval, callable $func, array $args = []): int
    {
        return $this->delay($interval, $func, $args);
    }

    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    public function onReadable($stream, callable $func): void
    {
        $this->readEvents[(int)$stream] = [$stream, $func];
    }

    public function offReadable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd])) {
            return false;
        }
        unset($this->readEvents[$fd]);
        return true;
    }

    public function onWritable($stream, callable $func): void
    {
        $this->writeEvents[(int)$stream] = [$stream, $func];
    }

    public function offWritable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->writeEvents[$fd])) {
            return false;
        }
        unset($this->writeEvents[$fd]);
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
        $this->delayTimers = [];
    }

    public function run(): void
    {
    }

    public function stop(): void
    {
    }

    public function getTimerCount(): int
    {
        return count($this->delayTimers);
    }

    public function setErrorHandler(callable $errorHandler): void
    {
    }
}

it('writes ssl data directly before waiting for writable event', function () {
    $event = new SslConnectionTestEventLoop();
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $serverName = stream_socket_get_name($server, false);
    expect($serverName)->not->toBeFalse();

    $client = stream_socket_client('tcp://' . $serverName, $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $connection = new class($event, $accepted, (string)stream_socket_get_name($accepted, true)) extends TcpConnection {
        public function markSslHandshakeComplete(): void
        {
            $this->transport = 'ssl';
            $this->sslHandshakeCompleted = true;
        }
    };
    $connection->markSslHandshakeComplete();

    expect($connection->send('hello', true))->toBeTrue();
    expect($connection->getSendBufferQueueSize())->toBe(0);
    expect($event->writeEvents)->toBe([]);
    expect(fread($client, 5))->toBe('hello');

    $connection->destroy();
    fclose($client);
    fclose($server);
});

it('reports the reason and never connects when the ssl negotiation is rejected', function () {
    $event = new SslConnectionTestEventLoop();
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $serverName = stream_socket_get_name($server, false);
    expect($serverName)->not->toBeFalse();

    $client = stream_socket_client('tcp://' . $serverName, $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    // Anything that is not a TLS record makes stream_socket_enable_crypto() fail outright
    // instead of asking for more data.
    fwrite($accepted, str_repeat("NOT-A-TLS-RECORD", 64));
    $read = [$client];
    $write = $except = [];
    expect(stream_select($read, $write, $except, 1))->toBe(1);

    $connection = new class("ssl://$serverName") extends AsyncTcpConnection {
        public function attach($socket, EventInterface $eventLoop): void
        {
            $this->socket = $socket;
            $this->eventLoop = $eventLoop;
            $this->status = self::STATUS_CONNECTING;
            $this->transport = 'ssl';
            stream_set_blocking($socket, false);
        }
    };
    $connection->attach($client, $event);

    $calls = [];
    $connection->onConnect = function () use (&$calls): void {
        $calls[] = ['connect'];
    };
    $connection->onError = function ($connection, $code, $message) use (&$calls): void {
        $calls[] = ['error', $code, $message, $connection->getStatus(false)];
    };
    $connection->onClose = function () use (&$calls): void {
        $calls[] = ['close'];
    };

    $connection->checkConnection();

    // onError has to come first, otherwise the application only sees an unexplained disconnect.
    expect($calls)->toHaveCount(2)
        ->and($calls[0][0])->toBe('error')
        ->and($calls[0][1])->toBe(AsyncTcpConnection::CONNECT_FAIL)
        ->and($calls[0][2])->toContain("SSL handshake with $serverName failed")
        ->and($calls[0][2])->not->toBe("SSL handshake with $serverName failed")
        ->and($calls[0][3])->toBe('CLOSING')
        ->and($calls[1][0])->toBe('close')
        ->and($connection->getStatus(false))->toBe('CLOSED')
        ->and($connection->onConnect)->toBeNull()
        ->and($event->readEvents)->toBe([])
        ->and($event->writeEvents)->toBe([]);

    fclose($accepted);
    fclose($server);
});

it('does not establish an async ssl connection until the handshake succeeds', function () {
    $event = new SslConnectionTestEventLoop();
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $serverName = stream_socket_get_name($server, false);
    expect($serverName)->not->toBeFalse();

    $client = stream_socket_client('tcp://' . $serverName, $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $connection = new class('ssl://127.0.0.1:443') extends AsyncTcpConnection {
        public int $handshakeCalls = 0;
        public int $checkConnectionCalls = 0;
        public array $handshakeResults = [0, true];

        public function attach($socket, EventInterface $eventLoop): void
        {
            $this->socket = $socket;
            $this->eventLoop = $eventLoop;
            $this->status = self::STATUS_CONNECTING;
            $this->transport = 'ssl';
        }

        public function checkConnection(): void
        {
            ++$this->checkConnectionCalls;
            parent::checkConnection();
        }

        public function doSslHandshake($socket): bool|int
        {
            ++$this->handshakeCalls;
            return array_shift($this->handshakeResults);
        }

        public function sslHandshakeIsComplete(): bool
        {
            return $this->sslHandshakeCompleted === true;
        }
    };
    $connection->attach($accepted, $event);
    $callbacks = [];
    $connection->onConnect = function ($connection) use (&$callbacks): void {
        $callbacks[] = ['connect', $connection->getStatus(false)];
    };

    $connection->checkConnection();

    expect($connection->handshakeCalls)->toBe(1)
        ->and($connection->getStatus(false))->toBe('CONNECTING')
        ->and($connection->sslHandshakeIsComplete())->toBeFalse()
        ->and($event->readEvents)->toHaveKey((int)$accepted)
        ->and($callbacks)->toBe([]);

    // A connected socket is always writable, so waiting on writable here would spin the event loop
    // at full speed for the whole handshake round trip.
    expect($event->writeEvents)->toBe([]);

    // The retry has to use the dedicated SSL callback. Re-entering checkConnection() would replay the
    // connect sequence, including the proxy CONNECT/SOCKS5 negotiation, on every retry.
    [$stream, $onReadable] = $event->readEvents[(int)$accepted];
    $onReadable($stream);

    expect($connection->checkConnectionCalls)->toBe(1)
        ->and($connection->handshakeCalls)->toBe(2)
        ->and($connection->sslHandshakeIsComplete())->toBeTrue()
        ->and($connection->getStatus(false))->toBe('ESTABLISHED')
        ->and($callbacks)->toBe([['connect', 'ESTABLISHED']])
        ->and($event->readEvents)->toHaveKey((int)$accepted)
        ->and($event->writeEvents)->toBe([]);

    $connection->destroy();
    fclose($client);
    fclose($server);
});

it('flushes data queued before an async ssl handshake completes', function () {
    $event = new SslConnectionTestEventLoop();
    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $serverName = stream_socket_get_name($server, false);
    expect($serverName)->not->toBeFalse();

    $client = stream_socket_client('tcp://' . $serverName, $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $connection = new class('ssl://127.0.0.1:443') extends AsyncTcpConnection {
        public array $handshakeResults = [0, true];

        public function attach($socket, EventInterface $eventLoop): void
        {
            $this->socket = $socket;
            $this->eventLoop = $eventLoop;
            $this->status = self::STATUS_CONNECTING;
            $this->transport = 'ssl';
        }

        public function doSslHandshake($socket): bool|int
        {
            return array_shift($this->handshakeResults);
        }
    };
    $connection->attach($accepted, $event);

    expect($connection->send('queued', true))->toBeNull();
    $connection->checkConnection();

    expect($connection->getStatus(false))->toBe('CONNECTING')
        ->and($event->writeEvents)->toBe([]);

    [$stream, $onReadable] = $event->readEvents[(int)$accepted];
    $onReadable($stream);

    expect($connection->getStatus(false))->toBe('ESTABLISHED')
        ->and($event->writeEvents)->toHaveKey((int)$accepted);

    [, $onWritable] = $event->writeEvents[(int)$accepted];
    $onWritable($accepted);

    expect(fread($client, 6))->toBe('queued')
        ->and($connection->getSendBufferQueueSize())->toBe(0)
        ->and($event->writeEvents)->toBe([]);

    $connection->destroy();
    fclose($client);
    fclose($server);
});
