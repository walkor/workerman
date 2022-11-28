<?php

declare(strict_types=1);

namespace Rexpl\Workerman;

use Closure;
use LogicException;
use Rexpl\Workerman\Exceptions\HandlerException;
use Rexpl\Workerman\Exceptions\ProtocolException;
use Rexpl\Workerman\Exceptions\TransportException;
use Rexpl\Workerman\Exceptions\WorkermanException;
use Rexpl\Workerman\Handler\ClassHandler;
use Rexpl\Workerman\Handler\ClosureHandler;
use Rexpl\Workerman\Handler\Handler;
use Rexpl\Workerman\Handler\HandlerInterface;
use Rexpl\Workerman\Handler\ObjectHandler;
use Rexpl\Workerman\Tools\Helpers;
use Workerman\Events\EventInterface;
use Workerman\Connection\ConnectionInterface;

class Socket
{
    /**
     * All socket instances.
     *
     * @var array<self>
     */
    protected static $instances = [];


    /**
     * Object hash.
     * 
     * @var string
     */
    public readonly string $hash;


    /**
     * The transport being used.
     * 
     * @var int
     */
    protected int $transport;


    /**
     * The application layer protocol being used.
     * 
     * @var string
     */
    protected string $protocol;


    /**
     * The socket address (path or internet address)
     * 
     * @var string
     */
    protected string $address;


    /**
     * The stream context.
     * 
     * @var resource
     */
    protected $context;


    /**
     * The worker count
     * 
     * @var int
     */
    protected int $workerCount = 1;


    /**
     * Reuse port.
     * 
     * @var bool
     */
    protected bool $reusePort = false;


    /**
     * The socket name and process name.
     * 
     * @var string
     */
    protected string $name;


    /**
     * Is socket started.
     * 
     * @var bool
     */
    protected bool $isStarted = false;


    /**
     * Socket.
     * 
     * @var resource
     */
    protected $socket;


    /**
     * Is socket accepting connectons.
     * 
     * @var bool
     */
    protected $isAccepting = false;


    /**
     * Event loop.
     * 
     * @var EventInterface
     */
    protected EventInterface $eventLoop;


    /**
     * Handler.
     * 
     * @var array<HandlerInterface>
     */
    protected array $handlers = [];


    /**
     * Closure handlers.
     * 
     * @var ClosureHandler|null
     */
    protected ?ClosureHandler $closure = null;


    /**
     * Worker callable.
     * 
     * @var array
     */
    protected array $workerCallable = [];


    /**
     * Is the closure handler made.
     * 
     * @var bool
     */
    protected bool $isCallableMade = false;


    /**
     * @param int $transport
     * @param string $address
     * @param array $context
     * 
     * @return void
     */
    public function __construct(int $transport, string $address, array $context)
    {
        $this->hash = spl_object_hash($this);

        static::$instances[] = $this->setTransport($transport)->setAddress($address)->setContext($context);      
    }


    /**
     * Set the used transport.
     * 
     * @param int $transport
     * 
     * @return static
     * 
     * @throws TransportException
     */
    public function setTransport(int $transport): static
    {
        if (!array_key_exists($transport, Workerman::BUILT_IN_TRANSPORT)) {

            throw new TransportException(sprintf(
                'Unknown transport %d.', $transport
            ));
        }

        $this->transport = $transport;

        return $this;
    }


    /**
     * Get the used transport.
     * 
     * @return int
     */
    public function getTransport(): int
    {
        return $this->transport;
    }


    /**
     * Set the used protocol.
     * 
     * @param int|string $protocol
     * 
     * @return static
     */
    public function setProtocol(int|string $protocol): static
    {
        if (is_int($protocol)) return $this->setBuiltInProtocol($protocol);

        return $this->setCustomProtocol($protocol);
    }


    /**
     * Set a built in protocol.
     * 
     * @param int $protocol
     * 
     * @return static
     * 
     * @throws ProtocolException
     */
    protected function setBuiltInProtocol(int $protocol): static
    {
        if (!array_key_exists($protocol, Workerman::BUILT_IN_PROTOCOL)) {

            throw new ProtocolException(sprintf(
                'Unknown built in protocol %d.', $protocol
            ));
        }

        $this->protocol = Workerman::BUILT_IN_PROTOCOL[$protocol];

        return $this;
    }


    /**
     * Set a custom in protocol.
     * 
     * @param string $protocol
     * 
     * @return static
     * 
     * @throws ProtocolException
     */
    protected function setCustomProtocol(string $protocol): static
    {
        if (
            !class_exists($protocol)
            || !in_array($protocol, class_implements(ProtocolInterface::class))
        ) {
            throw new ProtocolException(sprintf(
                'Class %s does not implement %s.', $protocol, ProtocolInterface::class
            ));
        }

        $this->protocol = $protocol;

        return $this;
    }


    /**
     * Get the application layer protocol.
     * 
     * @return string|null
     */
    public function getProtocol(): ?string
    {
        return $this->protocol;
    }


