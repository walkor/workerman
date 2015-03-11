<?php
/**
 * run with command 
 * php start.php start
 */

ini_set('display_errors', 'on');
use Workerman\Worker;

if(!extension_loaded('pcntl'))
{
    exit("Please install pcntl extension. See http://doc3.workerman.net/install/install.html\n");
}

if(!extension_loaded('posix'))
{
    exit("Please install posix extension. See http://doc3.workerman.net/install/install.html\n");
}

require_once __DIR__ . '/Workerman/Autoloader.php';

foreach(glob(__DIR__.'/Applications/*/start.php') as $start_file)
{
    require_once $start_file;
}

Worker::runAll();