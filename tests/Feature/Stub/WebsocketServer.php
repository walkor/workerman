<?php

declare(strict_types=1);

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

if (!defined('STDIN')) define('STDIN', fopen('php://stdin', 'r'));
if (!defined('STDOUT')) define('STDOUT', fopen('php://stdout', 'w'));
if (!defined('STDERR')) define('STDERR', fopen('php://stderr', 'w'));

$worker = new Worker("websocket://127.0.0.1:8081");
//%action%

Worker::$pidFile = sprintf('%s/test-websocket-server.pid', sys_get_temp_dir());
Worker::$logFile = sprintf('%s/test-websocket-server.log', sys_get_temp_dir());
Worker::$command = 'start';
Worker::runAll();
