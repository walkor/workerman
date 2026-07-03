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

it('retries async ssl handshake when non-blocking crypto needs more data', function () {
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

        public function attach($socket, EventInterface $eventLoop): void
        {
            $this->socket = $socket;
            $this->eventLoop = $eventLoop;
            $this->status = self::STATUS_CONNECTING;
            $this->transport = 'ssl';
        }

        public function doSslHandshake($socket): int
        {
            ++$this->handshakeCalls;
            return 0;
        }

        public function sslHandshakeIsComplete(): bool
        {
            return $this->sslHandshakeCompleted === true;
        }
    };
    $connection->attach($accepted, $event);

    $connection->checkConnection();

    expect($connection->handshakeCalls)->toBe(1);
    expect($connection->getStatus())->toBe(TcpConnection::STATUS_CONNECTING);
    expect($connection->sslHandshakeIsComplete())->toBeFalse();
    expect($event->readEvents)->toHaveKey((int)$accepted);
    expect($event->writeEvents)->toHaveKey((int)$accepted);

    $connection->destroy();
    fclose($client);
    fclose($server);
});
