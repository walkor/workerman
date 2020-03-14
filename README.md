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
PHP 5.3 or Higher  
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

// 4 processes
$ws_worker->count = 4;

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
    //$requset->session();
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

// Emitted when new connection come
$tcp_worker->onClose = function ($connection) {
    echo "Connection closed\n";
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

中文主页:[http://www.workerman.net](http://www.workerman.net)

中文文档: [http://doc.workerman.net](http://doc.workerman.net)

Documentation:[https://github.com/walkor/workerman-manual](https://github.com/walkor/workerman-manual/blob/master/english/src/SUMMARY.md)

# Benchmarks
```
CPU:      Intel(R) Core(TM) i3-3220 CPU @ 3.30GHz and 4 processors totally
Memory:   8G
OS:       Ubuntu 14.04 LTS
Software: ab
PHP:      5.5.9
```

**Codes**
```php
<?php
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:1234');
$worker->count = 3;
$worker->onMessage = function ($connection, $data) {
    $connection->send("HTTP/1.1 200 OK\r\nConnection: keep-alive\r\nServer: workerman\r\nContent-Length: 5\r\n\r\nhello");
};

Worker::runAll();
```
**Result**

```shell
ab -n1000000 -c100 -k http://127.0.0.1:1234/
This is ApacheBench, Version 2.3 <$Revision: 1528965 $>
Copyright 1996 Adam Twiss, Zeus Technology Ltd, http://www.zeustech.net/
Licensed to The Apache Software Foundation, http://www.apache.org/

Benchmarking 127.0.0.1 (be patient)
Completed 100000 requests
Completed 200000 requests
Completed 300000 requests
Completed 400000 requests
Completed 500000 requests
Completed 600000 requests
Completed 700000 requests
Completed 800000 requests
Completed 900000 requests
Completed 1000000 requests
Finished 1000000 requests


Server Software:        workerman/3.1.4
Server Hostname:        127.0.0.1
Server Port:            1234

Document Path:          /
Document Length:        5 bytes

Concurrency Level:      100
Time taken for tests:   7.240 seconds
Complete requests:      1000000
Failed requests:        0
Keep-Alive requests:    1000000
Total transferred:      73000000 bytes
HTML transferred:       5000000 bytes
Requests per second:    138124.14 [#/sec] (mean)
Time per request:       0.724 [ms] (mean)
Time per request:       0.007 [ms] (mean, across all concurrent requests)
Transfer rate:          9846.74 [Kbytes/sec] received

Connection Times (ms)
              min  mean[+/-sd] median   max
Connect:        0    0   0.0      0       5
Processing:     0    1   0.2      1       9
Waiting:        0    1   0.2      1       9
Total:          0    1   0.2      1       9

Percentage of the requests served within a certain time (ms)
  50%      1
  66%      1
  75%      1
  80%      1
  90%      1
  95%      1
  98%      1
  99%      1
 100%      9 (longest request)

```


## Other links with workerman

[PHPSocket.IO](https://github.com/walkor/phpsocket.io)   
[php-socks5](https://github.com/walkor/php-socks5)  
[php-http-proxy](https://github.com/walkor/php-http-proxy)  

## Donate
<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=UQGGS9UB35WWG"><img src="http://donate.workerman.net/img/donate.png"></a>

## LICENSE

Workerman is released under the [MIT license](https://github.com/walkor/workerman/blob/master/MIT-LICENSE.txt).
