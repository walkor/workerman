## workerman 3.0 
create test.php
```php
require_once './Workerman/Autoloader.php';
use Workerman\Worker;

// create socket and listen 1234 port
$tcp_worker = new Worker("tcp://0.0.0.0:1234");
//create 4 hello_worker processes
$tcp_worker->count = 4;
// when client send data to 1234 port
$tcp_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello $data \n");
};

// another http worker
$http_worker = new Worker("http://0.0.0.0:2345");
$http_worker->count = 4;
$http_worker->onMessage = function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

// websocket worker
$ws_worker = new Worker("websocket://0.0.0.0:5678");
$ws_worker->onMessage =  function($connection, $data)
{
    // send data to client
    $connection->send("hello world \n");
};

// run all workers
Worker::runAll();
```

run width
php test.php start

## available commands
php test.php stop  
php test.php restart  
php test.php status  
php test.php reload  

