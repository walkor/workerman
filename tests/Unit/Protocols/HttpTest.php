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
    testWithConnectionEnd(function (TcpConnection $tcpConnection) {
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
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
        expect(Http::input(str_replace('POST', 'MIAOWU', $buffer), $tcpConnection))
            ->toBe(0);
    }, '400 Bad Request');

    //content-length exceeds connection max package size
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
        $tcpConnection->maxPackageSize = 10;
        expect(Http::input($buffer, $tcpConnection))
            ->toBe(0);
    }, '413 Payload Too Large');
});

it('missing Host header causes 400 Bad Request for HTTP/1.1', function () {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    expect(Http::input("GET / HTTP/1.1\r\n\r\n", $tcpConnection))->toBe(0);
    $tcpConnection->shouldHaveReceived('end', function ($actual) {
        return str_starts_with($actual, '400 Bad Request') && str_contains($actual, "Connection: close\r\n");
    });
});

it('sends 413 with Connection: close when header end is missing and buffered length reaches at least 16384 bytes', function (int $incompleteLength) {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    Http::input(str_repeat('a', $incompleteLength), $tcpConnection);
    $tcpConnection->shouldHaveReceived('end', function ($actual) {
        return str_contains($actual, '413 Payload Too Large') && str_contains($actual, "Connection: close\r\n");
    });
})->with([
    'exactly 16384' => [16384],
    'strictly greater than 16384' => [16385],
]);

it('does not send 413 for completed headers whose total size before body exceeds 16384 bytes', function () {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    $tcpConnection->maxPackageSize = 2 * 1024 * 1024;
    $prefix = "GET / HTTP/1.1\r\nX: ";
    $suffix = "\r\n\r\n";
    $padding = str_repeat('x', 16400 - strlen($prefix) - strlen($suffix));
    $buffer = $prefix . $padding . $suffix;
    expect(strlen($buffer))->toBeGreaterThan(16384);
    expect(Http::input($buffer, $tcpConnection))->toBe(strlen($buffer));
    $tcpConnection->shouldNotHaveReceived('end');
});

it('accepts ::input for POST with 500-character request-target and small body (forum long-path noise)', function () {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    $tcpConnection->maxPackageSize = 1024 * 1024;
    $path = '/' . str_repeat('A', 500);
    $buffer = "POST {$path} HTTP/1.1\r\n"
        . "Host: localhost:8080\r\n"
        . "User-Agent: Mozilla/5.0\r\n"
        . "Content-Type: text/plain\r\n"
        . "Content-Length: 5\r\n"
        . "\r\n"
        . 'hello';
    expect(Http::input($buffer, $tcpConnection))->toBe(strlen($buffer));
    $tcpConnection->shouldNotHaveReceived('end');
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
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
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
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
        expect(Http::input($buffer, $tcpConnection))->toBe(0);
    }, $expectedCloseContains);
})->with([
    'Transfer-Encoding with Content-Length is forbidden (request smuggling)' => [
        "POST / HTTP/1.1\r\nContent-Length: 5\r\nTransfer-Encoding: chunked\r\n\r\nhello",
        '400 Bad Request',
    ],
    'Transfer-Encoding with non-chunked value is forbidden' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: gzip\r\n\r\n",
        '400 Bad Request',
    ],
    'Duplicate Transfer-Encoding headers are forbidden' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
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

it('tests ::input for chunked transfer-encoding', function (string $buffer, int $expectedLength) {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    $tcpConnection->maxPackageSize = 1024 * 1024;
    expect(Http::input($buffer, $tcpConnection))->toBe($expectedLength);
})->with([
    'simple chunked body' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\n\r\n"),
    ],
    'multiple chunks' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n"),
    ],
    'chunked with trailers' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\nChecksum: abc\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhello\r\n0\r\nChecksum: abc\r\n\r\n"),
    ],
    'chunked with chunk extension' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=val\r\nhello\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5;ext=val\r\nhello\r\n0\r\n\r\n"),
    ],
    'incomplete chunked body returns 0' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n5\r\nhel",
        0,
    ],
    'chunked header only returns 0' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n",
        0,
    ],
    'case-insensitive transfer-encoding' => [
        "POST / HTTP/1.1\r\ntransfer-encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\ntransfer-encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n"),
    ],
]);

