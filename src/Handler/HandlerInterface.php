<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Handler;

use Closure;
use Rexpl\Workerman\Worker;
use Workerman\Connection\ConnectionInterface;

interface HandlerInterface
{
    /**
     * Callback: onWorkerStart
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onWorkerStart(Closure $next, array $arguments): int;


    /**
     * Callback: onWorkerStop
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onWorkerStop(Closure $next, array $arguments): int;


    /**
     * Callback: onConnect
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onConnect(Closure $next, array $arguments): int;


    /**
     * Callback: onMessage
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onMessage(Closure $next, array $arguments): int;


    /**
     * Callback: onClose
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onClose(Closure $next, array $arguments): int;


    /**
     * Callback: onError
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onError(Closure $next, array $arguments): int;


    /**
     * Callback: onBufferFull
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onBufferFull(Closure $next, array $arguments): int;


    /**
     * Callback: onBufferFull
     * 
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    public function onBufferDrain(Closure $next, array $arguments): int;
}