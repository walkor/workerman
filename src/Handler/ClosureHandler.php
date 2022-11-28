<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Handler;

use Closure;
use Rexpl\Workerman\Workerman;

class ClosureHandler extends Handler
{
    /**
     * All closures.
     * 
     * @var array<int,array<Closure>>
     */
    protected array $closures = [];


    /**
     * If has closure for event.
     * 
     * @var array
     */
    protected array $events = [
        Workerman::ON_WORKER_START => false,
        Workerman::ON_WORKER_STOP => false,
        Workerman::ON_CONNECT => false,
        Workerman::ON_MESSAGE => false,
        Workerman::ON_CLOSE => false,
        Workerman::ON_ERROR => false,
        Workerman::ON_BUFFER_FULL => false,
        Workerman::ON_BUFFER_DRAIN => false,
    ];


    /**
     * Add event.
     * 
     * @param int $event
     * @param Closure $closure
     * 
     * @return void
     */
    public function add(int $event, Closure $closure): void
    {
        $this->closures[$event][] = $closure;
        $this->events[$event] = true;
    }


    /**
     * @param int $event
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    protected function handle(int $event, Closure $next, array $arguments): int
    {
        if (!$this->events[$event]) return $next($arguments);

        foreach ($this->closures[$event] as $closure) {
            
            $closure(...$arguments);
        }

        return $next($arguments);
    }
}