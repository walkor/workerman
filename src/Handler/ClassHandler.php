<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Handler;

use Closure;
use Rexpl\Workerman\Exceptions\HandlerException;
use Rexpl\Workerman\Worker;
use Rexpl\Workerman\Workerman;
use Workerman\Connection\ConnectionInterface;

class ClassHandler extends Handler
{
    /**
     * Class for event.
     * 
     * @var string
     */
    protected string $class;


    /**
     * Constructor event.
     * 
     * @var int
     */
    protected int $constructor;


    /**
     * Is constrcutor event connection related.
     * 
     * @var bool
     */
    protected bool $isConstructorConnectionRelated;


    /**
     * Object containg the callables.
     * 
     * @var array<int,object>
     */
    protected array $objects;


    /**
     * @param string $class
     * 
     * @return void
     */
    public function __construct(string $class, int $constructor)
    {
        $this->class = $class;

        $this->discoverConstructor($constructor);
        $this->discoverAllMethods();
    }


    /**
     * Discover the constructor.
     * 
     * @param int $constructor
     * 
     * @return void
     */
    protected function discoverConstructor(int $constructor): void
    {
        $this->constructor = $constructor;
        $this->isConstructorConnectionRelated = $this->isConnectionRelated($constructor);
    }


    /**
     * Is the event connection related.
     * 
     * @param int $event
     * 
     * @return bool
     */
    protected function isConnectionRelated(int $event): bool
    {
        return !in_array($event, [Workerman::ON_WORKER_START, Workerman::ON_WORKER_STOP]);
    }


    /**
     * Discover all methods
     * 
     * @return void
     */
    protected function discoverAllMethods(): void
    {
        foreach (self::EVENT_METHODS as $key => $method) {

            if ($key === $this->constructor) continue;

            $this->has[$key] = method_exists($this->object, $method);
            
            if ($this->has[$key]) $this->methods[$key] = $method;
        }
    }


    /**
     * Get object.
     * 
     * @param int $event
     * @param Worker|ConnectionInterface $object
     * @param mixed $extra
     * 
     * @return object
     */
    protected function getObject(int $event, Worker|ConnectionInterface $object, mixed $extra): object
    {
        $object = $this->objects[$object->id] ?? $this->newObject($event, $object, $extra);

        /**
         * We remove the object ref to if it connection related and the connection is closed.
         * The purpose is to save memory on the long run.
         */
        if (
            $event === Workerman::ON_CLOSE
            && $this->isConstructorConnectionRelated
        ) unset($this->objects[$object->id]);

        return $object;
    }


    /**
     * Make new object.
     * 
     * @param int $event
     * @param Worker|ConnectionInterface $object
     * @param mixed $extra
     * 
     * @return object
     */
    protected function newObject(int $event, Worker|ConnectionInterface $object, mixed $extra): object
    {
        if ($event !== $this->constructor) {

            throw new HandlerException(
                'Event does not match constructor. Cannot make new object.'
            );
        }

        $class = $this->class;

        return $this->objects[$object->id] = new $class($object, $extra);
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
        if (!$this->has[$event]) return $next($arguments);

        [$object, $extra] = count($arguments) >= 2
            ? $arguments
            : [$arguments[0], null];

        $object = $this->getObject($event, $object, $extra);

        if ($event !== $this->constructor) $object->{$this->methods[$event]}(...$arguments);

        return $next($arguments);
    }
}