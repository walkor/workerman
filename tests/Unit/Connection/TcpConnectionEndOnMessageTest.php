<?php

use Workerman\Connection\TcpConnection;
use Workerman\Events\Select;
use Workerman\Protocols\Text;
use Workerman\Timer;

it('disables onMessage after end() is called inside onMessage (pipeline case)', function () {
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
    $connection->protocol = Text::class;
    $connection->lingerTimeout = 0.01;

    $calls = 0;
    $connection->onMessage = function (TcpConnection $c, string $msg) use (&$calls): void {
        $calls++;
        if ($msg === 'a') {
            // Simulate app deciding to end() while there is another pipelined message in buffer.
            $c->end();
        }
    };

    fwrite($client, "a\nb\n");

    $event->delay(0.03, static fn () => $event->stop());
    $event->run();

    expect($calls)->toBe(1);

    fclose($client);
    fclose($server);
});

