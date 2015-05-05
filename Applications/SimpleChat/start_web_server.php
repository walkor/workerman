<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
use \Workerman\Worker;
use \Workerman\WebServer;
use \Workerman\Autoloader;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

//@see http://doc3.workerman.net/advanced/webserver.html
$web_server = new WebServer("http://0.0.0.0:3737");
$web_server->name = 'SimpleChatWeb';
$web_server->count = 4;
$web_server->addRoot('example.com', __DIR__.'/Web');

if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