    /**
     * Set address.
     * 
     * @param string $address
     * 
     * @return static
     */
    public function setAddress(string $address): static
    {
        $this->address = sprintf(
            '%s://%s', Workerman::BUILT_IN_TRANSPORT[$this->transport], $address
        );

        return $this;
    }


    /**
     * Get the address.
     * 
     * @return string
     */
    public function getAddress(): string
    {
        return $this->address;
    }


    /**
     * Set the socket context.
     * 
     * @param array $context
     * 
     * @return static
     */
    public function setContext(array $context): static
    {
        $context['socket']['backlog'] = $context['socket']['backlog'] ?? Workerman::DEFAULT_BACKLOG;
        $this->context = stream_context_create($context);

        return $this;
    }


    /**
     * Get the socket context.
     * 
     * @return resource
     */
    public function getContext()//: resource
    {
        return $this->context;
    }


    /**
     * Set the worker count.
     * 
     * @param int $count
     * 
     * @return static
     * 
     * @throws WorkermanException
     */
    public function setWorkerCount(int $count): static
    {
        if ($count < 1) {

            throw new WorkermanException(sprintf(
                'Integer with a value higher than 1 expected for the worker count. %d given instead.', $count
            ));
        }

        $this->workerCount = $count;

        return $this;
    }


    /**
     * Get the worker count.
     * 
     * @return int
     */
    public function getWorkerCount(): int
    {
        return $this->workerCount;
    }


    /**
     * Enable reuse port.
     * 
     * @return static
     */
    public function enableReusePort(): static
    {
        $this->reusePort = true;

        return $this;
    }


    /**
     * Disable reuse port.
     * 
     * @return static
     */
    public function disableReusePort(): static
    {
        $this->reusePort = false;

        return $this;
    }


    /**
     * Get the reuse port setting.
     * 
     * @return bool
     */
    public function reusePort(): bool
    {
        return $this->reusePort;
    }


    /**
     * Set the process name.
     * 
     * @param string $name
     * 
     * @return static
     */
    public function setName(string $name): static
    {
        $this->name = ucfirst($name);

        return $this;
    }


    /**
     * Get the process name.
     * 
     * @return string
     */
    public function getName(): string
    {
        return $this->name ?? $this->address;
    }


    /**
     * Set the event loop.
     * 
     * @param EventInterface
     * 
     * @return static
     */
    public function setEventLoop(EventInterface $eventLoop): static
    {
        $this->eventLoop = new $eventLoop;

        return $this;
    }


    /**
     * Get the event loop.
     * 
     * @return EventInterface
     */
    public function getEventLoop(): EventInterface
    {
        return $this->eventLoop ?? $this->getDefaultEventLoop();
    }


    /**
     * Get the default event loop.
     * 
     * @return EventInterface
     */
    protected function getDefaultEventLoop(): EventInterface
    {
        $eventLoop = Helpers::detectEventLoop();

        return $this->eventLoop = new $eventLoop;
    }


    /**
     * Add a handler.
     * 
     * @param HandlerInterface $handler
     *
     * @return static
     */
    public function addHandler(HandlerInterface $handler): static
    {
        $this->handlers[] = $handler;

        return $this;
    }


    /**
     * Verify an event exists.
     *
     * @param integer $event
     * 
     * @return void
     * 
     * @throws HandlerException
     */
    protected function verifyEventExists(int $event): void
    {
        if (array_key_exists($event, Handler::EVENT_METHODS)) return;

        throw new HandlerException(sprintf(
            'Event %d does not exists', $event
        ));
    }


    /**
     * Add a handler.
     * 
     * @param object|string $class
     * @param int|null $constructor
     * 
     * @return static
     */
    public function addObject(object|string $class, ?int $constructor = null): static
    {
        $this->hasHandler = true;

        if (is_object($class)) return $this->addHandler(new ObjectHandler($class));

        $this->verifyEventExists($constructor);

        return $this->addHandler(new ClassHandler($class, $constructor));
    }


    /**
     * Return the closure handler.
     * 
     * @return ClosureHandler
     */
    public function closureHandler(): ClosureHandler
    {
        return $this->closure ?? $this->closure = new ClosureHandler();
    }


    /**
     * Add closure.
     * 
     * @param int $event
     * @param Closure $closure
     * 
     * @return static
     */
    public function addClosure(int $event, Closure $closure): static
    {
        $this->verifyEventExists($event);

        $this->closureHandler()->add($event, $closure);

        return $this;
    }


    /**
     * Is socket created.
     * 
     * @return bool
     */
    public function isSocketStarted(): bool
    {
        return $this->isStarted;
    }


