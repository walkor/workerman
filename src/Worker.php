<?php

declare(strict_types=1);

namespace Rexpl\Workerman;

use Rexpl\Workerman\Tools\Helpers;
use Rexpl\Workerman\Tools\Output;
use Workerman\Worker as WorkermanWorker;
use Workerman\Connection\TcpConnection;
use Workerman\Events\EventInterface;
use Workerman\Timer;

class Worker
{
    /**
     * Socket.
     * 
     * @var Socket
     */
    protected Socket $socket;


    /**
     * Worker ID.
     * 
     * @var int
     */
    public readonly int $id;


    /**
     * Event loop.
     * 
     * @var EventInterface
     */
    protected EventInterface $eventLoop;


    /**
     * All connections.
     * 
     * @var array<int,TcpConnection>
     */
    public array $connections = [];


    /**
     * Total connections.
     * 
     * @var int
     */
    protected int $connectionCount = 0;


    /**
     * @param Socket $socket
     * @param int $id
     * 
     * @return void
     */
    public function __construct(Socket $socket, int $id)
    {
        $this->socket = $socket;
        $this->id = $id;
        $this->eventLoop = $socket->getEventLoop();
    }


    /**
     * Start the worker.
     * 
     * @param bool $daemon
     * 
     * @return void
     */
    public function start(bool $daemon): void
    {
        Output::debug(sprintf(
            'Worker (%d): listen: %s name: %s',
            $this->id, $this->socket->getAddress(), $this->socket->getName()
        ));

        Timer::delAll();
        
        if ($this->socket->reusePort()) $this->socket->startSocket();

        $this->socket->destroyCompetition();

        Helpers::setProcessTitle(sprintf(
            '%s worker (%d)', $this->socket->getName(), $this->id
        ));
        Helpers::eventSignalHandler($this->eventLoop, $this, 'signalHandler');

        if ($daemon) Helpers::surpressOuputStream();

        /**
         * /!\ Temporary fix /!\
         * 
         * This is currently needed as the official workerman library 
         * expect to comunicate with the worker in this way.
         * 
         * Potential solutions:
         *  - Rewrite connection and event loops
         *  - Extend classes wich need it
         */
        WorkermanWorker::$globalEvent = $this->eventLoop;

        Timer::init($this->eventLoop);

        $this->socket->resumeAccept($this);

        $this->eventLoop->loop();
    }


    /**
     * Accept a connection.
     * 
     * @param resource $socket
     * 
     * @return void
     */
    public function acceptConnection($socket)
    {
        $socket = stream_socket_accept($socket, 0, $remoteAddress);

        // Thundering herd.
        if (false === $socket) return;

        $this->connectionCount++;
        
        $connection = new TcpConnection($socket, $remoteAddress);

        $connection->worker = $this;
        $connection->protocol = $this->socket->getProtocol();
        $connection->transport = Workerman::BUILT_IN_TRANSPORT[$this->socket->getTransport()];
        
        $this->socket->feedConnection($connection);
        
        call_user_func($connection->onConnect, $connection);
    }


    /**
     * Singal handler.
     * 
     * @param int $signal
     * 
     * @return void
     */
    public function signalHandler(int $signal): void
    {
        Output::debug(sprintf('Worker (%d): Received signal %d', $this->id, $signal));

        switch ($signal) {
            case SIGHUP:
                
                $this->gracefullStop();
                break;

            case SIGQUIT:
            
                $this->hardStop();
                break;
            
            case SIGIOT:
        
                $this->writeStatus();
                break;
        }
    }


    /**
     * Stop the worker.
     * 
     * @return void
     */
    protected function stopSocket(): void
    {
        if ($this->socket->isSocketStarted()) $this->socket->destroySocket();        
    }


    /**
     * Gracefull stop.
     * 
     * @return void
     */
    public function gracefullStop(): void
    {
        $this->stopSocket();

        if ($this->connections === []) exit(Workerman::EXIT_SUCCESS);
    }


    /**
     * Hard stop.
     * 
     * @return void
     */
    public function hardStop(): void
    {
        echo "hardstop\n";

        $this->stopSocket();

        foreach ($this->connections as $co) $co->close();

        exit(Workerman::EXIT_SUCCESS);
    }


    /**
     * Write status to status file.
     * 
     * @return void
     */
    protected function writeStatus(): void
    {
        $memory = round(memory_get_usage() / (1024 * 1024), 2) . "M";
        $peakMemomry = round(memory_get_peak_usage() / (1024 * 1024), 2) . "M";

        echo json_encode(
            [
                'memory' => $memory,
                'peak_memory' => $peakMemomry,
                'connection_active' => count($this->connections),
                'connection_total' => $this->connectionCount,
            ],
            JSON_PRETTY_PRINT
        );
    }
}