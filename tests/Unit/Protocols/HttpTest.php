<?php

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;

it('customizes request class', function () {
    //backup old request class
    $oldRequestClass = Http::requestClass();

    //actual test
    $class = new class{
    };
    Http::requestClass($class::class);
    expect(Http::requestClass())->toBe($class::class);

    //restore old request class
    Http::requestClass($oldRequestClass);
});

it('tests ::encode', function () {
    $tcpConnection = Mockery::mock(TcpConnection::class);

});

it('tests ::decode', function () {

});