    /**
     * Start socket.
     * 
     * @return void
     * 
     * @throws LogicException
     * @throws WorkermanException
     */
    public function startSocket(): void
    {
        if ($this->isStarted) {

            throw new LogicException(
                'Cannot start listening on socket, socket already listening.'
            );
        }

        $errorCode = 0;
        $errorMessage = '';

        if ($this->reusePort) stream_context_set_option($this->context, 'socket', 'so_reuseport', 1);

        $this->socket = stream_socket_server(
            $this->address,
            $errorCode,
            $errorMessage,
            $this->transport === Workerman::UPD_TRANSPORT ? \STREAM_SERVER_BIND : \STREAM_SERVER_BIND | \STREAM_SERVER_LISTEN,
            $this->context
        );

        if (!$this->socket) {
            
            throw new WorkermanException(sprintf(
                'Failed to create socket server. Socket error: %s.', $errorMessage
            ));
        }

        switch ($this->transport) {
            case Workerman::SSL_TRANSPORT:
                
                stream_socket_enable_crypto($this->socket, false);
            
            case Workerman::TCP_TRANSPORT:
            
                $socket = socket_import_stream($this->socket);
                socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
                break;

            case Workerman::UNIX_TRANSPORT:
            
                if ($this->user) chown($this->address, $this->user);
                if ($this->group) chgrp($this->address, $this->group);
        }

        stream_set_blocking($this->socket, false);

        $this->isStarted = true;
    }


    /**
     * Destroy the socket.
     * 
     * @return void
     * 
     * @throws LogicException
     * @throws WorkermanException
     */
    public function destroySocket(): void
    {
        if (!$this->isStarted) {

            throw new LogicException(
                'Cannot stop listening on socket, socket not started.'
            );
        }

        if ($this->isAccepting) $this->pauseAccept();

        if (!fclose($this->socket)) {

            throw new WorkermanException(sprintf(
                'Failed closing socket listening on %s.', $this->address
            ));
        }

        $this->socket = null;
        $this->isStarted = false;
    }


    /**
     * Destroy all sockets exept this one.
     * 
     * @return void
     */
    public function destroyCompetition(): void
    {
        foreach (static::$instances as $key => $socket) {
            
            if ($this->hash === $socket->hash) continue;

            $socket->destroySocket();
            unset(static::$instances[$key]);
        }
    }


    /**
     * Is socket accepting connections.
     * 
     * @return bool
     */
    public function isAccepting(): bool
    {
        return $this->isAccepting;
    }


    /**
     * Resume or start accepting connections.
     * 
     * @param Worker $worker
     * 
     * @return void
     * 
     * @throws LogicException
     */
    public function resumeAccept(Worker $worker): void
    {
        if (
            !$this->isStarted
            || $this->isAccepting
        ) {
            throw new LogicException(
                'Cannot start/resume accepting connections. The socket is not started or was already accepting.'
            );
        }

        $method = $this->transport === Workerman::UPD_TRANSPORT ? 'acceptUdpConnection' : 'acceptConnection';
        $this->getEventLoop()->add($this->socket, EventInterface::EV_READ, [$worker, $method]);

        $this->isAccepting = true;
    }


    /**
     * Pause accepting connections.
     * 
     * @return void
     * 
     * @throws LogicException
     */
    public function pauseAccept(): void
    {
        if (
            !$this->isStarted
            || !$this->isAccepting
        ) {
            throw new LogicException(
                'Cannot pause accepting connections. The socket is not started or was already not accepting.'
            );
        }

        $this->getEventLoop()->del($this->socket, EventInterface::EV_READ);

        $this->isAccepting = false;
    }


    /**
     * Make the closure handler.
     *
     * @return void
     */
    protected function makeClosureHandler(): void
    {
        if ($this->closure) $this->addHandler($this->closure);
    }


    /**
     * Build connection feeder.
     * 
     * @return void
     */
    protected function buildConnectionFeeder(): void
    {
        $this->makeClosureHandler();

        foreach (Handler::EVENT_METHODS as $key => $event) {
            
            /**
             * This is an uggly temporary solution.
             * At the moment official library cannot work with our way, only with callables.
             */
            $this->workerCallable[$key] = array_reduce(

                $this->handlers,
            
                function ($next, $handler) use ($event)
                {	
                    return function ($arguments) use ($handler, $next, $event)
                    {
                        return $handler->{$event}($next, $arguments);
                    };
                },
            
                fn() => 0
            );
        }

        $this->isCallableMade = true;
    }


    /**
     * Feeds a connection with all variables needed.
     * 
     * @param ConnectionInterface $connection
     * 
     * @return void
     */
    public function feedConnection(ConnectionInterface $connection): void
    {
        if (!$this->isCallableMade) $this->buildConnectionFeeder();

        foreach (Handler::CONNECTION_METHODS as $key => $event) {

            $pipeline = $this->workerCallable[$key];

            $connection->$event = function($object, $data = null) use ($pipeline)
            {
                $pipeline([$object, $data]);
            };
        }
    }


    /**
     * Return all instances.
     * 
     * @return array<self>
     */
    public static function allSockets(): array
    {
        return static::$instances;
    }
}