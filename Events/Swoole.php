<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    Ares<aresrr#qq.com>
 * @copyright Ares<aresrr#qq.com>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;

use Swoole\Event;
use Swoole\Timer;
use Swoole\Process;

class Swoole implements EventInterface
{

    protected $_timer = array();

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
                return Process::signal($fd, $func);
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
                return Event::add($fd,
                    function ($fd) use ($func, $args) {
                        call_user_func_array($func, $args);
                    }, null, SWOOLE_EVENT_READ);
            case self::EV_WRITE:
                return Event::add($fd, null,
                    function ($fd) use ($func, $args) {
                        call_user_func_array($func, $args);
                    }, SWOOLE_EVENT_WRITE);
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
                return Process::signal($fd, null);
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                return Timer::clear($fd);
            case self::EV_READ:
            case self::EV_WRITE:
                return Event::del($fd);
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
