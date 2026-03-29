<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Websocket;
use Workerman\Worker;

beforeEach(function () {
    if (Worker::$outputStream === null) {
        Worker::$outputStream = fopen('php://memory', 'w+');
    }
});

/**
 * Build permessage-deflate payload the same way as Websocket::deflate (raw deflate, strip last 4 bytes).
 */
function wsTestRawDeflatePayload(string $plaintext): string
{
    $deflator = deflate_init(ZLIB_ENCODING_RAW, [
        'level' => -1,
        'memory' => 8,
        'window' => 15,
        'strategy' => ZLIB_DEFAULT_STRATEGY,
    ]);

    return substr(deflate_add($deflator, $plaintext), 0, -4);
}

function wsTestMask(string $data, string $maskKey): string
{
    $len = strlen($data);
    $masks = str_repeat($maskKey, (int) floor($len / 4)) . substr($maskKey, 0, $len % 4);

    return $data ^ $masks;
}

/**
 * Client → server frame (masked), matching Websocket::decode expectations.
 */
function wsTestBuildMaskedFrame(string $firstByte, string $payloadUnmasked, string $maskKey = "\x37\x69\x1a\x5a"): string
{
    $len = strlen($payloadUnmasked);
    $masked = wsTestMask($payloadUnmasked, $maskKey);

    if ($len <= 125) {
        return $firstByte . chr($len | 0x80) . $maskKey . $masked;
    }

    if ($len <= 65535) {
        return $firstByte . chr(126 | 0x80) . pack('n', $len) . $maskKey . $masked;
    }

    throw new InvalidArgumentException('payload too long for test helper');
}

/**
 * @return TcpConnection&\Mockery\MockInterface
 */
function wsTestMockWebSocketConnection(): TcpConnection
{
    /** @var TcpConnection&\Mockery\MockInterface $c */
    $c = Mockery::mock(TcpConnection::class);
    $c->context = new stdClass();
    $c->context->websocketDataBuffer = '';
    $c->context->websocketCurrentFrameLength = 0;
    $c->maxPackageSize = 1024 * 1024;

    return $c;
}

/**
 * @return TcpConnection&\Mockery\MockInterface
 */
function wsTestMockWebSocketConnectionForInput(): TcpConnection
{
    /** @var TcpConnection&\Mockery\MockInterface $c */
    $c = Mockery::mock(TcpConnection::class);
    $c->context = new stdClass();
    $c->context->websocketDataBuffer = '';
    $c->context->websocketCurrentFrameLength = 0;
    $c->maxPackageSize = 1024 * 1024;
    $c->context->websocketHandshake = true;

    return $c;
}

it('decode decodes masked text frame', function () {
    $connection = wsTestMockWebSocketConnection();
    $plaintext = 'Hello, WebSocket!';
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, $plaintext);

    expect(Websocket::decode($frame, $connection))->toBe($plaintext);
});

it('decode inflates masked permessage-deflate text frame (RSV1), including UTF-8', function () {
    $connection = wsTestMockWebSocketConnection();
    $plaintext = 'Compressed payload with entropy 你好，世界';
    $compressed = wsTestRawDeflatePayload($plaintext);
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB_DEFLATE, $compressed);

    expect(Websocket::decode($frame, $connection))->toBe($plaintext);
});

it('decode inflates masked permessage-deflate binary frame (RSV1)', function () {
    $connection = wsTestMockWebSocketConnection();
    $plaintext = "binary\x00\xff";
    $compressed = wsTestRawDeflatePayload($plaintext);
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_ARRAYBUFFER_DEFLATE, $compressed);

    expect(Websocket::decode($frame, $connection))->toBe($plaintext);
});

it('decode uses non-zero mask key correctly', function () {
    $connection = wsTestMockWebSocketConnection();
    $plaintext = 'masked';
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, $plaintext, "\x01\x02\x03\x04");

    expect(Websocket::decode($frame, $connection))->toBe($plaintext);
});

it('decode handles extended 16-bit payload length for masked deflate frame', function () {
    $connection = wsTestMockWebSocketConnection();
    $plaintext = random_bytes(200);
    $compressed = wsTestRawDeflatePayload($plaintext);
    expect(strlen($compressed))->toBeGreaterThan(125);
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_ARRAYBUFFER_DEFLATE, $compressed);

    expect(Websocket::decode($frame, $connection))->toBe($plaintext);
});

it('encode sends unmasked text frame when handshake is complete', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = true;
    $connection->websocketType = Websocket::BINARY_TYPE_BLOB;

    $plaintext = 'Hello';
    $out = Websocket::encode($plaintext, $connection);

    expect($out[0])->toBe(Websocket::BINARY_TYPE_BLOB)
        ->and(ord($out[1]))->toBe(strlen($plaintext))
        ->and(substr($out, 2))->toBe($plaintext);
});

