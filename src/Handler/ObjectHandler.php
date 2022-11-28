<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Handler;

use Closure;

class ObjectHandler extends Handler
{
    /**
     * Object contaiing the callables.
     * 
     * @var object
     */
    protected object $object;


    /**
     * Marks whether this object has a method.
     * 
     * @var array<int,bool>
     */
    protected array $has = [];


    /**
     * All present methods.
     * 
     * @var array<int,string>
     */
    protected array $methods;


    /**
     * @param object $object
     * 
     * @return void
     */
    public function __construct(object $object)
    {
        $this->object = $object;

        $this->discoverAllMethods();
    }


    /**
     * Discover all methods
     * 
     * @return void
     */
    protected function discoverAllMethods(): void
    {
        foreach (self::EVENT_METHODS as $key => $method) {

            $this->has[$key] = method_exists($this->object, $method);
            
            if ($this->has[$key]) $this->methods[$key] = $method;
        }
    }


    /**
     * Handle callback event.
     * 
     * @param int $event
     * @param Closure $next
     * @param array $arguments
     * 
     * @return int
     */
    protected function handle(int $event, Closure $next, array $arguments): int
    {
        if ($this->has[$event]) $this->object->{$this->methods[$event]}(...$arguments);

        return $next($arguments);
    }
}