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
 * libevent eventloop
 */
class Libevent implements EventInterface
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
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        $this->_eventBase = event_base_new();
    }
   
    /**
     * 添加事件
     * @see EventInterface::add()
     */
    public function add($fd, $flag, $func, $args=array())
    {
        switch($flag)
        {
            case self::EV_SIGNAL:
                $fd_key = (int)$fd;
                $real_flag = EV_SIGNAL | EV_PERSIST;
                $this->_eventSignal[$fd_key] = event_new();
                if(!event_set($this->_eventSignal[$fd_key], $fd, $real_flag, $func, null))
                {
                    return false;
                }
                if(!event_base_set($this->_eventSignal[$fd_key], $this->_eventBase))
                {
                    return false;
                }
                if(!event_add($this->_eventSignal[$fd_key]))
                {
                    return false;
                }
                return true;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $event = event_new();
                $timer_id = (int)$event;
                if(!event_set($event, 0, EV_TIMEOUT, array($this, 'timerCallback'), $timer_id))
                {
                    return false;
                }
                
                if(!event_base_set($event, $this->_eventBase))
                {
                    return false;
                }
                
                $time_interval = $fd*1000000;
                if(!event_add($event, $time_interval))
                {
                    return false;
                }
                $this->_eventTimer[$timer_id] = array($func, (array)$args, $event, $flag, $time_interval);
                return $timer_id;
                
            default :
                $fd_key = (int)$fd;
                $real_flag = $flag === self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;
                
                $event = event_new();
                
                if(!event_set($event, $fd, $real_flag, $func, null))
                {
                    return false;
                }
                
                if(!event_base_set($event, $this->_eventBase))
                {
                    return false;
                }
                
                if(!event_add($event))
                {
                    return false;
                }
                
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
                    event_del($this->_allEvents[$fd_key][$flag]);
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
                    event_del($this->_eventSignal[$fd_key]);
                    unset($this->_eventSignal[$fd_key]);
                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                // 这里 fd 为timerid 
                if(isset($this->_eventTimer[$fd]))
                {
                    event_del($this->_eventTimer[$fd][2]);
                    unset($this->_eventTimer[$fd]);
                }
                break;
        }
        return true;
    }
    
    /**
     * 定时器回调
     * @param null $_null
     * @param null $_null
     * @param int $timer_id
     */
    protected function timerCallback($_null, $_null, $timer_id)
    {
        // 如果是连续的定时任务，再把任务加进去
        if($this->_eventTimer[$timer_id][3] === self::EV_TIMER)
        {
            event_add($this->_eventTimer[$timer_id][2], $this->_eventTimer[$timer_id][4]);
        }
        try 
        {
            // 执行任务
            call_user_func_array($this->_eventTimer[$timer_id][0], $this->_eventTimer[$timer_id][1]);
        }
        catch(\Exception $e)
        {
            echo $e;
            exit(250);
        }
        if(isset($this->_eventTimer[$timer_id]) && $this->_eventTimer[$timer_id][3] === self::EV_TIMER_ONCE)
        {
            $this->del($timer_id, self::EV_TIMER_ONCE);
        }
    }
    
    /**
     * 删除所有定时器
     * @return void
     */
    public function clearAllTimer()
    {
        foreach($this->_eventTimer as $task_data)
        {
            event_del($task_data[2]);
        }
        $this->_eventTimer = array();
    }
     

    /**
     * 事件循环
     * @see EventInterface::loop()
     */
    public function loop()
    {
        event_base_loop($this->_eventBase);
    }
}

