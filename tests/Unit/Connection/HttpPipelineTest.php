<?php

use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Timer;

it('fires onMessage once per request when two HTTP/1.1 requests arrive in one TCP read', function () {
    $event = new Select();
    Timer::init($event);

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $serverName = stream_socket_get_name($server, false);
    expect($serverName)->not->toBeFalse();

    $client = stream_socket_client('tcp://' . $serverName, $errno, $errstr, 1);
    expect($client)->not->toBeFalse();

    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $remoteAddress = (string)stream_socket_get_name($accepted, true);
    $connection = new TcpConnection($event, $accepted, $remoteAddress);
    $connection->protocol = Http::class;

    $paths = [];
    $connection->onMessage = function (TcpConnection $c, Request $req) use (&$paths): void {
        $paths[] = $req->path();
    };

    $payload = "GET /first HTTP/1.1\r\nHost: t\r\n\r\n"
        . "GET /second HTTP/1.1\r\nHost: t\r\n\r\n";
    fwrite($client, $payload);

    $event->delay(0.05, static fn () => $event->stop());
    $event->run();

    expect($paths)->toBe(['/first', '/second']);

    fclose($client);
    fclose($server);
});

it('fires onMessage for POST with body then pipelined GET in one read', function () {
    $event = new Select();
    Timer::init($event);

    $server = stream_socket_server('tcp://127.0.0.1:0', $errno, $errstr);
    expect($server)->not->toBeFalse();

    $serverName = stream_socket_get_name($server, false);
    $client = stream_socket_client('tcp://' . $serverName, $errno, $errstr, 1);
    $accepted = stream_socket_accept($server, 1);
    expect($accepted)->not->toBeFalse();

    $connection = new TcpConnection($event, $accepted, (string)stream_socket_get_name($accepted, true));
    $connection->protocol = Http::class;

    $meta = [];
    $connection->onMessage = function (TcpConnection $c, Request $req) use (&$meta): void {
        $meta[] = [$req->method(), $req->path(), $req->rawBody()];
    };

    $post = "POST /save HTTP/1.1\r\nHost: t\r\nContent-Length: 3\r\n\r\nabc";
    $get = "GET /after HTTP/1.1\r\nHost: t\r\n\r\n";
    fwrite($client, $post . $get);

    $event->delay(0.05, static fn () => $event->stop());
    $event->run();

    expect($meta)->toBe([
        ['POST', '/save', 'abc'],
        ['GET', '/after', ''],
    ]);

    fclose($client);
    fclose($server);
});
