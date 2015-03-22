## Workerman 3.0 

Home page:[http://www.workerman.net](http://www.workerman.net)

Documentation:[http://doc3.workerman.net](http://doc3.workerman.net)

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
![workerman start](http://www.workerman.net/img/workerman-start.png)  
```php test.php status  ```  
![workerman satus](http://www.workerman.net/img/workerman-status.png?a=123)
```php test.php stop  ```  
```php test.php restart  ```  
```php test.php reload  ```  

## Demos

### [tadpole](http://kedou.workerman.net/)  
![workerman todpole](http://www.workerman.net/img/workerman-todpole.png)  

### [BrowserQuest](http://browserquest.workerman.net/)    
![BrowserQuest width workerman](http://www.workerman.net/img/browserquest.jpg)  

### [chat room](http://chat.workerman.net/)  
![workerman-chat](http://www.workerman.net/img/workerman-chat.png)  

### [statistics](http://monitor.workerman.net/)  
![workerman-statistics](http://www.workerman.net/img/workerman-statistics.png)  

### [flappybird](http://flap.workerman.net/)  
![workerman-statistics](http://www.workerman.net/img/workerman-flappy-bird.png)  

### [jsonRpc](https://github.com/walkor/workerman-JsonRpc)  
![workerman-jsonRpc](http://www.workerman.net/img/workerman-json-rpc.png)  

### [thriftRpc](https://github.com/walkor/workerman-thrift)  
![workerman-thriftRpc](http://www.workerman.net/img/workerman-thrift.png)  

### [web-msg-sender](https://github.com/walkor/web-msg-sender)  
![web-msg-sender](http://www.workerman.net/img/web-msg-sender.png)  

### [queue](https://github.com/walkor/workerman-queue)
