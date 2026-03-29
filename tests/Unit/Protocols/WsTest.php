<?php

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Protocols\Ws;
use Workerman\Worker;

beforeEach(function () {
    if (Worker::$outputStream === null) {
        Worker::$outputStream = fopen('php://memory', 'w+');
    }
});

/**
 * 服务端 → 客户端：RFC 规定为 **不掩码** 帧（与 Websocket::decode 的 masked 帧相对）。
 */
function wsTestBuildServerUnmaskedFrame(string $firstByte, string $payload): string
{
    $len = strlen($payload);
    if ($len <= 125) {
        return $firstByte . chr($len) . $payload;
    }
    if ($len <= 65535) {
        return $firstByte . chr(126) . pack('n', $len) . $payload;
    }
    if ($len <= 0xFFFFFFFF) {
        return $firstByte . chr(127) . pack('N', 0) . pack('N', $len) . $payload;
    }

    throw new InvalidArgumentException('payload too long for test helper');
}

function wsTestXorMask(string $data, string $maskKey): string
{
    $len = strlen($data);
    $masks = str_repeat($maskKey, (int) floor($len / 4)) . substr($maskKey, 0, $len % 4);

    return $data ^ $masks;
}

/**
 * @return AsyncTcpConnection&\Mockery\MockInterface
 */
function wsTestMockAsyncConnection(): AsyncTcpConnection
{
    /** @var AsyncTcpConnection&\Mockery\MockInterface $c */
    $c = Mockery::mock(AsyncTcpConnection::class);
    $c->context = new stdClass();
    $c->context->websocketDataBuffer = '';
    $c->context->websocketCurrentFrameLength = 0;
    $c->maxSendBufferSize = 1024 * 1024;
    $c->maxPackageSize = 1048576;

    return $c;
}

it('decode decodes unmasked text frame from server', function () {
    $connection = wsTestMockAsyncConnection();
    $plaintext = 'Hello, client!';
    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, $plaintext);

    expect(Ws::decode($frame, $connection))->toBe($plaintext);
});

it('decode decodes unmasked binary frame from server', function () {
    $connection = wsTestMockAsyncConnection();
    $plaintext = "bin\x00\xff";
    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_ARRAYBUFFER, $plaintext);

    expect(Ws::decode($frame, $connection))->toBe($plaintext);
});

it('decode handles extended 16-bit payload length (126)', function () {
    $connection = wsTestMockAsyncConnection();
    $plaintext = str_repeat('x', 200);
    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, $plaintext);

    expect(Ws::decode($frame, $connection))->toBe($plaintext);
});

it('decode handles 127 extended 64-bit length frame', function () {
    $connection = wsTestMockAsyncConnection();
    $plaintext = str_repeat('q', 65536);
    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, $plaintext);

    expect(ord($frame[1]))->toBe(127)
        ->and(Ws::decode($frame, $connection))->toBe($plaintext);
});

it('encode sends masked client frame with zero mask (payload unchanged)', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 2;

    $plaintext = 'Hello';
    $out = Ws::encode($plaintext, $connection);

    expect($out[0])->toBe(Ws::BINARY_TYPE_BLOB)
        ->and(ord($out[1]))->toBe(0x80 | strlen($plaintext))
        ->and(substr($out, 2, 4))->toBe("\x00\x00\x00\x00")
        ->and(substr($out, 6))->toBe($plaintext);
});

it('encode uses BINARY_TYPE_ARRAYBUFFER when set', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 2;
    $connection->websocketType = Ws::BINARY_TYPE_ARRAYBUFFER;

    $plaintext = "a\x00";
    $out = Ws::encode($plaintext, $connection);

    expect($out[0])->toBe(Ws::BINARY_TYPE_ARRAYBUFFER)
        ->and(substr($out, 6))->toBe($plaintext);
});

it('encode uses 126 extended length when payload is 126–65535 bytes', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 2;

    $plaintext = str_repeat('z', 200);
    $out = Ws::encode($plaintext, $connection);

    expect(ord($out[1]))->toBe(0x80 | 126)
        ->and(substr($out, 8))->toBe($plaintext);
});

