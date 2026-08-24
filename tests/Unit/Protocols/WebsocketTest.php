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

/**
 * A raw deflate stream whose first block claims the reserved BTYPE 0b11, so zlib always rejects it.
 * Random bytes are not usable here: they decode successfully often enough to make a test flaky.
 */
function wsTestInvalidDeflatePayload(): string
{
    return "\x07" . str_repeat("\x00", 63);
}

function wsTestMask(string $data, string $maskKey): string
{
    $len = strlen($data);
    $masks = str_repeat($maskKey, (int) floor($len / 4)) . substr($maskKey, 0, $len % 4);

    return $data ^ $masks;
}

/**
 * Client �� server frame (masked), matching Websocket::decode expectations.
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
    Websocket::initContext($c);
    $c->maxPackageSize = 1024 * 1024;

    return $c;
}

function wsTestHandshakeRequest(): string
{
    return "GET / HTTP/1.1\r\n"
        . "Host: example.com\r\n"
        . "Upgrade: websocket\r\n"
        . "Connection: Upgrade\r\n"
        . "Sec-WebSocket-Key: dGhlIHNhbXBsZSBub25jZQ==\r\n"
        . "Sec-WebSocket-Version: 13\r\n\r\n";
}

/**
 * @return TcpConnection&\Mockery\MockInterface
 */
function wsTestMockWebSocketConnectionForInput(): TcpConnection
{
    $c = wsTestMockWebSocketConnection();
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
    $plaintext = 'Compressed payload with entropy ��ã�����';
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

it('encode uses 126 extended length when payload is 126�C65535 bytes', function () {
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

    expect(Websocket::decode($frame, $connection))->toBe('');
});

it('stops inflating as soon as the output passes maxPackageSize', function () {
    if (!function_exists('proc_open')) {
        $this->markTestSkipped('proc_open is required for the memory-limit regression test');
    }

    // Runs under a 16MB memory_limit against 24MB of inflated output, so the test only passes if
    // inflate() gives up partway instead of building the whole string first.

    $compressed = wsTestRawDeflatePayload(str_repeat('Z', 24 * 1024 * 1024));
    $autoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
    $script = <<<'PHP'
require $argv[1];

\Workerman\Worker::$outputStream = fopen('php://memory', 'w+');

final class WebsocketInflateLimitConnection extends \Workerman\Connection\TcpConnection
{
    public bool $wasClosed = false;

    public function __construct()
    {
        $this->context = new \stdClass();
        $this->context->websocketDataBuffer = '';
        $this->maxPackageSize = 512;
    }

    public function close(mixed $data = null, bool $raw = false): void
    {
        $this->wasClosed = true;
    }
}

final class WebsocketInflateLimitProtocol extends \Workerman\Protocols\Websocket
{
    public static function inflatePayload(\Workerman\Connection\TcpConnection $connection, string $payload): string
    {
        return parent::inflate($connection, $payload);
    }
}

$connection = new WebsocketInflateLimitConnection();
$result = WebsocketInflateLimitProtocol::inflatePayload($connection, stream_get_contents(STDIN));
exit($result === '' && $connection->wasClosed ? 0 : 1);
PHP;

    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=16M', '-r', $script, $autoload],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes
    );
    expect($process)->toBeResource();

    fwrite($pipes[0], $compressed);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    expect($exitCode)->toBe(0, trim($stdout . "\n" . $stderr));
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

it('initContext resets every field the frame parser reads', function () {
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketDataBuffer = 'leftover';
    $connection->context->websocketCurrentFrameLength = 42;
    $connection->context->websocketCompressed = true;
    $connection->context->websocketPermessageDeflate = true;
    $connection->context->websocketFragmented = true;

    Websocket::initContext($connection);

    expect((array)$connection->context)->toBe([
        'websocketDataBuffer' => '',
        'websocketCurrentFrameLength' => 0,
        'websocketCompressed' => false,
        'websocketPermessageDeflate' => false,
        'websocketFragmented' => false,
    ]);
});

it('decode closes the connection when deflate data cannot be inflated, without surfacing a php warning', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->context->websocketPermessageDeflate = true;
    $connection->shouldReceive('close')->once();

    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB_DEFLATE, wsTestInvalidDeflatePayload());

    // inflate_add() warns on undecodable input, and the protocol handles that outcome itself.
    $reported = [];
    set_error_handler(static function (int $code, string $message) use (&$reported): bool {
        if (error_reporting() & $code) {
            $reported[] = $message;
        }
        return true;
    });
    try {
        $result = Websocket::decode($frame, $connection);
    } finally {
        restore_error_handler();
    }

    expect($result)->toBe('')
        ->and($reported)->toBe([]);
});

