<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    Ares<aresrr#qq.com>
 * @link      http://www.workerman.net/
 * @link      https://github.com/ares333/Workerman
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;

use Swoole\Event;
use Swoole\Timer;
use Swoole\Process;

class Swoole implements EventInterface
{

    protected $_timer = array();

    protected $_fd = array();

    // milisecond, set to null will use swoole signal.
    public static $signalDispatchInterval = 200;

    // Swoole\Process::signal() is not stable in some version of php and swoole.
    // The problem may be caused by using pcntl_signal() and pcntl_fork() and Swoole\Process::signal() together.
    protected $_hasSignal = false;

    /**
     *
     * {@inheritdoc}
     *
     * @see \Workerman\Events\EventInterface::add()
     */
    public function add($fd, $flag, $func, $args = null)
    {
        if (! isset($args)) {
            $args = array();
        }
        switch ($flag) {
            case self::EV_SIGNAL:
                if (isset(static::$signalDispatchInterval)) {
                    $res = pcntl_signal($fd, $func, false);
                    if (! $this->_hasSignal && $res) {
                        Timer::tick(static::$signalDispatchInterval,
                            function () {
                                pcntl_signal_dispatch();
                            });
                        $this->_hasSignal = true;
                    }
                    return $res;
                } else {
                    return Process::signal($fd, $func);
                }
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $method = self::EV_TIMER == $flag ? 'tick' : 'after';
                $timer_id = Timer::$method($fd * 1000,
                    function ($timer_id = null) use ($func, $args) {
                        call_user_func_array($func, $args);
                    });
                $this->_timer[] = $timer_id;
                return $timer_id;
            case self::EV_READ:
            case self::EV_WRITE:
                if ($flag == self::EV_READ) {
                    $res = Event::add($fd, $func, null, SWOOLE_EVENT_READ);
                } else {
                    $res = Event::add($fd, null, $func, SWOOLE_EVENT_WRITE);
                }
                if (! in_array((int) $fd, $this->_fd) && $res) {
                    $this->_fd[] = (int) $fd;
                }
                return $res;
        }
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \Workerman\Events\EventInterface::del()
     */
    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                if (isset(static::$signalDispatchInterval)) {
                    return pcntl_signal($fd, SIG_IGN, false);
                } else {
                    return Process::signal($fd, null);
                }
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                return Timer::clear($fd);
            case self::EV_READ:
            case self::EV_WRITE:
                $key = array_search((int) $fd, $this->_fd);
                if (false !== $key) {
                    $res = Event::del($fd);
                    if ($res) {
                        unset($this->_fd[$key]);
                    }
                    return $res;
                }
        }
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \Workerman\Events\EventInterface::clearAllTimer()
     */
    public function clearAllTimer()
    {
        foreach ($this->_timer as $v) {
            Timer::clear($v);
        }
        $this->_timer = array();
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \Workerman\Events\EventInterface::loop()
     */
    public function loop()
    {
        Event::wait();
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \Workerman\Events\EventInterface::destroy()
     */
    public function destroy()
    {
        Event::exit();
    }

    /**
     *
     * {@inheritdoc}
     *
     * @see \Workerman\Events\EventInterface::getTimerCount()
     */
    public function getTimerCount()
    {
        return count($this->_timer);
    }
}
