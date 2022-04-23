<?php

require __DIR__ . '/vendor/autoload.php';

use Workerman\Connection\AsyncTcpConnection;
$worker = new \Workerman\Worker();
$worker->onWorkerStart = function($worker){
    echo '开始链接' . PHP_EOL;
    $url = 'ws://stream.binance.com:9443/ws';
    $con = new AsyncTcpConnection($url);
    $con->transport = 'ssl';
    $con->proxySocks5 = '127.0.0.1:1080';
//    $con->proxyHttp = '127.0.0.1:25378';

    $con->onConnect = function(AsyncTcpConnection $con) {
        $ww = [
            'id' => 1,
            'method' => 'SUBSCRIBE',
            'params' => [
                "btcusdt@aggTrade",
                "btcusdt@depth"
            ]
        ];
        echo '链接成功';
        $con->send(json_encode($ww));
        echo 'ok';
    };

    $con->onMessage = function(AsyncTcpConnection $con, $data) {
        echo $data;
    };

    $con->onClose = function (AsyncTcpConnection $con) {
        echo 'onClose' . PHP_EOL;
    };

    $con->onError = function (AsyncTcpConnection $con, $code, $msg) {
        echo "error [ $code ] $msg\n";
    };

    $con->connect();
};
\Workerman\Worker::runAll();
