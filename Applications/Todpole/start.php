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


// bussinessWorker
$worker = new BusinessWorker();

$worker->name = 'TodpoleBusinessWorker';

$worker->count = 4;


// WebServer
$web = new WebServer("http://0.0.0.0:8686");

$web->count = 2;

$web->addRoot('www.your_domain.com', __DIR__.'/Web');
