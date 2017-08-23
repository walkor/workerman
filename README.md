# Workerman
[![Gitter](https://badges.gitter.im/walkor/Workerman.svg)](https://gitter.im/walkor/Workerman?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=body_badge)

## What is it
Workerman is an asynchronous event driven PHP framework with high performance for easily building fast, scalable network applications. Supports HTTP, Websocket, SSL and other custom protocols. Supports libevent, [HHVM](https://github.com/facebook/hhvm) , [ReactPHP](https://github.com/reactphp/react).

## Requires
PHP 5.3 or Higher  
A POSIX compatible operating system (Linux, OSX, BSD)  
POSIX and PCNTL extensions for PHP  

## Installation

```
composer require workerman/workerman
```

## Basic Usage

### A websocket server 
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

// Create a Websocket server
$ws_worker = new Worker("websocket://0.0.0.0:2346");

// 4 processes
$ws_worker->count = 4;

// Emitted when new connection come
$ws_worker->onConnect = function($connection)
{
    echo "New connection\n";
 };

// Emitted when data received
$ws_worker->onMessage = function($connection, $data)
{
    // Send hello $data
    $connection->send('hello ' . $data);
};

// Emitted when connection closed
$ws_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

// Run worker
Worker::runAll();
```

### An http server
```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

// #### http worker ####
$http_worker = new Worker("http://0.0.0.0:2345");

// 4 processes
$http_worker->count = 4;

// Emitted when data received
$http_worker->onMessage = function($connection, $data)
{
    // $_GET, $_POST, $_COOKIE, $_SESSION, $_SERVER, $_FILES are available
    var_dump($_GET, $_POST, $_COOKIE, $_SESSION, $_SERVER, $_FILES);
    // send data to client
    $connection->send("hello world \n");
};

// run all workers
Worker::runAll();
```

### A WebServer
```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\WebServer;
use Workerman\Worker;

// WebServer
$web = new WebServer("http://0.0.0.0:80");

// 4 processes
$web->count = 4;

// Set the root of domains
$web->addRoot('www.your_domain.com', '/your/path/Web');
$web->addRoot('www.another_domain.com', '/another/path/Web');
// run all workers
Worker::runAll();
```

### A tcp server
```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

// #### create socket and listen 1234 port ####
$tcp_worker = new Worker("tcp://0.0.0.0:1234");

// 4 processes
$tcp_worker->count = 4;

// Emitted when new connection come
$tcp_worker->onConnect = function($connection)
{
    echo "New Connection\n";
};

// Emitted when data received
$tcp_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello $data \n");
};

// Emitted when new connection come
$tcp_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

Worker::runAll();
```

### Enable SSL.
```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

// SSL context.
$context = array(
    'ssl' => array(
        'local_cert' => '/your/path/of/server.pem',
        'local_pk'   => '/your/path/of/server.key',
    )
);

// Create a Websocket server with ssl context.
$ws_worker = new Worker("websocket://0.0.0.0:2346", $context);

// Enable SSL. WebSocket+SSL means that Secure WebSocket (wss://). 
// The similar approaches for Https etc.
$ws_worker->transport = 'ssl';

$ws_worker->onMessage = function($connection, $data)
{
    // Send hello $data
    $connection->send('hello ' . $data);
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
        if($pos === false)
        {
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
        return $data."\n";
    }
}
```

```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

// #### MyTextProtocol worker ####
$text_worker = new Worker("MyTextProtocol://0.0.0.0:5678");

$text_worker->onConnect = function($connection)
{
    echo "New connection\n";
};

$text_worker->onMessage =  function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

$text_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

// run all workers
Worker::runAll();
```

### Timer
```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use Workerman\Lib\Timer;

$task = new Worker();
$task->onWorkerStart = function($task)
{
    // 2.5 seconds
    $time_interval = 2.5; 
    $timer_id = Timer::add($time_interval, 
        function()
        {
            echo "Timer run\n";
        }
    );
};

// run all workers
Worker::runAll();
```

### AsyncTcpConnection (tcp/ws/text/frame etc...)
```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
use Workerman\Connection\AsyncTcpConnection;

$worker = new Worker();
$worker->onWorkerStart = function()
{
    // Websocket protocol for client.
    $ws_connection = new AsyncTcpConnection("ws://echo.websocket.org:80");
    $ws_connection->onConnect = function($connection){
        $connection->send('hello');
    };
    $ws_connection->onMessage = function($connection, $data){
        echo "recv: $data\n";
    };
    $ws_connection->onError = function($connection, $code, $msg){
        echo "error: $msg\n";
    };
    $ws_connection->onClose = function($connection){
        echo "connection closed\n";
    };
    $ws_connection->connect();
};
Worker::runAll();
```

### Async Mysql of ReactPHP
```
composer require react/mysql
```

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:6161');
$worker->onWorkerStart = function() {
    global $mysql;
    $loop  = Worker::getEventLoop();
    $mysql = new React\MySQL\Connection($loop, array(
        'host'   => '127.0.0.1',
        'dbname' => 'dbname',
        'user'   => 'user',
        'passwd' => 'passwd',
    ));
    $mysql->on('error', function($e){
        echo $e;
    });
    $mysql->connect(function ($e) {
        if($e) {
            echo $e;
        } else {
            echo "connect success\n";
        }
    });
};
$worker->onMessage = function($connection, $data) {
    global $mysql;
    $mysql->query('show databases' /*trim($data)*/, function ($command, $mysql) use ($connection) {
        if ($command->hasError()) {
            $error = $command->getError();
        } else {
            $results = $command->resultRows;
            $fields  = $command->resultFields;
            $connection->send(json_encode($results));
        }
    });
};
Worker::runAll();
```

### Async Redis of ReactPHP
```
composer require clue/redis-react
```

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Clue\React\Redis\Factory;
use Clue\React\Redis\Client;
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:6161');

$worker->onWorkerStart = function() {
    global $factory;
    $loop    = Worker::getEventLoop();
    $factory = new Factory($loop);
};

$worker->onMessage = function($connection, $data) {
    global $factory;
    $factory->createClient('localhost:6379')->then(function (Client $client) use ($connection) {
        $client->set('greeting', 'Hello world');
        $client->append('greeting', '!');

        $client->get('greeting')->then(function ($greeting) use ($connection){
            // Hello world!
            echo $greeting . PHP_EOL;
            $connection->send($greeting);
        });

        $client->incr('invocation')->then(function ($n) use ($connection){
            echo 'This is invocation #' . $n . PHP_EOL;
            $connection->send($n);
        });
    });
};

Worker::runAll();
```

### Aysnc dns of ReactPHP
```
composer require react/dns
```

```php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;
$worker = new Worker('tcp://0.0.0.0:6161');
$worker->onWorkerStart = function() {
    global   $dns;
    // Get event-loop.
    $loop    = Worker::getEventLoop();
    $factory = new React\Dns\Resolver\Factory();
    $dns     = $factory->create('8.8.8.8', $loop);
};
$worker->onMessage = function($connection, $host) {
    global $dns;
    $host = trim($host);
    $dns->resolve($host)->then(function($ip) use($host, $connection) {
        $connection->send("$host: $ip");
    },function($e) use($host, $connection){
        $connection->send("$host: {$e->getMessage()}");
    });
};

Worker::runAll();
```

### Http client of ReactPHP
```
composer require react/http-client
```

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('tcp://0.0.0.0:6161');

$worker->onWorkerStart = function() {
    global   $client;
    $loop    = Worker::getEventLoop();
    $factory = new React\Dns\Resolver\Factory();
    $dns     = $factory->createCached('8.8.8.8', $loop);
    $factory = new React\HttpClient\Factory();
    $client = $factory->create($loop, $dns);
};

$worker->onMessage = function($connection, $host) {
    global     $client;
    $request = $client->request('GET', trim($host));
    $request->on('error', function(Exception $e) use ($connection) {
        $connection->send($e);
    });
    $request->on('response', function ($response) use ($connection) {
        $response->on('data', function ($data, $response) use ($connection) {
            $connection->send($data);
        });
    });
    $request->end();
};

Worker::runAll();
```

### ZMQ of ReactPHP
```
composer require react/zmq
```

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('text://0.0.0.0:6161');

$worker->onWorkerStart = function() {
    global   $pull;
    $loop    = Worker::getEventLoop();
    $context = new React\ZMQ\Context($loop);
    $pull    = $context->getSocket(ZMQ::SOCKET_PULL);
    $pull->bind('tcp://127.0.0.1:5555');

    $pull->on('error', function ($e) {
        var_dump($e->getMessage());
    });

    $pull->on('message', function ($msg) {
        echo "Received: $msg\n";
    });
};

Worker::runAll();
```

### STOMP of ReactPHP
```
composer require react/stomp
```

```php
<?php
require_once __DIR__ . '/vendor/autoload.php';
use Workerman\Worker;

$worker = new Worker('text://0.0.0.0:6161');

$worker->onWorkerStart = function() {
    global   $client;
    $loop    = Worker::getEventLoop();
    $factory = new React\Stomp\Factory($loop);
    $client  = $factory->createClient(array('vhost' => '/', 'login' => 'guest', 'passcode' => 'guest'));

    $client
        ->connect()
        ->then(function ($client) use ($loop) {
            $client->subscribe('/topic/foo', function ($frame) {
                echo "Message received: {$frame->body}\n";
            });
        });
};

Worker::runAll();
```



## Available commands
```php start.php start  ```  
```php start.php start -d  ```  
![workerman start](http://www.workerman.net/img/workerman-start.png)  
```php start.php status  ```  
![workerman satus](http://www.workerman.net/img/workerman-status.png?a=123)
```php start.php connections
```php start.php stop  ```  
```php start.php restart  ```  
```php start.php reload  ```  

## Documentation

中文主页:[http://www.workerman.net](http://www.workerman.net)

中文文档: [http://doc3.workerman.net](http://doc3.workerman.net)

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
$worker->count=3;
$worker->onMessage = function($connection, $data)
{
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

## [PHPSocket.IO](https://github.com/walkor/phpsocket.io)  
[Live demo](http://www.workerman.net/demos/phpsocketio-chat/)  
[Source code](https://github.com/walkor/phpsocket.io)  
![phpsocket.io](http://www.workerman.net/img/socket.io.png)  

## [tadpole](http://kedou.workerman.net/)  
[Live demo](http://kedou.workerman.net/)  
[Source code](https://github.com/walkor/workerman)  
![workerman todpole](http://www.workerman.net/img/workerman-todpole.png)  

## [BrowserQuest](http://www.workerman.net/demos/browserquest/)   
[Live demo](http://www.workerman.net/demos/browserquest/)  
[Source code](https://github.com/walkor/BrowserQuest-PHP)  
![BrowserQuest width workerman](http://www.workerman.net/img/browserquest.jpg) 

## [web vmstat](http://www.workerman.net/demos/vmstat/)   
[Live demo](http://www.workerman.net/demos/vmstat/)  
[Source code](https://github.com/walkor/workerman-vmstat)  
![web vmstat](http://www.workerman.net/img/workerman-vmstat.png)   

## [live-ascii-camera](https://github.com/walkor/live-ascii-camera)   
[Live demo camera page](http://www.workerman.net/demos/live-ascii-camera/camera.html)  
[Live demo receive page](http://www.workerman.net/demos/live-ascii-camera/)  
[Source code](https://github.com/walkor/live-ascii-camera)  
![live-ascii-camera](http://www.workerman.net/img/live-ascii-camera.png)   

## [live-camera](https://github.com/walkor/live-camera)   
[Live demo camera page](http://www.workerman.net/demos/live-camera/camera.html)  
[Live demo receive page](http://www.workerman.net/demos/live-camera/)  
[Source code](https://github.com/walkor/live-camera)  
![live-camera](http://www.workerman.net/img/live-camera.jpg)  

## [chat room](http://chat.workerman.net/)  
[Live demo](http://chat.workerman.net/)  
[Source code](https://github.com/walkor/workerman-chat)  
![workerman-chat](http://www.workerman.net/img/workerman-chat.png)  

## [statistics](http://www.workerman.net:55757/)  
[Live demo](http://www.workerman.net:55757/)  
[Source code](https://github.com/walkor/workerman-statistics)  
![workerman-statistics](http://www.workerman.net/img/workerman-statistics.png)  

## [flappybird](http://workerman.net/demos/flappy-bird/)  
[Live demo](http://workerman.net/demos/flappy-bird/)  
[Source code](https://github.com/walkor/workerman-flappy-bird)  
![workerman-statistics](http://www.workerman.net/img/workerman-flappy-bird.png)  

## [jsonRpc](https://github.com/walkor/workerman-JsonRpc)  
[Source code](https://github.com/walkor/workerman-JsonRpc)  
![workerman-jsonRpc](http://www.workerman.net/img/workerman-json-rpc.png)  

## [thriftRpc](https://github.com/walkor/workerman-thrift)  
[Source code](https://github.com/walkor/workerman-thrift)  
![workerman-thriftRpc](http://www.workerman.net/img/workerman-thrift.png)  

## [web-msg-sender](https://github.com/walkor/web-msg-sender)  
[Live demo send page](http://workerman.net:3333/)  
[Live demo receive page](http://workerman.net/web-msg-sender.html)  
[Source code](https://github.com/walkor/web-msg-sender)  
![web-msg-sender](http://www.workerman.net/img/web-msg-sender.png)  

## [shadowsocks-php](https://github.com/walkor/shadowsocks-php)
[Source code](https://github.com/walkor/shadowsocks-php)  
![shadowsocks-php](http://www.workerman.net/img/shadowsocks-php.png)  

## [queue](https://github.com/walkor/workerman-queue)
[Source code](https://github.com/walkor/workerman-queue)  

## LICENSE

Workerman is released under the [MIT license](https://github.com/walkor/workerman/blob/master/MIT-LICENSE.txt).
