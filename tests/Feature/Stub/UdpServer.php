<?php

declare(strict_types=1);

use Workerman\Worker;

require './vendor/autoload.php';

if(!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
if(!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
if(!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));

$server = new Worker('udp://127.0.0.1:8083');

$server->onMessage = function ($connection, $data) {
    $connection->send('received: ' . $data);
};

Worker::$pidFile = sprintf('%s/test-udp-server.pid', sys_get_temp_dir());
Worker::$logFile = sprintf('%s/test-udp-server.log', sys_get_temp_dir());
Worker::$command = 'start';
Worker::runAll();
