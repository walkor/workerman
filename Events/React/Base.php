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
namespace Workerman\Events\React;

use Workerman\Events\EventInterface;
use React\EventLoop\TimerInterface;
use React\EventLoop\LoopInterface;

/**
 * Class StreamSelectLoop
 * @package Workerman\Events\React
 */
class Base implements LoopInterface
{
    /**
     * @var array
     */
    protected $_timerIdMap = array();

    /**
     * @var int
     */
    protected $_timerIdIndex = 0;

    /**
     * @var array
     */
    protected $_signalHandlerMap = array();

    /**
     * @var LoopInterface
     */
    protected $_eventLoop = null;

    /**
     * Base constructor.
     */
    public function __construct()
    {
        $this->_eventLoop = new \React\EventLoop\StreamSelectLoop();
    }

    /**
     * Add event listener to event loop.
     *
     * @param int $fd
     * @param int $flag
     * @param callable $func
     * @param array $args
     * @return bool
     */
    public function add($fd, $flag, $func, array $args = array())
    {
        $args = (array)$args;
        switch ($flag) {
            case EventInterface::EV_READ:
                return $this->addReadStream($fd, $func);
            case EventInterface::EV_WRITE:
                return $this->addWriteStream($fd, $func);
            case EventInterface::EV_SIGNAL:
                if (isset($this->_signalHandlerMap[$fd])) {
                    $this->removeSignal($fd, $this->_signalHandlerMap[$fd]);
                }
                $this->_signalHandlerMap[$fd] = $func;
                return $this->addSignal($fd, $func);
            case EventInterface::EV_TIMER:
                $timer_obj = $this->addPeriodicTimer($fd, function() use ($func, $args) {
                    \call_user_func_array($func, $args);
                });
                $this->_timerIdMap[++$this->_timerIdIndex] = $timer_obj;
                return $this->_timerIdIndex;
            case EventInterface::EV_TIMER_ONCE:
                $index = ++$this->_timerIdIndex;
                $timer_obj = $this->addTimer($fd, function() use ($func, $args, $index) {
                    $this->del($index,EventInterface::EV_TIMER_ONCE);
                    \call_user_func_array($func, $args);
                });
                $this->_timerIdMap[$index] = $timer_obj;
                return $this->_timerIdIndex;
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
                return $this->removeReadStream($fd);
            case EventInterface::EV_WRITE:
                return $this->removeWriteStream($fd);
            case EventInterface::EV_SIGNAL:
                if (!isset($this->_eventLoop[$fd])) {
                    return false;
                }
                $func = $this->_eventLoop[$fd];
                unset($this->_eventLoop[$fd]);
                return $this->removeSignal($fd, $func);

            case EventInterface::EV_TIMER:
            case EventInterface::EV_TIMER_ONCE:
                if (isset($this->_timerIdMap[$fd])){
                    $timer_obj = $this->_timerIdMap[$fd];
                    unset($this->_timerIdMap[$fd]);
                    $this->cancelTimer($timer_obj);
                    return true;
                }
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
        $this->run();
    }


    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {

    }

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->_timerIdMap);
    }

    /**
     * @param resource $stream
     * @param callable $listener
     */
    public function addReadStream($stream, $listener)
    {
        return $this->_eventLoop->addReadStream($stream, $listener);
    }

    /**
     * @param resource $stream
     * @param callable $listener
     */
    public function addWriteStream($stream, $listener)
    {
        return $this->_eventLoop->addWriteStream($stream, $listener);
    }

    /**
     * @param resource $stream
     */
    public function removeReadStream($stream)
    {
        return $this->_eventLoop->removeReadStream($stream);
    }

    /**
     * @param resource $stream
     */
    public function removeWriteStream($stream)
    {
        return $this->_eventLoop->removeWriteStream($stream);
    }

    /**
     * @param float|int $interval
     * @param callable $callback
     * @return \React\EventLoop\Timer\Timer|TimerInterface
     */
    public function addTimer($interval, $callback)
    {
        return $this->_eventLoop->addTimer($interval, $callback);
    }

    /**
     * @param float|int $interval
     * @param callable $callback
     * @return \React\EventLoop\Timer\Timer|TimerInterface
     */
    public function addPeriodicTimer($interval, $callback)
    {
        return $this->_eventLoop->addPeriodicTimer($interval, $callback);
    }

    /**
     * @param TimerInterface $timer
     */
    public function cancelTimer(TimerInterface $timer)
    {
        return $this->_eventLoop->cancelTimer($timer);
    }

    /**
     * @param callable $listener
     */
    public function futureTick($listener)
    {
        return $this->_eventLoop->futureTick($listener);
    }

    /**
     * @param int $signal
     * @param callable $listener
     */
    public function addSignal($signal, $listener)
    {
        return $this->_eventLoop->addSignal($signal, $listener);
    }

    /**
     * @param int $signal
     * @param callable $listener
     */
    public function removeSignal($signal, $listener)
    {
        return $this->_eventLoop->removeSignal($signal, $listener);
    }

    /**
     * Run.
     */
    public function run()
    {
        return $this->_eventLoop->run();
    }

    /**
     * Stop.
     */
    public function stop()
    {
        return $this->_eventLoop->stop();
    }
}
