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

/**
 * Class LibEventLoop
 * @package Workerman\Events\React
 */
class LibEventLoop extends \React\EventLoop\LibEventLoop
{
    /**
     * Event base.
     *
     * @var event_base resource
     */
    protected $_eventBase = null;

    /**
     * All signal Event instances.
     *
     * @var array
     */
    protected $_signalEvents = array();

    /**
     * @var array
     */
    protected $_timerIdMap = array();

    /**
     * @var int
     */
    protected $_timerIdIndex = 0;

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
                return $this->addReadStream($fd, $func);
            case EventInterface::EV_WRITE:
                return $this->addWriteStream($fd, $func);
            case EventInterface::EV_SIGNAL:
                return $this->addSignal($fd, $func);
            case EventInterface::EV_TIMER:
                $timer_id = ++$this->_timerIdIndex;
                $timer_obj = $this->addPeriodicTimer($fd, function() use ($func, $args) {
                    call_user_func_array($func, $args);
                });
                $this->_timerIdMap[$timer_id] = $timer_obj;
                return $timer_id;
            case EventInterface::EV_TIMER_ONCE:
                $timer_id = ++$this->_timerIdIndex;
                $timer_obj = $this->addTimer($fd, function() use ($func, $args, $timer_id) {
                    unset($this->_timerIdMap[$timer_id]);
                    call_user_func_array($func, $args);
                });
                $this->_timerIdMap[$timer_id] = $timer_obj;
                return $timer_id;
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
                return $this->removeSignal($fd);
            case EventInterface::EV_TIMER:
            case EventInterface::EV_TIMER_ONCE;
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
     * Construct.
     */
    public function __construct()
    {
        parent::__construct();
        $class = new \ReflectionClass('\React\EventLoop\LibEventLoop');
        $property = $class->getProperty('eventBase');
        $property->setAccessible(true);
        $this->_eventBase = $property->getValue($this);
    }

    /**
     * Add signal handler.
     *
     * @param $signal
     * @param $callback
     * @return bool
     */
    public function addSignal($signal, $callback)
    {
        $event = event_new();
        $this->_signalEvents[$signal] = $event;
        event_set($event, $signal, EV_SIGNAL | EV_PERSIST, $callback);
        event_base_set($event, $this->_eventBase);
        event_add($event);
    }

    /**
     * Remove signal handler.
     *
     * @param $signal
     */
    public function removeSignal($signal)
    {
        if (isset($this->_signalEvents[$signal])) {
            $event = $this->_signalEvents[$signal];
            event_del($event);
            unset($this->_signalEvents[$signal]);
        }
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {
        foreach ($this->_signalEvents as $event) {
            event_del($event);
        }
    }

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return count($this->_timerIdMap);
    }
}
