<?php

declare(strict_types=1);

namespace Rexpl\Workerman;

use LogicException;
use Rexpl\Workerman\Commands\Command;
use Rexpl\Workerman\Exceptions\WorkermanException;
use Rexpl\Workerman\Tools\Files;
use Rexpl\Workerman\Tools\Output;
use Rexpl\Workerman\Tools\OutputInterface;
use Symfony\Component\Console\Application;
use Workerman\Timer;

class Workerman
{
    /**
     * Workerman version.
     * 
     * @var string
     */
    public const VERSION = '1.0';


    /**
     * Exit success.
     * 
     * @var int
     */
    public const EXIT_SUCCESS = 0;


    /**
     * Exit on failure.
     * 
     * @var int
     */
    public const EXIT_FAILURE = 1;


    /**
     * Start command.
     * 
     * @var int
     */
    public const COMMAND_START = 0;


    /**
     * Reload command.
     * 
     * @var int
     */
    public const COMMAND_RELOAD = 1;


    /**
     * Stop command.
     * 
     * @var int
     */
    public const COMMAND_STOP = 2;


    /**
     * Status command.
     * 
     * @var int
     */
    public const COMMAND_STATUS = 3;


    /**
     * Linux like os.
     * 
     * @var int
     */
    public const LINUX = 0;


    /**
     * Windwos os.
     * 
     * @var int
     */
    public const WINDOWS = 1;


    /**
     * Path to the PID file.
     * 
     * @var string
     */
    public const PID_PATH = 'process.pid';


    /**
     * Default backlog. Backlog is the maximum length of the queue of pending connections.
     *
     * @var int
     */
    public const DEFAULT_BACKLOG = 102400;
    

    /**
     * Max udp package size.
     *
     * @var int
     */
    public const MAX_UDP_PACKAGE_SIZE = 65535;


    /**
     * Tcp transport.
     * 
     * @var int
     */
    public const TCP_TRANSPORT = 0;


    /**
     * Udp transport.
     * 
     * @var int
     */
    public const UPD_TRANSPORT = 1;


    /**
     * Ssl transport.
     * 
     * @var int
     */
    public const SSL_TRANSPORT = 2;


    /**
     * Unix socket transport.
     * 
     * @var int
     */
    public const UNIX_TRANSPORT = 3;


    /**
     * Built in transport.
     * 
     * @var array<int,string>
     */
    public const BUILT_IN_TRANSPORT = [
        self::TCP_TRANSPORT => 'tcp',
        self::UPD_TRANSPORT => 'udp',
        self::SSL_TRANSPORT => 'tcp',
        self::UNIX_TRANSPORT => 'unix',
    ];


    /**
     * Frame protocol.
     * 
     * @var int
     */
    public const FRAME_PROTOCOL = 0;


    /**
     * Text protocol.
     * 
     * @var int
     */
    public const TEXT_PROTOCOL = 1;


    /**
     * Http protocol.
     * 
     * @var int
     */
    public const HTTP_PROTOCOL = 2;


    /**
     * Websocket protocol.
     * 
     * @var int
     */
    public const WS_PROTOCOL = 3;


    /**
     * Built in protocols.
     * 
     * @var array<int,string>
     */
    public const BUILT_IN_PROTOCOL = [
        self::FRAME_PROTOCOL => \Workerman\Protocols\Frame::class,
        self::TEXT_PROTOCOL => \Workerman\Protocols\Text::class,
        self::HTTP_PROTOCOL => \Workerman\Protocols\Http::class,
        self::WS_PROTOCOL => \Workerman\Protocols\Websocket::class,
    ];


    /**
     * On worker start.
     * 
     * @var int
     */
    public const ON_WORKER_START = 0;


    /**
     * On worker stop.
     * 
     * @var int
     */
    public const ON_WORKER_STOP = 1;


    /**
     * On connect.
     * 
     * @var int
     */
    public const ON_CONNECT = 2;


    /**
     * On message.
     * 
     * @var int
     */
    public const ON_MESSAGE = 3;


    /**
     * On connection close.
     * 
     * @var int
     */
    public const ON_CLOSE = 4;


    /**
     * On error.
     * 
     * @var int
     */
    public const ON_ERROR = 5;


    /**
     * On buffer full.
     * 
     * @var int
     */
    public const ON_BUFFER_FULL = 6;


