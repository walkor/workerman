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
    Ws::initContext($c);
    $c->maxSendBufferSize = 1024 * 1024;
    $c->maxPackageSize = 1048576;

    return $c;
}

/**
 * A client that has sent its handshake request and is waiting for the 101 response.
 *
 * @return AsyncTcpConnection&\Mockery\MockInterface
 */
function wsTestMockAsyncConnectionAwaitingHandshake(string $secKey): AsyncTcpConnection
{
    $c = wsTestMockAsyncConnection();
    $c->context->handshakeStep = 1;
    $c->context->websocketSecKey = $secKey;

    return $c;
}

/**
 * A client whose handshake already completed, ready to receive frames.
 *
 * @return AsyncTcpConnection&\Mockery\MockInterface
 */
function wsTestMockAsyncConnectionAfterHandshake(): AsyncTcpConnection
{
    $c = wsTestMockAsyncConnection();
    $c->context->handshakeStep = 2;

    return $c;
}

function wsTestHandshakeResponse(string $secKey): string
{
    $accept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));

    return "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: $accept\r\n\r\n";
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
    $buffer = wsTestHandshakeResponse($key);

    $connection = wsTestMockAsyncConnectionAwaitingHandshake($key);
    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($buffer));

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0)
        ->and($connection->context->handshakeStep)->toBe(2);
});

it('dealHandshake flushes tmpWebsocketData over send after success', function () {
    $key = base64_encode(random_bytes(16));
    $buffer = wsTestHandshakeResponse($key);

    $connection = wsTestMockAsyncConnectionAwaitingHandshake($key);
    $connection->context->tmpWebsocketData = 'queued-frame-bytes';
    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($buffer));
    $connection->shouldReceive('send')->with('queued-frame-bytes', true)->once();

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0)
        ->and($connection->context->tmpWebsocketData)->toBe('');
});

it('dealHandshake closes when Sec-WebSocket-Accept header is missing', function () {
    $connection = wsTestMockAsyncConnectionAwaitingHandshake(base64_encode(random_bytes(16)));
    $connection->shouldReceive('close')->once();

    expect(Ws::dealHandshake("HTTP/1.1 101 Switching Protocols\r\n\r\n", $connection))->toBe(0);
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
    $buffer = "HTTP/1.1 101 Switching Protocols\r\nSec-WebSocket-Accept: wrongwrongwrong=\r\n\r\n";

    $connection = wsTestMockAsyncConnectionAwaitingHandshake(base64_encode(random_bytes(16)));
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

it('initContext resets every field the frame parser reads', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->websocketDataBuffer = 'leftover';
    $connection->context->websocketCurrentFrameLength = 42;
    $connection->context->websocketFragmented = true;
    $connection->context->tmpWebsocketData = 'queued';

    Ws::initContext($connection);

    expect((array)$connection->context)->toBe([
        'websocketDataBuffer' => '',
        'websocketCurrentFrameLength' => 0,
        'websocketFragmented' => false,
        'tmpWebsocketData' => '',
    ]);
});

it('input rejects frames with any rsv bit set since the client negotiates no extension', function (int $firstByte) {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $connection->shouldReceive('close')->once();

    expect(Ws::input(wsTestBuildServerUnmaskedFrame(chr($firstByte), 'x'), $connection))->toBe(0);
})->with([
    'rsv1' => [0xc1],
    'rsv2' => [0xa1],
    'rsv3' => [0x91],
]);

it('input rejects control frames that break RFC 6455 section 5.5', function (int $firstByte, string $payload) {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $connection->shouldReceive('close')->once();
    $connection->shouldReceive('consumeRecvBuffer');

    // An invalid control frame is rejected outright, never handled as ping/pong/close.
    $handled = false;
    $record = function () use (&$handled): void {
        $handled = true;
    };
    $connection->onWebSocketPing = $record;
    $connection->onWebSocketClose = $record;

    expect(Ws::input(wsTestBuildServerUnmaskedFrame(chr($firstByte), $payload), $connection))->toBe(0)
        ->and($handled)->toBeFalse();
})->with([
    'ping payload over 125 bytes' => [0x89, str_repeat('p', 200)],
    'close payload over 125 bytes' => [0x88, str_repeat('c', 200)],
    'fragmented ping' => [0x09, 'hb'],
    'fragmented close' => [0x08, 'bye'],
]);

it('input rejects a continuation frame when no message is in progress', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $connection->shouldReceive('close')->once();

    expect(Ws::input(wsTestBuildServerUnmaskedFrame(chr(0x80), 'orphan'), $connection))->toBe(0);
});

