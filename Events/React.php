<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;
use React\EventLoop\LoopInterface;
use React\EventLoop\Timer\TimerInterface;

/**
 * select eventloop
 */
class React implements LoopInterface
{
    /**
     * @var React\EventLoop\LoopInterface
     */
    protected $_loop = null;

    /**
     * React constructor.
     */
    public function __construct() {
        if (function_exists('event_base_new')) {
            $this->_loop = new \Workerman\Events\React\LibEventLoop();
        } elseif (class_exists('EventBase', false)) {
            $this->_loop = new \Workerman\Events\React\ExtEventLoop();
        } else {
            $this->_loop = new \Workerman\Events\React\StreamSelectLoop();
        }
    }

    /**
     * Add event listener to event loop.
     *
     * @param $fd
     * @param $flag
     * @param $func
     * @param array $args
     * @return bool
     */
    public function add($fd, $flag, $func, $args = array())
    {
        $args = (array)$args;
        switch ($flag) {
            case EventInterface::EV_READ:
                return $this->_loop->addReadStream($fd, $func);
            case EventInterface::EV_WRITE:
                return $this->_loop->addWriteStream($fd, $func);
            case EventInterface::EV_SIGNAL:
                return $this->_loop->addSignal($fd, $func);
            case EventInterface::EV_TIMER:
                return $this->_loop->addPeriodicTimer($fd, function() use ($func, $args) {
                    call_user_func_array($func, $args);
                });
            case EventInterface::EV_TIMER_ONCE:
                return $this->_loop->addTimer($fd, function() use ($func, $args) {
                    call_user_func_array($func, $args);
                });
        }
        return false;
    }

    /**
     * Remove event listener from event loop.
     *
     * @param mixed $fd
     * @param int   $flag
     * @return bool
     */
    public function del($fd, $flag)
    {
        switch ($flag) {
            case EventInterface::EV_READ:
                return $this->_loop->removeReadStream($fd);
            case EventInterface::EV_WRITE:
                return $this->_loop->removeWriteStream($fd);
            case EventInterface::EV_SIGNAL:
                return $this->_loop->removeSignal($fd);
            case EventInterface::EV_TIMER:
            case EventInterface::EV_TIMER_ONCE;
                return  $this->_loop->cancelTimer($fd);
        }
        return false;
    }


    /**
     * Main loop.
     *
     * @return void
     */
    public function loop()
    {
        $this->_loop->run();
    }

    /**
     * Register a listener to be notified when a stream is ready to read.
     *
     * @param resource $stream   The PHP stream resource to check.
     * @param callable $listener Invoked when the stream is ready.
     */
    public function addReadStream($stream, callable $listener) {
        return call_user_func(array($this->_loop, 'addReadStream'), $stream, $listener);
    }

    /**
     * Register a listener to be notified when a stream is ready to write.
     *
     * @param resource $stream   The PHP stream resource to check.
     * @param callable $listener Invoked when the stream is ready.
     */
    public function addWriteStream($stream, callable $listener) {
        return call_user_func(array($this->_loop, 'addWriteStream'), $stream, $listener);
    }

    /**
     * Remove the read event listener for the given stream.
     *
     * @param resource $stream The PHP stream resource.
     */
    public function removeReadStream($stream) {
        return call_user_func(array($this->_loop, 'removeReadStream'), $stream);
    }

    /**
     * Remove the write event listener for the given stream.
     *
     * @param resource $stream The PHP stream resource.
     */
    public function removeWriteStream($stream) {
        return call_user_func(array($this->_loop, 'removeWriteStream'), $stream);
    }

    /**
     * Remove all listeners for the given stream.
     *
     * @param resource $stream The PHP stream resource.
     */
    public function removeStream($stream) {
        return call_user_func(array($this->_loop, 'removeStream'), $stream);
    }

    /**
     * Enqueue a callback to be invoked once after the given interval.
     *
     * The execution order of timers scheduled to execute at the same time is
     * not guaranteed.
     *
     * @param int|float $interval The number of seconds to wait before execution.
     * @param callable  $callback The callback to invoke.
     *
     * @return TimerInterface
     */
    public function addTimer($interval, callable $callback) {
        return call_user_func(array($this->_loop, 'addTimer'), $interval, $callback);
    }

    /**
     * Enqueue a callback to be invoked repeatedly after the given interval.
     *
     * The execution order of timers scheduled to execute at the same time is
     * not guaranteed.
     *
     * @param int|float $interval The number of seconds to wait before execution.
     * @param callable  $callback The callback to invoke.
     *
     * @return TimerInterface
     */
    public function addPeriodicTimer($interval, callable $callback) {
        return call_user_func(array($this->_loop, 'addPeriodicTimer'), $interval, $callback);
    }

    /**
     * Cancel a pending timer.
     *
     * @param TimerInterface $timer The timer to cancel.
     */
    public function cancelTimer(TimerInterface $timer) {
        return call_user_func(array($this->_loop, 'cancelTimer'), $timer);
    }

    /**
     * Check if a given timer is active.
     *
     * @param TimerInterface $timer The timer to check.
     *
     * @return boolean True if the timer is still enqueued for execution.
     */
    public function isTimerActive(TimerInterface $timer) {
        return call_user_func(array($this->_loop, 'isTimerActive'), $timer);
    }

    /**
     * Schedule a callback to be invoked on the next tick of the event loop.
     *
     * Callbacks are guaranteed to be executed in the order they are enqueued,
     * before any timer or stream events.
     *
     * @param callable $listener The callback to invoke.
     */
    public function nextTick(callable $listener) {
        return call_user_func(array($this->_loop, 'nextTick'), $listener);
    }

    /**
     * Schedule a callback to be invoked on a future tick of the event loop.
     *
     * Callbacks are guaranteed to be executed in the order they are enqueued.
     *
     * @param callable $listener The callback to invoke.
     */
    public function futureTick(callable $listener) {
        return call_user_func(array($this->_loop, 'futureTick'), $listener);
    }

    /**
     * Perform a single iteration of the event loop.
     */
    public function tick() {
        return call_user_func(array($this->_loop, 'tick'));
    }

    /**
     * Run the event loop until there are no more tasks to perform.
     */
    public function run() {
        return call_user_func(array($this->_loop, 'run'));
    }

    /**
     * Instruct a running event loop to stop.
     */
    public function stop() {
        return call_user_func(array($this->_loop, 'stop'));
    }
}
