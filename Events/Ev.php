<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;

/**
 * ev eventloop
 */
class Ev implements EventInterface
{
    /**
     * eventBase
     * @var object
     */
    protected $_eventBase = null;

    /**
     * 所有的事件
     * @var array
     */
    protected $_allEvents = array();

    /**
     * 所有的信号事件
     * @var array
     */
    protected $_eventSignal = array();

    /**
     * 所有的定时事件
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * 定时器id
     */
    protected static $_timerId = 1;

    /**
     * 添加事件
     * @see EventInterface::add()
     */
    public function add($fd, $flag, $func, $args=null)
    {
        $callback = function($event,$socket)use($fd,$func)
        {
            try
            {
                call_user_func($func,$fd);
            }
            catch(\Exception $e)
            {
                echo $e;
                exit(250);
            }
        };

        switch($flag)
        {
            case self::EV_SIGNAL:
                $event = new \EvSignal($fd, $callback);
                $this->_eventSignal[$fd] = $event;
                return true;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $repeat = $flag==self::EV_TIMER_ONCE ? 0 : $fd;
                $param = array($func, (array)$args, $flag, $fd, self::$_timerId);
                $event = new \EvTimer($fd, $repeat, array($this, 'timerCallback'),$param);
                $this->_eventTimer[self::$_timerId] = $event;
                return self::$_timerId++;
            default :
                $fd_key = (int)$fd;
                $real_flag = $flag === self::EV_READ ? \Ev::READ : \Ev::WRITE;
                $event = new \EvIo($fd, $real_flag, $callback);
                $this->_allEvents[$fd_key][$flag] = $event;
                return true;
        }

    }

    /**
     * 删除事件
     * @see Events\EventInterface::del()
     */
    public function del($fd ,$flag)
    {
        switch($flag)
        {
            case self::EV_READ:
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                if(isset($this->_allEvents[$fd_key][$flag]))
                {
                    $this->_allEvents[$fd_key][$flag]->stop();
                    unset($this->_allEvents[$fd_key][$flag]);
                }
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if(isset($this->_eventSignal[$fd_key]))
                {
                    $this->_allEvents[$fd_key][$flag]->stop();
                    unset($this->_eventSignal[$fd_key]);
                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                if(isset($this->_eventTimer[$fd]))
                {
                    $this->_eventTimer[$fd]->stop();
                    unset($this->_eventTimer[$fd]);
                }
                break;
        }
        return true;
    }

    /**
     * 定时器回调
     * @param event $event
     */
    public function timerCallback($event)
    {
        $param = $event->data;
        $timer_id = $param[4];
        if($param[2] === self::EV_TIMER_ONCE)
        {
            $this->_eventTimer[$timer_id]->stop();
            unset($this->_eventTimer[$timer_id]);
        }
        try
        {
            call_user_func_array($param[0],$param[1]);
        }
        catch(\Exception $e)
        {
            echo $e;
            exit(250);
        }
    }

    /**
     * 删除所有定时器
     * @return void
     */
    public function clearAllTimer()
    {
        foreach($this->_eventTimer as $event)
        {
            $event->stop();
        }
        $this->_eventTimer = array();
    }

    /**
     * 事件循环
     * @see EventInterface::loop()
     */
    public function loop()
    {
        \Ev::run();
    }
}