    /**
     * On buffer drain.
     * 
     * @var int
     */
    public const ON_BUFFER_DRAIN = 7;


    /**
     * Operating system.
     * 
     * @var int
     */
    protected int $os;


    /**
     * The command requested.
     * 
     * @var int
     */
    protected int $command;


    /**
     * Should run as a daemon.
     * 
     * @var bool
     */
    protected bool $daemon;


    /**
     * All workers.
     * 
     * @var array<int,Worker>
     */
    protected array $workers = [];


    /**
     * @param string $path
     * 
     * @return void
     */
    public function __construct(string $path)
    {
        if (PHP_SAPI !== 'cli') {

            throw new LogicException(
                'Workerman is a command line applicaton. Can only run workerman in cli environment.'
            );
        }

        Files::$rootPath = $path;
        Output::debug(sprintf(
            'Application path: %s', $path
        ));

        $this->os = DIRECTORY_SEPARATOR === '\\' ? self::WINDOWS : self::LINUX;
        Output::debug(sprintf(
            'Operating system: %s', $this->os ? 'Windows' : 'Unix'
        ));
    }


    /**
     * Start workerman.
     * 
     * @param bool $daemon
     * 
     * @return int
     */
    public function start(bool $daemon): int
    {
        $this->daemon = $daemon;
        $this->command = self::COMMAND_START;

        return $this->init();
    }


    /**
     * Initialize workerman.
     * 
     * @return int
     */
    protected function init(): int
    {
        Timer::init();

        switch ($this->command) {
            case self::COMMAND_START:
                
                Output::debug('Command: start');

                if ($this->os === self::LINUX) return $this->daemonizeIfNeeded();

                //return $this->forkWorkersForWindows();
            
            case self::COMMAND_RELOAD:

                Output::debug('Command: reload');
            
                //return $this->sendReloadSignal();

            case self::COMMAND_STOP:

                Output::debug('Command: stop');
            
                //return $this->stopWorkers();
            
            case self::COMMAND_STATUS:

                Output::debug('Command: status');
            
                //return $this->displayStatus();
        }
    }


    /**
     * Daemonize if needeed.
     * 
     * @return int
     */
    protected function daemonizeIfNeeded(): int
    {
        return $this->initAllSockets();
    }


    /**
     * Init all sockets.
     * 
     * @return int
     */
    protected function initAllSockets(): int
    {
        foreach (Socket::allSockets() as $socket) $this->initSocket($socket);

        return $this->forkAllSocketWorkers();
    }


    /**
     * Init socket.
     * 
     * @param Socket $socket
     * 
     * @return void
     */
    protected function initSocket(Socket $socket): void
    {
        if ($socket->reusePort()) return;

        Output::debug(sprintf(
            '(%s) Attempting to listen on: %s', $socket->getName(), $socket->getAddress()
        ));
        
        $socket->startSocket();
    }


    /**
     * Fork all socket workers.
     * 
     * @return int
     */
    protected function forkAllSocketWorkers(): int
    {
        foreach (Socket::allSockets() as $socket) $this->forkWorkers($socket);

        return $this->monitorWorkers();
    }


    /**
     * Fork workers for socket.
     * 
     * @param Socket $socket
     * 
     * @return void
     */
    protected function forkWorkers(Socket $socket): void
    {
        Output::debug(sprintf('(%s) Forking workers', $socket->getName()));

        foreach (range(1, $socket->getWorkerCount()) as $id) {
            
            $worker = new Worker($socket, $id);

            $pid = pcntl_fork();

            switch ($pid) {
                case 0:
                    
                    $worker->start($this->daemon);
                    break;

                case -1:

                    throw new WorkermanException(
                        'Fork worker failed.'
                    );
                
                default:
                    
                    $this->registerWorker($worker, $pid);
                    break;
            }
        }
    }


    /**
     * Register a new worker.
     * 
     * @param Worker $worker
     * @param int $pid
     * 
     * @return void
     */
    protected function registerWorker(Worker $worker, int $pid): void
    {
        $this->workers[$pid] = $worker;
    }


    /**
     * Monitor Workers.
     * 
     * @return int
     */
    protected function monitorWorkers(): int
    {
        $message = ['Succesfully started workerman.'];

        foreach (Socket::allSockets() as $socket) {
            
            $message[] = sprintf(
                '(%s) listening on %s with %d worker(s).',
                $socket->getName() === $socket->getAddress() ? 'unnamed' : $socket->getName(),
                $socket->getAddress(),
                $socket->getWorkerCount()
            );
            
        }

        Output::info($message);

        return (new Master($this->workers))->start($this->daemon);
    }