it('input rejects rsv1 frames when permessage-deflate was not negotiated', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->shouldReceive('close')->once();

    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB_DEFLATE, wsTestRawDeflatePayload('hi'));

    expect(Websocket::input($frame, $connection))->toBe(0);
});

it('input accepts rsv1 frames once permessage-deflate was negotiated', function () {
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->context->websocketPermessageDeflate = true;

    $frame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB_DEFLATE, wsTestRawDeflatePayload('hi'));

    expect(Websocket::input($frame, $connection))->toBe(strlen($frame));
});

it('input rejects reserved rsv2 and rsv3 bits even with permessage-deflate negotiated', function (int $firstByte) {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->context->websocketPermessageDeflate = true;
    $connection->shouldReceive('close')->once();

    expect(Websocket::input(wsTestBuildMaskedFrame(chr($firstByte), 'x'), $connection))->toBe(0);
})->with([
    'rsv2' => [0xa1],
    'rsv3' => [0x91],
    'rsv1+rsv2' => [0xe1],
]);

it('input rejects rsv1 on a control frame', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->context->websocketPermessageDeflate = true;
    $connection->shouldReceive('close')->once();

    // fin + rsv1 + opcode 0x9 (ping)
    expect(Websocket::input(wsTestBuildMaskedFrame(chr(0xc9), 'hb'), $connection))->toBe(0);
});

it('input rejects control frames that break RFC 6455 section 5.5', function (int $firstByte, string $payload) {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->shouldReceive('close')->once();
    $connection->shouldReceive('consumeRecvBuffer');

    // An invalid control frame is rejected outright, never handled as ping/pong/close.
    $handled = false;
    $record = function () use (&$handled): void {
        $handled = true;
    };
    $connection->onWebSocketPing = $record;
    $connection->onWebSocketClose = $record;

    expect(Websocket::input(wsTestBuildMaskedFrame(chr($firstByte), $payload), $connection))->toBe(0)
        ->and($handled)->toBeFalse();
})->with([
    'ping payload over 125 bytes' => [0x89, str_repeat('p', 200)],
    'close payload over 125 bytes' => [0x88, str_repeat('c', 200)],
    'fragmented ping' => [0x09, 'hb'],
    'fragmented close' => [0x08, 'bye'],
]);

it('input rejects a continuation frame when no message is in progress', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->shouldReceive('close')->once();

    // fin + opcode 0x0
    expect(Websocket::input(wsTestBuildMaskedFrame(chr(0x80), 'orphan'), $connection))->toBe(0);
});

it('input rejects a data frame that interrupts a fragmented message', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->context->websocketFragmented = true;
    $connection->shouldReceive('close')->once();

    expect(Websocket::input(wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, 'BBB'), $connection))->toBe(0);
});

it('input marks the connection as fragmented only while a message is incomplete', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->shouldReceive('consumeRecvBuffer');

    // First fragment: fin=0, opcode 0x1.
    Websocket::input(wsTestBuildMaskedFrame(chr(0x01), 'AAA'), $connection);
    expect($connection->context->websocketFragmented)->toBeTrue();

    // Final fragment: fin=1, opcode 0x0.
    Websocket::input(wsTestBuildMaskedFrame(chr(0x80), 'BBB'), $connection);
    expect($connection->context->websocketFragmented)->toBeFalse();
});

it('assembles a fragmented text message across continuation frames', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->shouldReceive('consumeRecvBuffer');

    $first = wsTestBuildMaskedFrame(chr(0x01), 'Hello, ');
    $middle = wsTestBuildMaskedFrame(chr(0x00), 'fragmented ');
    $last = wsTestBuildMaskedFrame(chr(0x80), 'world!');

    // input() buffers the non-final fragments itself and reports the length of the final one,
    // which TcpConnection::baseRead() then hands to decode().
    expect(Websocket::input($first, $connection))->toBe(0)
        ->and(Websocket::input($middle, $connection))->toBe(0)
        ->and(Websocket::input($last, $connection))->toBe(strlen($last))
        ->and(Websocket::decode($last, $connection))->toBe('Hello, fragmented world!');
});

it('inflates a fragmented compressed message once, after the final fragment', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->context->websocketPermessageDeflate = true;
    $connection->shouldReceive('consumeRecvBuffer');

    $plaintext = str_repeat('fragment-and-deflate;', 64);
    $compressed = wsTestRawDeflatePayload($plaintext);
    $split = intdiv(strlen($compressed), 2);

    // RFC 7692 section 6: rsv1 is set on the first frame of the message only.
    $first = wsTestBuildMaskedFrame(chr(0x41), substr($compressed, 0, $split));
    $last = wsTestBuildMaskedFrame(chr(0x80), substr($compressed, $split));

    expect(Websocket::input($first, $connection))->toBe(0)
        ->and(Websocket::input($last, $connection))->toBe(strlen($last))
        ->and(Websocket::decode($last, $connection))->toBe($plaintext);
});

