# Workerman
[![Gitter](https://badges.gitter.im/walkor/Workerman.svg)](https://gitter.im/walkor/Workerman?utm_source=badge&utm_medium=badge&utm_campaign=pr-badge&utm_content=body_badge)
[![Latest Stable Version](https://poser.pugx.org/workerman/workerman/v/stable)](https://packagist.org/packages/workerman/workerman)
[![Total Downloads](https://poser.pugx.org/workerman/workerman/downloads)](https://packagist.org/packages/workerman/workerman)
[![Monthly Downloads](https://poser.pugx.org/workerman/workerman/d/monthly)](https://packagist.org/packages/workerman/workerman)
[![Daily Downloads](https://poser.pugx.org/workerman/workerman/d/daily)](https://packagist.org/packages/workerman/workerman)
[![License](https://poser.pugx.org/workerman/workerman/license)](https://packagist.org/packages/workerman/workerman)

## What is it
Workerman is an asynchronous event-driven PHP framework with high performance to build fast and scalable network applications. It supports HTTP, WebSocket, custom protocols, coroutines, and connection pools, making it ideal for handling high-concurrency scenarios efficiently.

## Requires 
A POSIX compatible operating system (Linux, OSX, BSD)  
POSIX and PCNTL extensions required   
Event/Swoole/Swow extension recommended for better performance  

## Installation

```
composer require workerman/workerman
```

## Documentation

[https://manual.workerman.net](https://manual.workerman.net)

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

### Enable SSL
```php
<?php

use Workerman\Worker;

require_once __DIR__ . '/vendor/autoload.php';

// SSL context.
$context = [
    'ssl' => [
        'local_cert'  => '/your/path/of/server.pem',
        'local_pk'    => '/your/path/of/server.key',
        'verify_peer' => false,
    ]
];

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

### Coroutine

Coroutine is used to create coroutines, enabling the execution of asynchronous tasks to improve concurrency performance.

```php
<?php
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

$worker = new Worker('http://0.0.0.0:8001');

$worker->eventLoop = Swoole::class; // Or Swow::class or Fiber::class

$worker->onMessage = function (TcpConnection $connection, Request $request) {
    Coroutine::create(function () {
        echo file_get_contents("http://www.example.com/event/notify");
    });
    $connection->send('ok');
};

Worker::runAll();
```

> Note: Coroutine require Swoole extension or Swow extension or [Fiber revolt/event-loop](https://github.com/revoltphp/event-loop), and the same applies below

### Barrier
Barrier is used to manage concurrency and synchronization in coroutines. It allows tasks to run concurrently and waits until all tasks are completed, ensuring process synchronization.

```php
<?php
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Barrier;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

// Http Server
$worker = new Worker('http://0.0.0.0:8001');
$worker->eventLoop = Swoole::class; // Or Swow::class or Fiber::class
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $barrier = Barrier::create();
    for ($i=1; $i<5; $i++) {
        Coroutine::create(function () use ($barrier, $i) {
            file_get_contents("http://127.0.0.1:8002?task_id=$i");
        });
    }
    // Wait all coroutine done
    Barrier::wait($barrier);
    $connection->send('All Task Done');
};

// Task Server
$task = new Worker('http://0.0.0.0:8002');
$task->onMessage = function (TcpConnection $connection, Request $request) {
    $task_id = $request->get('task_id');
    $message = "Task $task_id Done";
    echo $message . PHP_EOL;
    $connection->close($message);
};

Worker::runAll();
```

### Parallel
Parallel executes multiple tasks concurrently and collects results. Use add to add tasks and wait to wait for completion and get results. Unlike Barrier, Parallel directly returns the results of each task.

```php
<?php
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Parallel;
use Workerman\Events\Swoole;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

// Http Server
$worker = new Worker('http://0.0.0.0:8001');
$worker->eventLoop = Swoole::class; // Or Swow::class or Fiber::class
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $parallel = new Parallel();
    for ($i=1; $i<5; $i++) {
        $parallel->add(function () use ($i) {
            return file_get_contents("http://127.0.0.1:8002?task_id=$i");
        });
    }
    $results = $parallel->wait();
    $connection->send(json_encode($results)); // Response: ["Task 1 Done","Task 2 Done","Task 3 Done","Task 4 Done"]
};

// Task Server
$task = new Worker('http://0.0.0.0:8002');
$task->onMessage = function (TcpConnection $connection, Request $request) {
    $task_id = $request->get('task_id');
    $message = "Task $task_id Done";
    $connection->close($message);
};

Worker::runAll();
```

### Channel

Channel is a mechanism for communication between coroutines. One coroutine can push data into the channel, while another can pop data from it, enabling synchronization and data sharing between coroutines.

```php
<?php
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Channel;
use Workerman\Coroutine;
use Workerman\Events\Swoole;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

// Http Server
$worker = new Worker('http://0.0.0.0:8001');
$worker->eventLoop = Swoole::class; // Or Swow::class or Fiber::class
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $channel = new Channel(2);
    Coroutine::create(function () use ($channel) {
        $channel->push('Task 1 Done');
    });
    Coroutine::create(function () use ($channel) {
        $channel->push('Task 2 Done');
    });
    $result = [];
    for ($i = 0; $i < 2; $i++) {
        $result[] = $channel->pop();
    }
    $connection->send(json_encode($result)); // Response: ["Task 1 Done","Task 2 Done"]
};
Worker::runAll();
```

### Pool

Pool is used to manage connection or resource pools, improving performance by reusing resources (e.g., database connections). It supports acquiring, returning, creating, and destroying resources.

```php
<?php
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Pool;
use Workerman\Events\Swoole;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

class RedisPool
{
    private Pool $pool;
    public function __construct($host, $port, $max_connections = 10)
    {
        $pool = new Pool($max_connections);
        $pool->setConnectionCreator(function () use ($host, $port) {
            $redis = new \Redis();
            $redis->connect($host, $port);
            return $redis;
        });
        $pool->setConnectionCloser(function ($redis) {
            $redis->close();
        });
        $pool->setHeartbeatChecker(function ($redis) {
            $redis->ping();
        });
        $this->pool = $pool;
    }
    public function get(): \Redis
    {
        return $this->pool->get();
    }
    public function put($redis): void
    {
        $this->pool->put($redis);
    }
}

// Http Server
$worker = new Worker('http://0.0.0.0:8001');
$worker->eventLoop = Swoole::class; // Or Swow::class or Fiber::class
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    static $pool;
    if (!$pool) {
        $pool = new RedisPool('127.0.0.1', 6379, 10);
    }
    $redis = $pool->get();
    $redis->set('key', 'hello');
    $value = $redis->get('key');
    $pool->put($redis);
    $connection->send($value);
};

Worker::runAll();
```


### Pool for automatic acquisition and release

```php
<?php
use Workerman\Connection\TcpConnection;
use Workerman\Coroutine\Context;
use Workerman\Coroutine;
use Workerman\Coroutine\Pool;
use Workerman\Events\Swoole;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
require_once __DIR__ . '/vendor/autoload.php';

class Db
{
    private static ?Pool $pool = null;
    public static function __callStatic($name, $arguments)
    {
        if (self::$pool === null) {
            self::initializePool();
        }
        // Get the connection from the coroutine context
        // to ensure the same connection is used within the same coroutine
        $pdo = Context::get('pdo');
        if (!$pdo) {
            // If no connection is retrieved, get one from the connection pool
            $pdo = self::$pool->get();
            Context::set('pdo', $pdo);
            // When the coroutine is destroyed, return the connection to the pool
            Coroutine::defer(function () use ($pdo) {
                self::$pool->put($pdo);
            });
        }
        return call_user_func_array([$pdo, $name], $arguments);
    }
    private static function initializePool(): void
    {
        self::$pool = new Pool(10);
        self::$pool->setConnectionCreator(function () {
            return new \PDO('mysql:host=127.0.0.1;dbname=your_database', 'your_username', 'your_password');
        });
        self::$pool->setConnectionCloser(function ($pdo) {
            $pdo = null;
        });
        self::$pool->setHeartbeatChecker(function ($pdo) {
            $pdo->query('SELECT 1');
        });
    }
}

// Http Server
$worker = new Worker('http://0.0.0.0:8001');
$worker->eventLoop = Swoole::class; // Or Swow::class or Fiber::class
$worker->onMessage = function (TcpConnection $connection, Request $request) {
    $value = Db::query('SELECT NOW() as now')->fetchAll();
    $connection->send(json_encode($value));
};

Worker::runAll();
```

## Available commands
```php start.php start  ```  
```php start.php start -d  ```  
```php start.php status  ```  
```php start.php status -d  ```  
```php start.php connections```  
```php start.php stop  ```  
```php start.php stop -g  ```  
```php start.php restart  ```  
```php start.php reload  ```  
```php start.php reload -g  ```

# Benchmarks
https://www.techempower.com/benchmarks/#section=data-r19&hw=ph&test=plaintext&l=zik073-1r


### Supported by

[![JetBrains logo.](https://resources.jetbrains.com/storage/products/company/brand/logos/jetbrains.svg)](https://jb.gg/OpenSourceSupport)


## Other links with workerman

[webman](https://github.com/walkor/webman)   
[AdapterMan](https://github.com/joanhey/AdapterMan)

## Donate
<a href="https://www.paypal.com/cgi-bin/webscr?cmd=_s-xclick&hosted_button_id=UQGGS9UB35WWG">PayPal</a>

## LICENSE

Workerman is released under the [MIT license](https://github.com/walkor/workerman/blob/master/MIT-LICENSE.txt).
