<?php 
use \Workerman\WebServer;
use \GatewayWorker\Gateway;
use \GatewayWorker\BusinessWorker;

// gateway
$gateway = new Gateway("Websocket://0.0.0.0:8585");

$gateway->name = 'TodpoleGateway';

$gateway->count = 4;

$gateway->lanIp = '127.0.0.1';

$gateway->startPort = 4000;

$gateway->pingInterval = 10;

$gateway->pingData = '{"type":"ping"}';

/* 
// 当客户端连接上来时，设置连接的onWebSocketConnect，即在websocket握手时的回调
$gateway->onConnect = function($connection)
{
    $connection->onWebSocketConnect = function($connection , $http_header)
    {
        // 可以在这里判断连接来源是否合法，不合法就关掉连接
        // $_SERVER['HTTP_ORIGIN']标识来自哪个站点的页面发起的websocket链接
        if($_SERVER['HTTP_ORIGIN'] != 'http://kedou.workerman.net')
        {
            $connection->close();
        }
        // onWebSocketConnect 里面$_GET $_SERVER是可用的
        // var_dump($_GET, $_SERVER);
    };
}; 
*/


// bussinessWorker
$worker = new BusinessWorker();

$worker->name = 'TodpoleBusinessWorker';

$worker->count = 4;


// WebServer
$web = new WebServer("http://0.0.0.0:8686");

$web->count = 2;

$web->addRoot('www.your_domain.com', __DIR__.'/Web');
