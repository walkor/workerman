<?php

use Symfony\Component\Process\PhpProcess;

$process = null;
beforeAll(function () use (&$process) {
    $process = new PhpProcess(file_get_contents(__DIR__ . '/Stub/UdpServer.php'));
    $process->start();
    usleep(250000);
});

afterAll(function () use (&$process) {
    echo "\nUDP Test:\n", $process->getOutput();
    $process->stop();
});

it('tests udp connection', function () {
    $socket = stream_socket_client('udp://127.0.0.1:8083', $errno, $errstr, 1);
    expect($errno)->toBeInt()->toBe(0);
    fwrite($socket, 'xiami');
    $data = fread($socket, 1024);
    expect($data)->toBeString('received: xiami');
    fclose($socket);
})
    ->skipOnWindows(); //require posix
