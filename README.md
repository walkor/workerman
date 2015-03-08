## Workerman 3.0 

homepage:[http://www.workerman.net](http://www.workerman.net)

manual:[http://doc3.workerman.net](http://doc3.workerman.net)

## What is it
Workerman is a library for event-driven programming in PHP. It has a huge number of features. Each worker is able to handle thousands of connections.

## Usage

### A tcp server
test.php
```php
require_once './Workerman/Autoloader.php';
use Workerman\Worker;

// #### create socket and listen 1234 port ####
$tcp_worker = new Worker("tcp://0.0.0.0:1234");
//create 4 hello_worker processes
$tcp_worker->count = 4;
// when client send data to 1234 port
$tcp_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello $data \n");
};

Worker::runAll();
```

### A http server
test.php
```php
require_once './Workerman/Autoloader.php';
use Workerman\Worker;

// #### http worker ####
$http_worker = new Worker("http://0.0.0.0:2345");
$http_worker->count = 4;
$http_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

// run all workers
Worker::runAll();
```


### A websocket server 
test.php
```php
require_once './Workerman/Autoloader.php';
use Workerman\Worker
// #### websocket worker ####
$ws_worker = new Worker("websocket://0.0.0.0:5678");
$ws_worker->onMessage =  function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

// run all workers
Worker::runAll();
```

### User defined protocol 
Protocols/MyTextProtocol.php
```php
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

test.php
```php
require_once './Workerman/Autoloader.php';
use Workerman\Worker
// #### MyTextProtocol worker ####
$text_worker = new Worker("MyTextProtocol://0.0.0.0:5678");
$text_worker->onMessage =  function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

// run all workers
Worker::runAll();
```

### A WebServer
test.php
```php
require_once './Workerman/Autoloader.php';
use \Workerman\WebServer;
// WebServer
$web = new WebServer("http://0.0.0.0:8686");
$web->count = 2;
$web->addRoot('www.your_domain.com', __DIR__.'/Web');
// run all workers
Worker::runAll();
```

### Timer
test.php
```php
require_once './Workerman/Autoloader.php';
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

run width

```php test.php start```

## Available commands
```php test.php start  ```  
```php test.php start -d  ```  
```php test.php stop  ```  
```php test.php restart  ```  
```php test.php status  ```  
```php test.php reload  ```  

## Demos
[tadpole](http://kedou.workerman.net/)  
[chat room](http://chat.workerman.net/)  
[statistics](http://monitor.workerman.net/)  
[flappybird](http://flap.workerman.net/)  
[jsonRpc](https://github.com/walkor/workerman-JsonRpc)  
[thriftRpc](https://github.com/walkor/workerman-thrift)  
[web-msg-sender](https://github.com/walkor/web-msg-sender)  
[queue](https://github.com/walkor/workerman-queue)
