<?php

declare(strict_types=1);

use Workerman\Connection\AsyncTcpConnection;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
if (!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));

$worker = new Worker();
$worker->onWorkerStart = function($worker) {
    $con = new AsyncTcpConnection('ws://127.0.0.1:8081');
    //%action%
    $con->connect();
};

Worker::$pidFile = sprintf('%s/test-websocket-client.pid', sys_get_temp_dir());
Worker::$logFile = sprintf('%s/test-websocket-client.log', sys_get_temp_dir());
Worker::$command = 'start';
Worker::runAll();
