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