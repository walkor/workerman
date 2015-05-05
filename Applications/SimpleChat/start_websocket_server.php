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
use \Workerman\Autoloader;

// autoload
require_once __DIR__ . '/../../Workerman/Autoloader.php';
Autoloader::setRootPath(__DIR__);

// create Websocket worker
$ws_server = new Worker('Websocket://0.0.0.0:3636');

$ws_server->name = 'SimpleChatWebSocket';

$ws_server->count = 1;

// @see http://doc3.workerman.net/worker-development/on-connect.html
$ws_server->onConnect = function($connection)
{
    // on WebSocket handshake 
    $connection->onWebSocketConnect = function($connection)
    {
        $data = array(
                'type' => 'login',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'from_id' => $connection->id,
        );
        broad_cast(json_encode($data));
    };
};

// @see http://doc3.workerman.net/worker-development/on-message.html
$ws_server->onMessage = function($connection, $data)use($ws_server)
{
    $data = array(
        'type' => 'say',
        'content' => $data,
        'time' => date('Y-m-d H:i:s'),
        // @see http://doc3.workerman.net/worker-development/id.html
        'from_id' => $connection->id,
    );
    broad_cast(json_encode($data));
};

// @see http://doc3.workerman.net/worker-development/connection-on-close.html
$ws_server->onClose = function($connection)
{
    $data = array(
                'type' => 'logout',
                'time' => date('Y-m-d H:i:s'),
                // @see http://doc3.workerman.net/worker-development/id.html
                'from_id' => $connection->id,
        );
        broad_cast(json_encode($data));
};

/**
 * broadcast
 * @param string $msg
 * @return void
 */
function broad_cast($msg)
{
    global $ws_server;
    //@see http://doc3.workerman.net/worker-development/connections.html
    foreach($ws_server->connections as $connection)
    {
        // @see http://doc3.workerman.net/worker-development/send.html
        $connection->send($msg);
    }
}


// 如果不是在根目录启动，则运行runAll方法
if(!defined('GLOBAL_START'))
{
    Worker::runAll();
}
