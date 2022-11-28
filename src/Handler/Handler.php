<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Handler;

use Closure;
use Rexpl\Workerman\Workerman;

abstract class Handler implements HandlerInterface
{
    /**
     * Events linked to methods.
     * 
     * @var array<int,string>
     */
    public const EVENT_METHODS = [
        Workerman::ON_WORKER_START => 'onWorkerStart',
        Workerman::ON_WORKER_STOP => 'onWorkerStop',
        Workerman::ON_CONNECT => 'onConnect',
        Workerman::ON_MESSAGE => 'onMessage',
        Workerman::ON_CLOSE => 'onClose',
        Workerman::ON_ERROR => 'onError',
        Workerman::ON_BUFFER_FULL => 'onBufferFull',
        Workerman::ON_BUFFER_DRAIN => 'onBufferDrain',
    ];


    /**
     * All connection events.
     * 
     * @var array<int,string>
     */
    public const CONNECTION_METHODS = [
        Workerman::ON_CONNECT => 'onConnect',
        Workerman::ON_MESSAGE => 'onMessage',
        Workerman::ON_CLOSE => 'onClose',
        Workerman::ON_ERROR => 'onError',
        Workerman::ON_BUFFER_FULL => 'onBufferFull',
        Workerman::ON_BUFFER_DRAIN => 'onBufferDrain',
    ];


    /**
     * @param int $event
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    abstract protected function handle(int $event, Closure $next, array $arguments): int;


    /**
     * Callback: onWorkerStart
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onWorkerStart(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_WORKER_START, $next, $arguments);
    }


    /**
     * Callback: onWorkerStop
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onWorkerStop(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_WORKER_STOP, $next, $arguments);
    }


    /**
     * Callback: onConnect
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onConnect(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_CONNECT, $next, $arguments);
    }


    /**
     * Callback: onMessage
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onMessage(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_MESSAGE, $next, $arguments);
    }


    /**
     * Callback: onClose
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onClose(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_CLOSE, $next, $arguments);
    }


    /**
     * Callback: onError
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onError(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_ERROR, $next, $arguments);
    }


    /**
     * Callback: onBufferFull
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onBufferFull(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_BUFFER_FULL, $next, $arguments);
    }


    /**
     * Callback: onBufferDrain
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onBufferDrain(Closure $next, array $arguments): int
    {
        return $this->handle(Workerman::ON_BUFFER_DRAIN, $next, $arguments);
    }
}