it('encode buffers frame to tmpWebsocketData while handshakeStep is 1', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 1;
    $connection->context->tmpWebsocketData = '';

    $plaintext = 'queued';
    $out = Ws::encode($plaintext, $connection);

    expect($out)->toBe('')
        ->and($connection->context->tmpWebsocketData)->not->toBe('')
        ->and(strlen($connection->context->tmpWebsocketData))->toBe(2 + 4 + strlen($plaintext));
});

it('dealHandshake completes when Sec-WebSocket-Accept matches', function () {
    $key = base64_encode(random_bytes(16));
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $buffer = "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: $accept\r\n\r\n";

    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = Mockery::mock(AsyncTcpConnection::class);
    $connection->context = new stdClass();
    $connection->context->handshakeStep = 1;
    $connection->context->websocketSecKey = $key;
    $connection->context->websocketCurrentFrameLength = 0;
    $connection->context->websocketDataBuffer = '';
    $connection->context->tmpWebsocketData = '';

    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($buffer));

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0)
        ->and($connection->context->handshakeStep)->toBe(2);
});

it('dealHandshake flushes tmpWebsocketData over send after success', function () {
    $key = base64_encode(random_bytes(16));
    $accept = base64_encode(sha1($key . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
    $buffer = "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: $accept\r\n\r\n";

    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = Mockery::mock(AsyncTcpConnection::class);
    $connection->context = new stdClass();
    $connection->context->handshakeStep = 1;
    $connection->context->websocketSecKey = $key;
    $connection->context->websocketCurrentFrameLength = 0;
    $connection->context->websocketDataBuffer = '';
    $connection->context->tmpWebsocketData = 'queued-frame-bytes';

    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($buffer));
    $connection->shouldReceive('send')->with('queued-frame-bytes', true)->once();

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0)
        ->and($connection->context->tmpWebsocketData)->toBe('');
});

it('dealHandshake closes when Sec-WebSocket-Accept header is missing', function () {
    $buffer = "HTTP/1.1 101 Switching Protocols\r\n\r\n";

    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = Mockery::mock(AsyncTcpConnection::class);
    $connection->context = new stdClass();
    $connection->context->handshakeStep = 1;
    $connection->context->websocketSecKey = base64_encode(random_bytes(16));
    $connection->context->websocketCurrentFrameLength = 0;
    $connection->context->websocketDataBuffer = '';
    $connection->context->tmpWebsocketData = '';

    $connection->shouldReceive('close')->once();

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0);
});

it('input returns 0 when handshake HTTP response is not yet complete', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 1;

    expect(Ws::input("HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: ", $connection))->toBe(0);
});

it('input closes when frame size exceeds maxPackageSize', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 2;
    $connection->maxPackageSize = 12;
    $connection->shouldReceive('close')->once();

    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, str_repeat('x', 20));

    expect(Ws::input($frame, $connection))->toBe(0);
});

it('dealHandshake rejects wrong Sec-WebSocket-Accept', function () {
    $key = base64_encode(random_bytes(16));
    $buffer = "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: wrongwrongwrong=\r\n\r\n";

    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = Mockery::mock(AsyncTcpConnection::class);
    $connection->context = new stdClass();
    $connection->context->handshakeStep = 1;
    $connection->context->websocketSecKey = $key;
    $connection->context->websocketCurrentFrameLength = 0;
    $connection->context->websocketDataBuffer = '';
    $connection->context->tmpWebsocketData = '';

    $connection->shouldReceive('close')->once();

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0);
});

it('input returns -1 when handshakeStep is not set', function () {
    $connection = wsTestMockAsyncConnection();
    unset($connection->context->handshakeStep);

    expect(Ws::input("\x81\x05hello", $connection))->toBe(-1);
});

it('input closes connection when server sends masked frame', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 2;

    $connection->shouldReceive('close')->once();

    $mask = "\xaa\xbb\xcc\xdd";
    $payload = 'abc';
    $maskedPayload = wsTestXorMask($payload, $mask);
    $maskedFrame = chr(0x81) . chr(0x80 | 3) . $mask . $maskedPayload;

    expect(Ws::input($maskedFrame, $connection))->toBe(0);
});

it('input returns full frame length for unmasked text frame after handshake', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->handshakeStep = 2;

    $payload = 'Hi';
    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, $payload);

    expect(Ws::input($frame, $connection))->toBe(strlen($frame));
});
