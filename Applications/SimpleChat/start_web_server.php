<?php 
use \Workerman\WebServer;
use \Workerman\Autoloader;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

//@see http://doc3.workerman.net/advanced/webserver.html
$web_server = new WebServer("http://0.0.0.0:3737");
$web_server->name = 'SimpleChatWeb';
$web_server->count = 4;
$web_server->addRoot('example.com', __DIR__.'/../Web');

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
