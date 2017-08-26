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
 * Class StreamSelectLoop
 * @package Workerman\Events\React
 */
class StreamSelectLoop extends \React\EventLoop\StreamSelectLoop
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
                $timer_obj = $this->addPeriodicTimer($fd, function() use ($func, $args) {
                    call_user_func_array($func, $args);
                });
                $this->_timerIdMap[++$this->_timerIdIndex] = $timer_obj;
                return $this->_timerIdIndex;
            case EventInterface::EV_TIMER_ONCE:
                $index = ++$this->_timerIdIndex;
                $timer_obj = $this->addTimer($fd, function() use ($func, $args, $index) {
                    $this->del($index,EventInterface::EV_TIMER_ONCE);
                    call_user_func_array($func, $args);
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
     * Add signal handler.
     *
     * @param $signal
     * @param $callback
     * @return bool
     */
    public function addSignal($signal, $callback)
    {
        if(DIRECTORY_SEPARATOR === '/') {
            pcntl_signal($signal, $callback);
        }
    }

    /**
     * Remove signal handler.
     *
     * @param $signal
     */
    public function removeSignal($signal)
    {
        if(DIRECTORY_SEPARATOR === '/') {
            pcntl_signal($signal, SIG_IGN);
        }
    }

    /**
     * Emulate a stream_select() implementation that does not break when passed
     * empty stream arrays.
     *
     * @param array        &$read   An array of read streams to select upon.
     * @param array        &$write  An array of write streams to select upon.
     * @param integer|null $timeout Activity timeout in microseconds, or null to wait forever.
     *
     * @return integer|false The total number of streams that are ready for read/write.
     * Can return false if stream_select() is interrupted by a signal.
     */
    protected function streamSelect(array &$read, array &$write, $timeout)
    {
        if ($read || $write) {
            $except = null;
            // Calls signal handlers for pending signals
            if(DIRECTORY_SEPARATOR === '/') {
                pcntl_signal_dispatch();
            }
            // suppress warnings that occur, when stream_select is interrupted by a signal
            return @stream_select($read, $write, $except, $timeout === null ? null : 0, $timeout);
        }

        // Calls signal handlers for pending signals
        if(DIRECTORY_SEPARATOR === '/') {
            pcntl_signal_dispatch();
        }
        $timeout && usleep($timeout);

        return 0;
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
        return count($this->_timerIdMap);
    }
}
