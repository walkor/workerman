<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

it('customizes request class', function () {
    //backup old request class
    $oldRequestClass = Http::requestClass();

    //actual test
    $class = new class {
    };
    Http::requestClass($class::class);
    expect(Http::requestClass())->toBe($class::class);

    //restore old request class
    Http::requestClass($oldRequestClass);
});

it('tests ::input', function () {
    //test 413 payload too large
    testWithConnectionClose(function (TcpConnection $tcpConnection) {
        expect(Http::input(str_repeat('jhdxr', 3333), $tcpConnection))
            ->toBe(0);
    }, '413 Payload Too Large');

    //example request from ChatGPT :)
    $buffer = "POST /path/to/resource HTTP/1.1\r\n" .
        "Host: example.com\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: 27\r\n" .
        "\r\n" .
        '{"key": "value", "foo": "bar"}';

    //unrecognized method
    testWithConnectionClose(function (TcpConnection $tcpConnection) use ($buffer) {
        expect(Http::input(str_replace('POST', 'MIAOWU', $buffer), $tcpConnection))
            ->toBe(0);
    }, '400 Bad Request');

    //content-length exceeds connection max package size
    testWithConnectionClose(function (TcpConnection $tcpConnection) use ($buffer) {
        $tcpConnection->maxPackageSize = 10;
        expect(Http::input($buffer, $tcpConnection))
            ->toBe(0);
    }, '413 Payload Too Large');
});

it('tests ::input request-line and header validation matrix', function (string $buffer, int $expectedLength) {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    $tcpConnection->maxPackageSize = 1024 * 1024;
    expect(Http::input($buffer, $tcpConnection))->toBe($expectedLength);
    $tcpConnection->shouldNotHaveReceived('close');
})->with([
    'minimal GET / HTTP/1.1' => [
        "GET / HTTP/1.1\r\n\r\n",
        18, // strlen("GET / HTTP/1.1\r\n\r\n")
    ],
    'lowercase method and version is allowed' => [
        "get / http/1.1\r\n\r\n",
        18,
    ],
    'all supported methods' => [
        "PATCH /a HTTP/1.0\r\n\r\n",
        21, // PATCH(5) + space + /a(2) + space + HTTP/1.0(8) + \r\n\r\n(4) = 21
    ],
    'GET with Content-Length is allowed and affects package length' => [
        "GET / HTTP/1.1\r\nContent-Length: 5\r\n\r\nhello",
        strlen("GET / HTTP/1.1\r\nContent-Length: 5\r\n\r\n") + 5, // header length + body length
    ],
    'request-target allows UTF-8 bytes (compatibility)' => [
        "GET /中文 HTTP/1.1\r\n\r\n",
        strlen("GET /中文 HTTP/1.1\r\n\r\n"),
    ],
    'pipeline: first request length is returned' => [
        "GET / HTTP/1.1\r\n\r\nGET /b HTTP/1.1\r\n\r\n",
        18,
    ],
    'X-Transfer-Encoding does not trigger Transfer-Encoding ban' => [
        "GET / HTTP/1.1\r\nX-Transfer-Encoding: chunked\r\n\r\n",
        18 + strlen("X-Transfer-Encoding: chunked\r\n"),
    ],
]);

it('rejects invalid request-line cases in ::input', function (string $buffer) {
    testWithConnectionClose(function (TcpConnection $tcpConnection) use ($buffer) {
        expect(Http::input($buffer, $tcpConnection))->toBe(0);
    }, '400 Bad Request');
})->with([
    'unknown method similar to valid one' => [
        "POSTS / HTTP/1.1\r\n\r\n",
    ],
    'tab delimiter between method and path is not allowed' => [
        "GET\t/ HTTP/1.1\r\n\r\n",
    ],
    'leading whitespace before method is not allowed' => [
        " GET / HTTP/1.1\r\n\r\n",
    ],
    'absolute-form request-target is not supported' => [
        "GET http://example.com/ HTTP/1.1\r\n\r\n",
    ],
    'asterisk-form request-target is not supported (including OPTIONS *)' => [
        "OPTIONS * HTTP/1.1\r\n\r\n",
    ],
    'invalid http version' => [
        "GET / HTTP/2.0\r\n\r\n",
    ],
    'invalid path contains space' => [
        "GET /a b HTTP/1.1\r\n\r\n",
    ],
    'invalid path contains DEL' => [
        "GET /\x7f HTTP/1.1\r\n\r\n",
    ],
    'CRLF injection attempt in request-target' => [
        "GET /foo\r\nX: y HTTP/1.1\r\n\r\n",
    ],
]);