it('tests ::input for chunked boundary cases', function (string $buffer, int $expectedLength) {
    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::spy(TcpConnection::class);
    $tcpConnection->maxPackageSize = 1024 * 1024;
    expect(Http::input($buffer, $tcpConnection))->toBe($expectedLength);
})->with([
    'empty body (only zero chunk)' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n"),
    ],
    'leading zeros in chunk-size' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n05\r\nhello\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n05\r\nhello\r\n0\r\n\r\n"),
    ],
    'HTTP/1.0 with chunked' => [
        "POST / HTTP/1.0\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.0\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\r\n0\r\n\r\n"),
    ],
    'uppercase hex chunk-size' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\nA\r\n" . str_repeat('x', 10) . "\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\nA\r\n" . str_repeat('x', 10) . "\r\n0\r\n\r\n"),
    ],
    'chunk extension with multiple params' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n4;a=1;b=2\r\nwiki\r\n0\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n4;a=1;b=2\r\nwiki\r\n0\r\n\r\n"),
    ],
    'multiple trailers then terminating empty line' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n1\r\nx\r\n0\r\nX-One: a\r\nX-Two: b\r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n1\r\nx\r\n0\r\nX-One: a\r\nX-Two: b\r\n\r\n"),
    ],
    'trailer field-value with leading OWS' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\nChecksum:   abc   \r\n\r\n",
        strlen("POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\nChecksum:   abc   \r\n\r\n"),
    ],
    'incomplete after zero chunk (no blank line after trailers)' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\nChecksum: abc\r\n",
        0,
    ],
    'incomplete mid-trailer block' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\nChecksum:",
        0,
    ],
]);

it('rejects malformed chunked framing in ::input', function (string $buffer) {
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
        expect(Http::input($buffer, $tcpConnection))->toBe(0);
    }, '400 Bad Request');
})->with([
    'invalid hex in chunk-size' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\nzz\r\n\r\n",
    ],
    'chunk-size longer than 16 hex digits' => [
        'POST / HTTP/1.1' . "\r\nTransfer-Encoding: chunked\r\n\r\n" . str_repeat('1', 17) . "\r\n\r\n",
    ],
    'chunk-size overflows int (hexdec float)' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n8000000000000000\r\n\r\n",
    ],
    'missing CRLF after chunk data' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabc\n0\r\n\r\n",
    ],
    'wrong bytes where CRLF must follow chunk data' => [
        "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n3\r\nabcXX0\r\n\r\n",
    ],
]);

it('rejects chunked message over maxPackageSize in ::input', function () {
    $buffer = "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n";
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
        $tcpConnection->maxPackageSize = strlen($buffer) - 1;
        expect(Http::input($buffer, $tcpConnection))->toBe(0);
    }, '413 Payload Too Large');
});

it('rejects chunked body that exceeds maxPackageSize in ::input', function () {
    $body = str_repeat('a', 100);
    $hex = dechex(strlen($body));
    $buffer = "POST / HTTP/1.1\r\nTransfer-Encoding: chunked\r\n\r\n{$hex}\r\n{$body}\r\n0\r\n\r\n";
    testWithConnectionEnd(function (TcpConnection $tcpConnection) use ($buffer) {
        $tcpConnection->maxPackageSize = strlen($buffer) - 1;
        expect(Http::input($buffer, $tcpConnection))->toBe(0);
    }, '413 Payload Too Large');
});

it('tests ::decode for chunked transfer-encoding', function () {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);
    $tcpConnection->context = new stdClass();
    $tcpConnection->context->chunked = true;

    $buffer = "POST /api HTTP/1.1\r\nHost: example.com\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "5\r\nhello\r\n6\r\n world\r\n0\r\n\r\n";

    $request = Http::decode($buffer, $tcpConnection);

    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->rawBody())->toBe('hello world')
        ->and($request->header('content-length'))->toBe('11')
        ->and($request->header('transfer-encoding'))->toBeNull()
        ->and($request->trailer())->toBe([]);
});

it('tests ::decode for chunked with trailers', function () {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);
    $tcpConnection->context = new stdClass();
    $tcpConnection->context->chunked = true;

    $buffer = "POST /api HTTP/1.1\r\nHost: example.com\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "5\r\nhello\r\n0\r\nChecksum: abc123\r\nExpires: Wed\r\n\r\n";

    $request = Http::decode($buffer, $tcpConnection);

    expect($request)->toBeInstanceOf(Request::class)
        ->and($request->rawBody())->toBe('hello')
        ->and($request->trailer('checksum'))->toBe('abc123')
        ->and($request->trailer('expires'))->toBe('Wed')
        ->and($request->trailer('nonexistent', 'default'))->toBe('default')
        ->and($request->trailer())->toBe(['checksum' => 'abc123', 'expires' => 'Wed']);
});

