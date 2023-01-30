# Workerman
[![Gitter](https://badges.gitter.im/walkor/Workerman.svg)](https://gitter.im/walkor/Workerman?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=body_badge)
[![Latest Stable Version](https://poser.pugx.org/workerman/workerman/v/stable)](https://packagist.org/packages/workerman/workerman)
[![Total Downloads](https://poser.pugx.org/workerman/workerman/downloads)](https://packagist.org/packages/workerman/workerman)
[![Monthly Downloads](https://poser.pugx.org/workerman/workerman/d/monthly)](https://packagist.org/packages/workerman/workerman)
[![Daily Downloads](https://poser.pugx.org/workerman/workerman/d/daily)](https://packagist.org/packages/workerman/workerman)
[![License](https://poser.pugx.org/workerman/workerman/license)](https://packagist.org/packages/workerman/workerman)

## What is it
Workerman is an asynchronous event-driven PHP framework with high performance to build fast and scalable network applications. 
Workerman supports HTTP, Websocket, SSL and other custom protocols. 
Workerman supports event extension.

## Requires
PHP 7.0 or Higher  
A POSIX compatible operating system (Linux, OSX, BSD)  
POSIX and PCNTL extensions required   
Event extension recommended for better performance  

## Installation

```
composer require workerman/workerman
```

## Basic Usage

### A websocket server 
```php
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
```

### An http server
```php
<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// #### http worker ####
$http_worker = new Worker('http://0.0.0.0:2345');

// 4 processes
$http_worker->count = 4;

// Emitted when data received
$http_worker->onMessage = function ($connection, $request) {
    //$request->get();
    //$request->post();
    //$request->header();
    //$request->cookie();
    //$request->session();
    //$request->uri();
    //$request->path();
    //$request->method();

    // Send data to client
    $connection->send("Hello World");
};

// Run all workers
Worker::runAll();
```

### A tcp server
```php
<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// #### create socket and listen 1234 port ####
$tcp_worker = new Worker('tcp://0.0.0.0:1234');

// 4 processes
$tcp_worker->count = 4;

// Emitted when new connection come
$tcp_worker->onConnect = function ($connection) {
    echo "New Connection\n";
};

// Emitted when data received
$tcp_worker->onMessage = function ($connection, $data) {
    // Send data to client
    $connection->send("Hello $data \n");
};

// Emitted when connection is closed
$tcp_worker->onClose = function ($connection) {
    echo "Connection closed\n";
};

Worker::runAll();
```

### A udp server

```php
<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker('udp://0.0.0.0:1234');

// 4 processes
$tcp_worker->count = 4;

// Emitted when data received
$worker->onMessage = function($connection, $data)
{
    $connection->send($data);
};

Worker::runAll();
```

### Enable SSL
```php
<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// SSL context.
$context = array(
    'ssl' => array(
        'local_cert'  => '/your/path/of/server.pem',
        'local_pk'    => '/your/path/of/server.key',
        'verify_peer' => false,
    )
);

// Create a Websocket server with ssl context.
$ws_worker = new Worker('websocket://0.0.0.0:2346', $context);

// Enable SSL. WebSocket+SSL means that Secure WebSocket (wss://). 
// The similar approaches for Https etc.
$ws_worker->transport = 'ssl';

$ws_worker->onMessage = function ($connection, $data) {
    // Send hello $data
    $connection->send('Hello ' . $data);
};

Worker::runAll();
```

### Custom protocol
Protocols/MyTextProtocol.php
```php
<?php

namespace Protocols;

/**
 * User defined protocol
 * Format Text+"\n"
 */
class MyTextProtocol
{
    public static function input($recv_buffer)
    {
        // Find the position of the first occurrence of "\n"
        $pos = strpos($recv_buffer, "\n");

        // Not a complete package. Return 0 because the length of package can not be calculated
        if ($pos === false) {
            return 0;
        }

        // Return length of the package
        return $pos+1;
    }

    public static function decode($recv_buffer)
    {
        return trim($recv_buffer);
    }

    public static function encode($data)
    {
        return $data . "\n";
    }
}
```

```php
<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// #### MyTextProtocol worker ####
$text_worker = new Worker('MyTextProtocol://0.0.0.0:5678');

$text_worker->onConnect = function ($connection) {
    echo "New connection\n";
};

$text_worker->onMessage = function ($connection, $data) {
    // Send data to client
    $connection->send("Hello world\n");
};

$text_worker->onClose = function ($connection) {
    echo "Connection closed\n";
};

// Run all workers
Worker::runAll();
```

### Timer
```php
<?php

use Workerman\Worker;
use Workerman\Timer;

require_once __DIR__ . '/vendor/autoload.php';

$task = new Worker();
$task->onWorkerStart = function ($task) {
    // 2.5 seconds
    $time_interval = 2.5; 
    $timer_id = Timer::add($time_interval, function () {
        echo "Timer run\n";
    });
};

// Run all workers
Worker::runAll();
```

### AsyncTcpConnection (tcp/ws/text/frame etc...)
```php
<?php

use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker();
$worker->onWorkerStart = function () {
    // Websocket protocol for client.
    $ws_connection = new AsyncTcpConnection('ws://echo.websocket.org:80');
    $ws_connection->onConnect = function ($connection) {
        $connection->send('Hello');
    };
    $ws_connection->onMessage = function ($connection, $data) {
        echo "Recv: $data\n";
    };
    $ws_connection->onError = function ($connection, $code, $msg) {
        echo "Error: $msg\n";
    };
    $ws_connection->onClose = function ($connection) {
        echo "Connection closed\n";
    };
    $ws_connection->connect();
};

Worker::runAll();
```



## Available commands
```php start.php start  ```  
```php start.php start -d  ```  
![workerman start](http://www.workerman.net/img/workerman-start.png)  
```php start.php status  ```  
![workerman satus](http://www.workerman.net/img/workerman-status.png?a=123)  
```php start.php connections```  
```php start.php stop  ```  
```php start.php restart  ```  
```php start.php reload  ```  

## Documentation

中文主页:[http://www.workerman.net](https://www.workerman.net)

中文文档: [https://www.workerman.net/doc/workerman](https://www.workerman.net/doc/workerman)

Documentation:[https://github.com/walkor/workerman-manual](https://github.com/walkor/workerman-manual/blob/master/english/SUMMARY.md)

# Benchmarks
https://www.techempower.com/benchmarks/#section=data-r20&hw=ph&test=db&l=yyku7z-e7&a=2
![image](https://user-images.githubusercontent.com/6073368/146704320-1559fe97-aa67-4ee3-95d6-61e341b3c93b.png)

## Sponsors
[opencollective.com/walkor](https://opencollective.com/walkor)

[patreon.com/walkor](https://patreon.com/walkor)

## Donate

<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=UQGGS9UB35WWG"><img src="http://donate.workerman.net/img/donate.png"></a>

## Other links with workerman

[webman](https://github.com/walkor/webman)   
[PHPSocket.IO](https://github.com/walkor/phpsocket.io)   
[php-socks5](https://github.com/walkor/php-socks5)  
[php-http-proxy](https://github.com/walkor/php-http-proxy)  

## LICENSE

Workerman is released under the [MIT license](https://github.com/walkor/workerman/blob/master/MIT-LICENSE.txt).
