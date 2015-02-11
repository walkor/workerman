<?php
/**
 * run with command 
 * php start.php start
 */

ini_set('display_errors', 'on');
use WorkerMan\Worker;

require_once __DIR__ . '/Workerman/Autoloader.php';

foreach(glob(__DIR__.'/Applications/*/start.php') as $start_file)
{
    require_once $start_file;
}

Worker::runAll();