it('input rejects a data frame that interrupts a fragmented message', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $connection->context->websocketFragmented = true;
    $connection->shouldReceive('close')->once();

    expect(Ws::input(wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, 'BBB'), $connection))->toBe(0);
});

it('input marks the connection as fragmented only while a message is incomplete', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $connection->shouldReceive('consumeRecvBuffer');

    Ws::input(wsTestBuildServerUnmaskedFrame(chr(0x01), 'AAA'), $connection);
    expect($connection->context->websocketFragmented)->toBeTrue();

    Ws::input(wsTestBuildServerUnmaskedFrame(chr(0x80), 'BBB'), $connection);
    expect($connection->context->websocketFragmented)->toBeFalse();
});

it('assembles a fragmented text message across continuation frames', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $connection->shouldReceive('consumeRecvBuffer');

    $first = wsTestBuildServerUnmaskedFrame(chr(0x01), 'Hello, ');
    $middle = wsTestBuildServerUnmaskedFrame(chr(0x00), 'fragmented ');
    $last = wsTestBuildServerUnmaskedFrame(chr(0x80), 'world!');

    expect(Ws::input($first, $connection))->toBe(0)
        ->and(Ws::input($middle, $connection))->toBe(0)
        ->and(Ws::input($last, $connection))->toBe(strlen($last))
        ->and(Ws::decode($last, $connection))->toBe('Hello, fragmented world!');
});

it('input consumes only the close frame and hands its own payload to the callback', function () {
    /** @var AsyncTcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $closeFrame = wsTestBuildServerUnmaskedFrame(chr(0x88), "\x03\xe8bye");
    $trailing = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, 'later');

    $received = null;
    $connection->onWebSocketClose = function ($c, $data) use (&$received): void {
        $received = $data;
    };
    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($closeFrame));

    expect(Ws::input($closeFrame . $trailing, $connection))->toBe(0)
        ->and($received)->toBe("\x03\xe8bye");
});

it('input waits for the full close frame before consuming it', function () {
    $connection = wsTestMockAsyncConnectionAfterHandshake();
    $closeFrame = wsTestBuildServerUnmaskedFrame(chr(0x88), "\x03\xe8bye");

    // No consumeRecvBuffer expectation: a partial frame must not be consumed.
    expect(Ws::input(substr($closeFrame, 0, strlen($closeFrame) - 1), $connection))->toBe(0);
});

it('decode keeps the pending fragment buffer intact when a control frame arrives', function () {
    $connection = wsTestMockAsyncConnection();
    $connection->context->websocketDataBuffer = 'part-one|';

    $ping = wsTestBuildServerUnmaskedFrame(chr(0x89), 'hb');

    expect(Ws::decode($ping, $connection))->toBe('hb')
        ->and($connection->context->websocketDataBuffer)->toBe('part-one|');
});

it('dealHandshake rejects unterminated response headers past MAX_HANDSHAKE_LENGTH', function () {
    $connection = wsTestMockAsyncConnectionAwaitingHandshake(base64_encode(random_bytes(16)));
    $connection->shouldReceive('close')->once();

    $buffer = "HTTP/1.1 101 Switching Protocols\r\nX-Pad: " . str_repeat('A', 16384);

    expect(Ws::dealHandshake($buffer, $connection))->toBe(0);
});

it('dealHandshake keeps buffering while the response is still under the limit', function () {
    $connection = wsTestMockAsyncConnectionAwaitingHandshake(base64_encode(random_bytes(16)));

    // No close expectation: this is a legitimate partial read.
    expect(Ws::dealHandshake("HTTP/1.1 101 Switching Protocols\r\n", $connection))->toBe(0);
});

it('dealHandshake reports a frame pipelined behind the 101 response', function () {
    $key = base64_encode(random_bytes(16));
    $response = wsTestHandshakeResponse($key);
    $frame = wsTestBuildServerUnmaskedFrame(Ws::BINARY_TYPE_BLOB, 'first-message');

    $connection = wsTestMockAsyncConnectionAwaitingHandshake($key);
    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($response));

    expect(Ws::dealHandshake($response . $frame, $connection))->toBe(strlen($frame));
});