it('does not carry the compressed flag over to the next message', function () {
    $connection = wsTestMockWebSocketConnectionForInput();
    $connection->context->websocketPermessageDeflate = true;

    $compressedFrame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB_DEFLATE, wsTestRawDeflatePayload('compressed'));
    Websocket::input($compressedFrame, $connection);
    expect(Websocket::decode($compressedFrame, $connection))->toBe('compressed');

    $plainFrame = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, 'plain');
    Websocket::input($plainFrame, $connection);
    expect(Websocket::decode($plainFrame, $connection))->toBe('plain');
});

it('input consumes the close frame and reports it once', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnectionForInput();
    $frame = wsTestBuildMaskedFrame(chr(0x88), "\x03\xe8");

    $calls = 0;
    $connection->onWebSocketClose = function () use (&$calls): void {
        $calls++;
    };
    $connection->shouldReceive('consumeRecvBuffer')->once()->with(strlen($frame));

    expect(Websocket::input($frame, $connection))->toBe(0)
        ->and($calls)->toBe(1);
});

it('input waits for the full close frame before consuming it', function () {
    $connection = wsTestMockWebSocketConnectionForInput();
    $frame = wsTestBuildMaskedFrame(chr(0x88), "\x03\xe8");

    // No consumeRecvBuffer expectation: a partial frame must not be consumed.
    expect(Websocket::input(substr($frame, 0, strlen($frame) - 1), $connection))->toBe(0);
});

it('dealHandshake rejects unterminated headers past MAX_HANDSHAKE_LENGTH', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->shouldReceive('close')
        ->once()
        ->with(Mockery::pattern('/^HTTP\/1\.1 431 /'), true);

    $buffer = "GET / HTTP/1.1\r\nX-Pad: " . str_repeat('A', 16384);

    expect(Websocket::dealHandshake($buffer, $connection))->toBe(0);
});

it('dealHandshake keeps buffering while headers are still under the limit', function () {
    $connection = wsTestMockWebSocketConnection();

    // No close expectation: this is a legitimate partial read.
    expect(Websocket::dealHandshake("GET / HTTP/1.1\r\nHost: t\r\n", $connection))->toBe(0);
});

it('dealHandshake rejects a terminated header block past MAX_HANDSHAKE_LENGTH', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->shouldReceive('close')
        ->once()
        ->with(Mockery::pattern('/^HTTP\/1\.1 431 /'), true);

    $buffer = "GET / HTTP/1.1\r\nX-Pad: " . str_repeat('A', 16384) . "\r\n\r\n";

    expect(Websocket::dealHandshake($buffer, $connection))->toBe(0);
});

it('dealHandshake records permessage-deflate when the app accepts the extension', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->headers = ['Sec-WebSocket-Extensions: permessage-deflate'];
    $connection->shouldReceive('consumeRecvBuffer')->once();
    $connection->shouldReceive('send')->once();

    Websocket::dealHandshake(wsTestHandshakeRequest(), $connection);

    expect($connection->context->websocketPermessageDeflate)->toBeTrue();
});

it('dealHandshake leaves permessage-deflate off when the app does not accept it', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->shouldReceive('consumeRecvBuffer')->once();
    $connection->shouldReceive('send')->once();

    Websocket::dealHandshake(wsTestHandshakeRequest(), $connection);

    expect($connection->context->websocketPermessageDeflate)->toBeFalse();
});

it('dealHandshake builds the request from the header block only', function () {
    /** @var TcpConnection&\Mockery\MockInterface $connection */
    $connection = wsTestMockWebSocketConnection();
    $connection->shouldReceive('consumeRecvBuffer')->once();
    $connection->shouldReceive('send')->once();

    $handshake = wsTestHandshakeRequest();
    // A frame pipelined behind the handshake must not become part of the Request.
    $trailing = wsTestBuildMaskedFrame(Websocket::BINARY_TYPE_BLOB, "Sec-WebSocket-Key: trailing\r\n");

    $body = null;
    $connection->onWebSocketConnect = function ($c, $request) use (&$body): void {
        $body = $request->rawBody();
    };

    Websocket::dealHandshake($handshake . $trailing, $connection);

    expect($body)->toBe('');
});
