<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// Create a Websocket server
$ws_worker = new Worker('websocket://0.0.0.0:2346');

// Emitted when new connection come
$ws_worker->onConnect = function ($connection) {
    echo "New connection\n";
};

// Emitted when data received
$ws_worker->onMessage = function ($connection, $data) {
    // Send hello $data
    $connection->send('Hello ' . $data);
};

// Emitted when connection closed
$ws_worker->onClose = function ($connection) {
    echo "Connection closed\n";
};

// Run worker
Worker::runAll();
