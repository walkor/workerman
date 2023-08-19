<?php

use Symfony\Component\Process\PhpProcess;
use Workerman\Worker;

$serverAddress = 'udp://127.0.0.1:6789';
$process = null;
beforeAll(function () use ($serverAddress, &$process) {
    $process = new PhpProcess(<<<PHP
        <?php    
        if(!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
        if(!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
        if(!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));
        require './vendor/autoload.php';
        use Workerman\Worker;
        
        \$server = new Worker('$serverAddress');
        \$server->onMessage = function (\$connection, \$data) {
            \$connection->send('received: '.\$data);
        };
        Worker::\$command = 'start';
        Worker::runAll();
    PHP
    );
    $process->start();
    sleep(1);
});

afterAll(function () use (&$process) {
    $process->stop();
});

it('tests udp connection', function () use ($serverAddress) {
    $socket = stream_socket_client($serverAddress, $errno, $errstr, 1);
    expect($errno)->toBeInt()->toBe(0);
    fwrite($socket, 'xiami');
    $data = fread($socket, 1024);
    expect($data)->toBeString('received: xiami');
    fclose($socket);
})
    ->skipOnWindows(); //require posix
