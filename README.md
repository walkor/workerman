## What is it
Workerman is an asynchronous event-driven PHP framework with high performance to build fast and scalable network applications. 
Workerman supports HTTP, Websocket, SSL and other custom protocols. 
Workerman supports event extension.

> ### :warning: This is just modified version that I made for learning purposes.
> ### Please use the [official workerman library](https://github.com/walkor/workerman).

## Installation

Add this line in the root composer.json

```json
"repositories": [
    {
        "url": "https://github.com/rexpl/workerman.git",
        "type": "git"
    }
],
```

Then you can run:

```
composer require rexpl/workerman
```

## Basic Usage

```php
<?php

use Rexpl\Workerman\Workerman;

require 'vendor/autoload.php';

// Create a websocket server
Workerman::newWebsocketServer('0.0.0.0:8080')
    ->setWorkerCount(4)
    ->setName('Websocket server');

// Create an http server
Workerman::newHttpServer('0.0.0.0:1335');
// The above is the same as
(new \Rexpl\Workerman\Socket(Workerman::TCP_TRANSPORT, '0.0.0.0:1335', []))
    ->setProtocol(Workerman::HTTP_PROTOCOL);

// Unix socket
Workerman::newUnixSocket('/path/to');

$path = '/path/for/workerman/files';

/**
 * You can start workerman with the symfony console.
 * 
 * You can optionnaly supply a Symfony console application instance to simply add the
 * workerman commands to an existing symfony console app
 */
Workerman::symfonyConsole($path, /*$app*/)->run();

$daemon = false;

/**
 * Or you can start manually.
 */
(new Workerman($path))->start($daemon);

// or (new Workerman($path))->stop();
// or (new Workerman($path))->restart();
```


## LICENSE

Workerman (official library) is released under the [MIT license](https://github.com/walkor/workerman/blob/master/MIT-LICENSE.txt).
