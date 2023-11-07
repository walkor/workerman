<?php

use Symfony\Component\Process\PhpProcess;

$serverCode = file_get_contents(__DIR__ . '/Stub/WebsocketServer.php');
$clientCode = file_get_contents(__DIR__ . '/Stub/WebsocketClient.php');

it('tests websocket connection', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(str_replace(subject: $serverCode, search: '//%action%', replace: <<<PHP
        \$worker->onWebSocketConnect = function () {
            echo "connected";
        };
        \$worker->onMessage = function () {};
    PHP));
    $serverProcess->start();
    usleep(250000);

    $clientProcess = new PhpProcess(str_replace(subject: $clientCode, search: '//%action%', replace: <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('connect');
        };
    PHP));
    $clientProcess->start();
    usleep(250000);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('connected')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('');

    $serverProcess->stop();
    $clientProcess->stop();
});

it('tests server and client sending and receiving messages', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(str_replace(subject: $serverCode, search: '//%action%', replace: <<<PHP
        \$worker->onMessage = function (TcpConnection \$connection, \$data) {
            echo \$data;
            \$connection->send('Hi');
        };
    PHP));
    $serverProcess->start();
    usleep(250000);

    $clientProcess = new PhpProcess(str_replace(subject: $clientCode, search: '//%action%', replace: <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('Hello Chance');
        };
        \$con->onMessage = function(\$con, \$data) {
            echo \$data;
        };
    PHP));
    $clientProcess->start();
    usleep(250000);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('Hello Chance')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('Hi');

    $serverProcess->stop();
    $clientProcess->stop();
});

it('tests server close connection', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(str_replace(subject: $serverCode, search: '//%action%', replace: <<<PHP
        \$worker->onWebSocketConnect = function (TcpConnection \$connection) {
            echo 'close connection';
            \$connection->close();
        };
        \$worker->onMessage = function () {};
    PHP));
    $serverProcess->start();
    usleep(250000);

    $clientProcess = new PhpProcess(str_replace(subject: $clientCode, search: '//%action%', replace: <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('connect');
        };
        \$con->onClose = function () {
            echo 'closed';
        };
    PHP));
    $clientProcess->start();
    usleep(250000);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('close connection')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('closed');

    $serverProcess->stop();
    $clientProcess->stop();
});

it('tests client close connection', function () use ($serverCode, $clientCode) {
    $serverProcess = new PhpProcess(str_replace(subject: $serverCode, search: '//%action%', replace: <<<PHP
        \$worker->onMessage = function () {};
        \$worker->onClose = function () {
            echo 'closed';
        };
    PHP));
    $serverProcess->start();
    usleep(250000);

    $clientProcess = new PhpProcess(str_replace(subject: $clientCode, search: '//%action%', replace: <<<PHP
        \$con->onWebSocketConnect = function(AsyncTcpConnection \$con) {
            \$con->send('connect');
            echo 'close connection';
            \$con->close();
        };
    PHP));
    $clientProcess->start();
    usleep(250000);

    expect(getNonFrameOutput($serverProcess->getOutput()))->toBe('closed')
        ->and(getNonFrameOutput($clientProcess->getOutput()))->toBe('close connection');

    $serverProcess->stop();
    $clientProcess->stop();
});