    /**
     * --------------------------------------------------------------------------
     * The methods below are made to start & configure the workerman environment.
     * --------------------------------------------------------------------------
     */


    /**
     * Add an ouput handler to workerman. The earlier the ouput handler is set the more info you get from the verbose mode (-v).
     * 
     * @param OutputInterface $handler
     * @param bool $afterStart If set to true the ouput interface will still be called after the start of the workerman daemon. Can become handy for a logger for exemple.
     * 
     * @return void
     */
    public static function addOutput(OutputInterface $handler, bool $afterStart = false): void
    {
        Output::addOutputHandler($handler, $afterStart);
    }


    /**
     * Create a new symfony console application.
     * 
     * The symfony console application will add an ouput handler to workerman and will
     * instantiate workerman and call the right method depending on the command.
     * 
     * @param string $path Application root path.
     * @param Application|null $app To only add the workerman commands to an exisitng symfony console application.
     * 
     * @return Application
     */
    public static function symfonyConsole(string $path, ?Application $app = null): Application
    {
        if (!$app) $app = new Application('Workerman revised (rexpl/workerman)', self::VERSION);

        Command::$path = $path;

        $app->add(new \Rexpl\Workerman\Commands\Start);
        $app->add(new \Rexpl\Workerman\Commands\Stop);
        $app->add(new \Rexpl\Workerman\Commands\Status);
        $app->add(new \Rexpl\Workerman\Commands\Reload);

        return $app;
    }


    /**
     * --------------------------------------------------------------------------
     * The methods below are shortcuts to create sockets.
     * --------------------------------------------------------------------------
     */


    /**
     * New worker.
     * 
     * @param int $transport
     * @param string $address
     * @param array $context
     * 
     * @return Socket
     */
    public static function newSocket(int $transport, string $address, array $context): Socket
    {
        return new Socket($transport, $address, $context);
    }


    /**
     * New unix socket.
     * 
     * @param string $address
     * @param array $context
     * 
     * @return Socket
     */
    public static function newUnixSocket(string $address, array $context = []): Socket
    {
        return self::newSocket(self::UNIX_TRANSPORT, $address, $context);
    }


    /**
     * New tcp server.
     * 
     * @param string $address
     * @param array $context
     * 
     * @return Socket
     */
    public static function newTcpServer(string $address, array $context = []): Socket
    {
        return self::newSocket(self::TCP_TRANSPORT, $address, $context);
    }


    /**
     * New udp server.
     * 
     * @param string $address
     * @param array $context
     * 
     * @return Socket
     */
    public static function newUdpServer(string $address, array $context = []): Socket
    {
        return self::newSocket(self::UPD_TRANSPORT, $address, $context);
    }


    /**
     * New ssl/tcp server.
     * 
     * @param string $address
     * @param array $context
     * 
     * @return Socket
     */
    public static function newSslServer(string $address, array $context = []): Socket
    {
        return self::newSocket(self::SSL_TRANSPORT, $address, $context);
    }


    /**
     * New tcp server with optional ssl.
     * 
     * @param string $address
     * @param array $context
     * @param bool $ssl
     * 
     * @return Socket
     */
    protected static function newPotentialSslServer(string $address, array $context, bool $ssl): Socket
    {
        return $ssl
            ? self::newSslServer($address, $context)
            : self::newTcpServer($address, $context);
    }


    /**
     * New http server. (over tcp)
     * 
     * @param string $address
     * @param array $context
     * @param bool $ssl
     * 
     * @return Socket
     */
    public static function newHttpServer(string $address, array $context = [], bool $ssl = false): Socket
    {
        return self::newPotentialSslServer($address, $context, $ssl)->setProtocol(self::HTTP_PROTOCOL);
    }


    /**
     * New websocket server. (over tcp)
     * 
     * @param string $address
     * @param array $context
     * @param bool $ssl
     * 
     * @return Socket
     */
    public static function newWebsocketServer(string $address, array $context = [], bool $ssl = false): Socket
    {
        return self::newPotentialSslServer($address, $context, $ssl)->setProtocol(self::WS_PROTOCOL);
    }
}