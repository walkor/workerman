<?php

use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Websocket;
use Workerman\Timer;
use Workerman\Worker;

/**
 * A protocol may close the connection from inside decode() when a frame cannot be turned into a
 * message. baseRead() then has nothing to deliver, and the connection must stay usable for the
 * next one, so these run the real read loop end to end rather than calling decode() directly.
 */

/**
 * @return array{0: Select, 1: TcpConnection, 2: resource, 3: resource}
 */
function wsRejectedPackageServer(): array
{
    $event = new Select();
    Timer::init($event);

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $client = stream_socket_client('tcp://' . stream_socket_get_name($server, false), $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $connection = new TcpConnection($event, $accepted, (string)stream_socket_get_name($accepted, true));
    $connection->protocol = Websocket::class;
    $connection->maxPackageSize = 65536;

    return [$event, $connection, $client, $server];
}

function wsRejectedPackageHandshake(): string
{
    return "GET / HTTP/1.1\r\n"
        . "Host: 127.0.0.1\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: " . base64_encode(random_bytes(16)) . "\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";
}

function wsRejectedPackageFrame(int $firstByte, string $payload): string
{
    $maskKey = random_bytes(4);
    $len = strlen($payload);
    $masks = str_repeat($maskKey, intdiv($len, 4)) . substr($maskKey, 0, $len % 4);
    $header = $len <= 125 ? chr($len | 0x80) : chr(126 | 0x80) . pack('n', $len);

    return chr($firstByte) . $header . $maskKey . ($payload ^ $masks);
}

/**
 * A raw deflate stream whose first block claims the reserved BTYPE 0b11, so zlib always rejects it.
 * Random bytes are not usable here: they decode successfully often enough to make a test flaky.
 */
function wsRejectedPackageInvalidDeflate(): string
{
    return "\x07" . str_repeat("\x00", 255);
}

function wsRejectedPackageDeflate(string $plaintext): string
{
    $deflator = deflate_init(ZLIB_ENCODING_RAW, [
        'level' => -1,
        'memory' => 8,
        'window' => 15,
        'strategy' => ZLIB_DEFAULT_STRATEGY,
    ]);

    return substr(deflate_add($deflator, $plaintext), 0, -4);
}

/**
 * Run the event loop with a handler that records any diagnostic raised along the way. Expected
 * outcomes are silenced where they are handled, so nothing should reach it.
 *
 * @return list<string>
 */
function wsRejectedPackageRunLoop(Select $event): array
{
    $diagnostics = [];
    set_error_handler(static function (int $code, string $message) use (&$diagnostics): bool {
        if (error_reporting() & $code) {
            $diagnostics[] = $message;
        }
        return true;
    });
    try {
        $event->delay(0.1, static fn() => $event->stop());
        $event->run();
    } finally {
        restore_error_handler();
    }

    return $diagnostics;
}

/**
 * Drive a real websocket handshake plus one payload frame through baseRead().
 *
 * @return array{messages: list<mixed>, status: int, diagnostics: list<string>}
 */
function wsRejectedPackageRun(string $payload): array
{
    if (Worker::$outputStream === null) {
        Worker::$outputStream = fopen('php://memory', 'w+');
    }

    [$event, $connection, $client, $server] = wsRejectedPackageServer();
    $connection->onWebSocketConnect = function (TcpConnection $c): void {
        $c->headers[] = 'Sec-WebSocket-Extensions: permessage-deflate';
    };

    $messages = [];
    $connection->onMessage = function (TcpConnection $c, $data) use (&$messages): void {
        $messages[] = $data;
    };

    fwrite($client, wsRejectedPackageHandshake() . $payload);

    $diagnostics = wsRejectedPackageRunLoop($event);

    $status = $connection->getStatus();
    fclose($client);
    fclose($server);

    return ['messages' => $messages, 'status' => $status, 'diagnostics' => $diagnostics];
}

it('does not deliver a compressed frame whose output exceeds maxPackageSize', function () {
    // A few KB on the wire, 2MB once inflated, against a 64KB maxPackageSize.
    $frame = wsRejectedPackageFrame(0xc1, wsRejectedPackageDeflate(str_repeat('Z', 2 * 1024 * 1024)));

    $result = wsRejectedPackageRun($frame);

    expect($result['messages'])->toBe([])
        ->and($result['diagnostics'])->toBe([])
        ->and($result['status'])->toBeIn([TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED]);
});

it('does not deliver a compressed frame that fails to inflate', function () {
    $result = wsRejectedPackageRun(wsRejectedPackageFrame(0xc1, wsRejectedPackageInvalidDeflate()));

    expect($result['messages'])->toBe([])
        ->and($result['diagnostics'])->toBe([])
        ->and($result['status'])->toBeIn([TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED]);
});

it('still delivers a compressed frame that inflates within maxPackageSize', function () {
    $plaintext = str_repeat('inflated-payload;', 32);
    $result = wsRejectedPackageRun(wsRejectedPackageFrame(0xc1, wsRejectedPackageDeflate($plaintext)));

    expect($result['messages'])->toBe([$plaintext])
        ->and($result['diagnostics'])->toBe([]);
});

it('answers a handshake over MAX_HANDSHAKE_LENGTH with 431', function () {
    if (Worker::$outputStream === null) {
        Worker::$outputStream = fopen('php://memory', 'w+');
    }

    [$event, $connection, $client, $server] = wsRejectedPackageServer();
    $connection->onMessage = function (): void {
    };

    fwrite($client, "GET / HTTP/1.1\r\nX-Pad: " . str_repeat('A', 20000));

    expect(wsRejectedPackageRunLoop($event))->toBe([]);
    expect(fread($client, 4096))->toContain('431 Request Header Fields Too Large')
        ->and($connection->getStatus())->toBeIn([TcpConnection::STATUS_CLOSING, TcpConnection::STATUS_CLOSED]);

    fclose($client);
    fclose($server);
});