it('tests ::decode for chunked with JSON post body', function () {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);
    $tcpConnection->context = new stdClass();
    $tcpConnection->context->chunked = true;

    $json = '{"key":"value"}';
    $hex = dechex(strlen($json));
    $buffer = "POST /api HTTP/1.1\r\nHost: example.com\r\nContent-Type: application/json\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "{$hex}\r\n{$json}\r\n0\r\n\r\n";

    $request = Http::decode($buffer, $tcpConnection);

    expect($request->post('key'))->toBe('value')
        ->and($request->header('transfer-encoding'))->toBeNull();
});

it('tests ::decode for chunked edge cases', function (string $buffer, array $expect) {
    /** @var TcpConnection $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class);
    $tcpConnection->context = new stdClass();
    $tcpConnection->context->chunked = true;

    $request = Http::decode($buffer, $tcpConnection);

    expect($request->rawBody())->toBe($expect['body'])
        ->and($request->trailer())->toBe($expect['trailers']);
})->with([
    'empty body (zero chunk only)' => [
        "POST /z HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n\r\n",
        ['body' => '', 'trailers' => []],
    ],
    'leading zero chunk-size' => [
        "POST /z HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n03\r\nabc\r\n0\r\n\r\n",
        ['body' => 'abc', 'trailers' => []],
    ],
    'trailer names lowercased; field-value ltrim only' => [
        "POST /z HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n0\r\n"
            . "X-Checksum:   sig   \r\nAnother-Trailer:\t v \r\n\r\n",
        ['body' => '', 'trailers' => ['x-checksum' => 'sig   ', 'another-trailer' => 'v ']],
    ],
    'multiple data chunks then trailers' => [
        "POST /z HTTP/1.1\r\nHost: a\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "2\r\nab\r\n2\r\ncd\r\n0\r\nEtag: \"x\"\r\n\r\n",
        ['body' => 'abcd', 'trailers' => ['etag' => '"x"']],
    ],
]);

it('tests ::input then ::decode for chunked with trailers', function () {
    $buffer = "POST /r HTTP/1.1\r\nHost: t\r\nTransfer-Encoding: chunked\r\n\r\n"
        . "4\r\nfour\r\n0\r\nX-T: one\r\n\r\n";

    /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
    $tcpConnection = Mockery::mock(TcpConnection::class)->makePartial();
    $tcpConnection->context = new stdClass();
    $tcpConnection->maxPackageSize = 1024 * 1024;

    expect(Http::input($buffer, $tcpConnection))->toBe(strlen($buffer));
    expect(isset($tcpConnection->context->chunked))->toBeTrue();

    $request = Http::decode($buffer, $tcpConnection);

    expect($request->rawBody())->toBe('four')
        ->and($request->trailer('x-t'))->toBe('one')
        ->and(isset($tcpConnection->context->chunked))->toBeFalse();
});

describe('Request chunked trailers (trailer / setChunkTrailers)', function () {
    it('Request::trailer() returns all trailers and case-insensitive names after Http::decode', function () {
        /** @var TcpConnection $tcpConnection */
        $tcpConnection = Mockery::mock(TcpConnection::class);
        $tcpConnection->context = new stdClass();
        $tcpConnection->context->chunked = true;

        $buffer = "POST / HTTP/1.1\r\nHost: x\r\nTransfer-Encoding: chunked\r\n\r\n"
            . "0\r\n"
            . "Alpha: one\r\n"
            . "Beta: two\r\n"
            . "\r\n";

        $request = Http::decode($buffer, $tcpConnection);

        expect($request->trailer())->toBe(['alpha' => 'one', 'beta' => 'two'])
            ->and($request->trailer('ALPHA'))->toBe('one')
            ->and($request->trailer('beta'))->toBe('two')
            ->and($request->trailer('missing', 'default'))->toBe('default');
    });

    it('Request::trailer() is empty when the request was not chunked-decoded', function () {
        /** @var TcpConnection $tcpConnection */
        $tcpConnection = Mockery::mock(TcpConnection::class);
        $tcpConnection->context = new stdClass();

        $request = Http::decode("GET / HTTP/1.1\r\n\r\n", $tcpConnection);

        expect($request->trailer())->toBe([])
            ->and($request->trailer('any', 'fallback'))->toBe('fallback');
    });

    it('Request::setChunkTrailers() only applies the first call; trailer() stays unchanged', function () {
        $request = new Request("GET / HTTP/1.1\r\n\r\n");
        $request->setChunkTrailers(['checksum' => 'abc']);
        expect($request->trailer('checksum'))->toBe('abc')
            ->and($request->trailer())->toBe(['checksum' => 'abc']);

        $request->setChunkTrailers(['other' => 'nope']);
        expect($request->trailer('checksum'))->toBe('abc')
            ->and($request->trailer('other'))->toBeNull()
            ->and($request->trailer())->toBe(['checksum' => 'abc']);
    });
});

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

