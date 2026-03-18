<?php

use Symfony\Component\Process\PhpProcess;

$process = null;
beforeAll(function () use (&$process) {
    $process = new PhpProcess(file_get_contents(__DIR__ . '/Stub/UdpServer.php'));
    $process->start();
    usleep(600000);
});

afterAll(function () use (&$process) {
    echo "\nUDP Test:\n", $process->getOutput();
    $process->stop();
});

it('tests udp connection', function () {
    $socket = stream_socket_client('udp://127.0.0.1:8083', $errno, $errstr, 1);
    expect($errno)->toBeInt()->toBe(0);
    stream_set_timeout($socket, 1);
    fwrite($socket, 'xiami');

    // 使用 recvfrom 读取，循环等待最多 ~1s
    $data = '';
    $start = microtime(true);
    do {
        $peer = null;
        $chunk = @stream_socket_recvfrom($socket, 1024, 0, $peer);
        if ($chunk !== false && $chunk !== '') {
            $data = $chunk;
            break;
        }
        usleep(50000);
    } while ((microtime(true) - $start) < 1.0);

    expect($data)->toBe('received: xiami');
    fclose($socket);
})
    ->skipOnWindows(); //require posix