it('encode sends raw-deflate payload when BINARY_TYPE_BLOB_DEFLATE', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = true;
    $connection->websocketType = Websocket::BINARY_TYPE_BLOB_DEFLATE;

    $plaintext = 'repeat-repeat-repeat-repeat-repeat-repeat';
    $out = Websocket::encode($plaintext, $connection);

    $deflated = wsTestRawDeflatePayload($plaintext);
    expect($out[0])->toBe(Websocket::BINARY_TYPE_BLOB_DEFLATE)
        ->and(ord($out[1]))->toBe(strlen($deflated))
        ->and(substr($out, 2))->toBe($deflated);

    $inflator = inflate_init(ZLIB_ENCODING_RAW, [
        'level' => -1,
        'memory' => 8,
        'window' => 15,
        'strategy' => ZLIB_DEFAULT_STRATEGY,
    ]);
    $recovered = inflate_add($inflator, $deflated . "\x00\x00\xff\xff");
    expect($recovered)->toBe($plaintext);
});

it('encode uses BINARY_TYPE_ARRAYBUFFER_DEFLATE for binary deflate', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = true;
    $connection->websocketType = Websocket::BINARY_TYPE_ARRAYBUFFER_DEFLATE;

    $plaintext = "bin\x00";
    $out = Websocket::encode($plaintext, $connection);

    $deflated = wsTestRawDeflatePayload($plaintext);
    expect($out[0])->toBe(Websocket::BINARY_TYPE_ARRAYBUFFER_DEFLATE)
        ->and(substr($out, 2))->toBe($deflated);
});

it('encode uses 126 extended length when payload is 126–65535 bytes', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = true;
    $connection->websocketType = Websocket::BINARY_TYPE_BLOB;

    $plaintext = str_repeat('m', 130);
    $out = Websocket::encode($plaintext, $connection);

    expect(ord($out[1]))->toBe(126)
        ->and(substr($out, 4))->toBe($plaintext);
});

it('encode uses 127 extended length when payload exceeds 65535 bytes', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = true;
    $connection->websocketType = Websocket::BINARY_TYPE_BLOB;

    $plaintext = str_repeat('P', 65536);
    $out = Websocket::encode($plaintext, $connection);

    expect(ord($out[1]))->toBe(127)
        ->and(strlen($out))->toBe(1 + 1 + 8 + strlen($plaintext))
        ->and(substr($out, 10))->toBe($plaintext);
});

it('decode triggers close when inflate result exceeds maxPackageSize', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->maxPackageSize = 512;
    $connection->shouldReceive('close')->once();

    $plaintext = str_repeat('Z', 4000);
    $compressed = wsTestRawDeflatePayload($plaintext);
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB_DEFLATE, $compressed);

    // inflate() returns false after close(); decode() is declared : string so PHP emits TypeError on return.
    expect(fn () => Websocket::decode($frame, $connection))->toThrow(\TypeError::class);
});

it('input returns 0 when buffer is shorter than masked frame header or payload', function () {
    $connection = wsTestMockWebSocketConnectionForInput();
    $full = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, str_repeat('x', 200));

    expect(Websocket::input(substr($full, 0, 7), $connection))->toBe(0);
});

it('input closes connection when masked frame total length exceeds maxPackageSize', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->maxPackageSize = 20;
    $connection->shouldReceive('close')->once();

    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, str_repeat('y', 30));

    expect(Websocket::input($frame, $connection))->toBe(0);
});

it('encode serializes non-scalar payload as JSON', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = true;
    $connection->websocketType = Websocket::BINARY_TYPE_BLOB;

    $payload = ['a' => 1, 'b' => 'x'];
    $out = Websocket::encode($payload, $connection);

    expect(substr($out, 2))->toBe(json_encode($payload, JSON_UNESCAPED_UNICODE));
});

it('input returns full masked frame length for FIN text after handshake', function () {
    $connection = wsTestMockWebSocketConnectionForInput();
    $payload = 'ok';
    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, $payload);

    expect(Websocket::input($frame, $connection))->toBe(strlen($frame));
});

it('input closes connection when client frame is not masked', function () {
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->shouldReceive('close')->once();

    $frame = "\x81\x04" . '1234';

    expect(Websocket::input($frame, $connection))->toBe(0);
});

it('encode buffers to tmpWebsocketData when handshake not complete', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketHandshake = false;
    $connection->context->tmpWebsocketData = '';
    $connection->websocketType = Websocket::BINARY_TYPE_BLOB;

    $out = Websocket::encode('hold', $connection);

    expect($out)->toBe('')
        ->and($connection->context->tmpWebsocketData)->not->toBe('');
});
