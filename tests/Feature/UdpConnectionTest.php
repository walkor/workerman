<?php
//example from manual
use Workerman\Connection\AsyncUdpConnection;
use Workerman\Timer;
use Workerman\Worker;

it('tests udp connection', function () {
    /** @noinspection PhpObjectFieldsAreOnlyWrittenInspection */
    $server = new Worker('udp://0.0.0.0:9292');
    $server->onMessage = function ($connection, $data) {
        expect($data)->toBe('hello');
        $connection->send('xiami');
    };
    $server->onWorkerStart = function () {
        //client
        Timer::add(1, function () {
            $client = new AsyncUdpConnection('udp://127.0.0.1:1234');
            $client->onConnect = function ($client) {
                $client->send('hello');
            };
            $client->onMessage = function ($client, $data) {
                expect($data)->toBe('xiami');
                //terminal this test
                terminate_current_test();
            };
            $client->connect();
        }, null, false);
    };
    Worker::runAll();
})->skipOnWindows() //require posix, multiple workers
->skip(message: 'this test needs to run isolated process while pest not support doing so yet');