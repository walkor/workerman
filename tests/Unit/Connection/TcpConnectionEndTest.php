<?php

use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Timer;

it('closes connection after lingerTimeout via Timer::delay when calling end()', function () {
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
    $connection->lingerTimeout = 0.01;

    $connection->end();

    $event->delay(0.03, static fn () => $event->stop());
    $event->run();

    expect($connection->getStatus())->toBe(TcpConnection::STATUS_CLOSED);

    fclose($client);
    fclose($server);
});