it('rejects Transfer-Encoding and bad/duplicate Content-Length in ::input', function (string $buffer, ?string $expectedCloseContains = '400 Bad Request') {
    testWithConnectionClose(function (TcpConnection $tcpConnection) use ($buffer) {
        expect(Http::input($buffer, $tcpConnection))->toBe(0);
    }, $expectedCloseContains);
})->with([
    'Transfer-Encoding is forbidden (case-insensitive)' => [
        "GET / HTTP/1.1\r\ntransfer-encoding: chunked\r\n\r\n",
        '400 Bad Request',
    ],
    'Content-Length must be digits (not a number)' => [
        "GET / HTTP/1.1\r\nContent-Length: abc\r\n\r\n",
        '400 Bad Request',
    ],
    'Content-Length must be digits (digits + letters)' => [
        "GET / HTTP/1.1\r\nContent-Length: 12abc\r\n\r\n",
        '400 Bad Request',
    ],
    'Content-Length must be digits (empty value)' => [
        "GET / HTTP/1.1\r\nContent-Length: \r\n\r\n",
        '400 Bad Request',
    ],
    'Content-Length must be digits (comma list)' => [
        "GET / HTTP/1.1\r\nContent-Length: 1,2\r\n\r\n",
        '400 Bad Request',
    ],
    'duplicate Content-Length (adjacent)' => [
        "GET / HTTP/1.1\r\nContent-Length: 1\r\nContent-Length: 1\r\n\r\nx",
        '400 Bad Request',
    ],
    'duplicate Content-Length (separated by other header, case-insensitive)' => [
        "GET / HTTP/1.1\r\ncontent-length: 1\r\nX: y\r\nContent-Length: 1\r\n\r\nx",
        '400 Bad Request',
    ],
    'very large numeric Content-Length should be rejected by maxPackageSize (413)' => [
        "GET / HTTP/1.1\r\nContent-Length: 999999999999999999999999999999999999\r\n\r\n",
        '413 Payload Too Large',
    ],
]);

it('tests ::encode for non-object response', function () {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);
    $tcpConnection->headers = [
        'foo' => 'bar',
        'jhdxr' => ['a', 'b'],
    ];
    $extHeader = "foo: bar\r\n" .
        "jhdxr: a\r\n" .
        "jhdxr: b\r\n";

    expect(Http::encode('xiami', $tcpConnection))
        ->toBe("HTTP/1.1 200 OK\r\n" .
            "Server: workerman\r\n" .
            "{$extHeader}Connection: keep-alive\r\n" .
            "Content-Type: text/html;charset=utf-8\r\n" .
            "Content-Length: 5\r\n\r\nxiami");
});

it('tests ::encode for ' . Response::class, function () {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);
    $tcpConnection->headers = [
        'foo' => 'bar',
        'jhdxr' => ['a', 'b'],
    ];
    $extHeader = "foo: bar\r\n" .
        "jhdxr: a\r\n" .
        "jhdxr: b\r\n";

    $response = new Response(body: 'xiami');

    expect(Http::encode($response, $tcpConnection))
        ->toBe("HTTP/1.1 200 OK\r\n" .
            "Server: workerman\r\n" .
            "{$extHeader}Connection: keep-alive\r\n" .
            "Content-Type: text/html;charset=utf-8\r\n" .
            "Content-Length: 5\r\n\r\nxiami");
});

it('tests ::decode', function () {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);

    //example request from ChatGPT :)
    $buffer = "POST /path/to/resource HTTP/1.1\r\n" .
        "Host: example.com\r\n" .
        "Content-Type: application/json\r\n" .
        "Content-Length: 27\r\n" .
        "\r\n" .
        '{"key": "value", "foo": "bar"}';

    $value = expect(Http::decode($buffer, $tcpConnection))
        ->toBeInstanceOf(Request::class)
        ->value;

    //test cache
    expect($value == Http::decode($buffer, $tcpConnection))
        ->toBeTrue();
});