describe('HTTP/1.1 pipelining (Http::input)', function () {
    it('returns only the first message length when two GET requests are concatenated', function () {
        /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
        $tcpConnection = Mockery::spy(TcpConnection::class);
        $tcpConnection->maxPackageSize = 1024 * 1024;
        $buffer = "GET / HTTP/1.1\r\n\r\nGET /b HTTP/1.1\r\n\r\n";
        expect(Http::input($buffer, $tcpConnection))->toBe(18);
        $tcpConnection->shouldNotHaveReceived('end');
    });

    it('returns the second message length when the buffer starts at the second request', function () {
        /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
        $tcpConnection = Mockery::spy(TcpConnection::class);
        $tcpConnection->maxPackageSize = 1024 * 1024;
        $second = "GET /b HTTP/1.1\r\n\r\n";
        $buffer = "GET / HTTP/1.1\r\n\r\n" . $second;
        expect(Http::input(substr($buffer, 18), $tcpConnection))->toBe(strlen($second));
        $tcpConnection->shouldNotHaveReceived('end');
    });

    it('handles POST with body followed by GET', function () {
        /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
        $tcpConnection = Mockery::spy(TcpConnection::class);
        $tcpConnection->maxPackageSize = 1024 * 1024;
        $first = "POST /a HTTP/1.1\r\nHost: x\r\nContent-Length: 4\r\n\r\nbody";
        $second = "GET /b HTTP/1.1\r\nHost: x\r\n\r\n";
        $buffer = $first . $second;
        expect(Http::input($buffer, $tcpConnection))->toBe(strlen($first));
        expect(Http::input(substr($buffer, strlen($first)), $tcpConnection))->toBe(strlen($second));
        $tcpConnection->shouldNotHaveReceived('end');
    });

    it('returns 0 when the first request line is incomplete', function () {
        /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
        $tcpConnection = Mockery::spy(TcpConnection::class);
        $tcpConnection->maxPackageSize = 1024 * 1024;
        expect(Http::input("GET / HTTP/1.1\r\n", $tcpConnection))->toBe(0);
        $tcpConnection->shouldNotHaveReceived('end');
    });

    it('returns 0 when a pipelined second request has incomplete headers', function () {
        /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
        $tcpConnection = Mockery::spy(TcpConnection::class);
        $tcpConnection->maxPackageSize = 1024 * 1024;
        $buffer = "GET / HTTP/1.1\r\n\r\nGET /b HTTP/1.1\r\n";
        expect(Http::input($buffer, $tcpConnection))->toBe(18);
        $rest = substr($buffer, 18);
        expect(Http::input($rest, $tcpConnection))->toBe(0);
        $tcpConnection->shouldNotHaveReceived('end');
    });

    it('parses three pipelined GETs in sequence', function () {
        /** @var TcpConnection&\Mockery\MockInterface $tcpConnection */
        $tcpConnection = Mockery::spy(TcpConnection::class);
        $tcpConnection->maxPackageSize = 1024 * 1024;
        $a = "GET /a HTTP/1.1\r\nHost: h\r\n\r\n";
        $b = "GET /b HTTP/1.1\r\nHost: h\r\n\r\n";
        $c = "GET /c HTTP/1.1\r\nHost: h\r\n\r\n";
        $buffer = $a . $b . $c;
        $pos = 0;
        foreach ([$a, $b, $c] as $part) {
            $len = strlen($part);
            expect(Http::input(substr($buffer, $pos), $tcpConnection))->toBe($len);
            $pos += $len;
        }
        $tcpConnection->shouldNotHaveReceived('end');
    });
});