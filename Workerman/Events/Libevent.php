<?php 
namespace Workerman\Events;
/**
 * libevent
 * @author walkor <walkor@workerman.net>
 */
class Libevent implements EventInterface
{
    /**
     * eventBase
     * @var object
     */
    protected $eventBase = null;
    
    /**
     * 所有的事件
     * @var array
     */
    protected $allEvents = array();
    
    /**
     * 所有的信号事件
     * @var array
     */
    protected $eventSignal = array();
    
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
    public function add($fd, $flag, $func)
    {
        $fd_key = (int)$fd;
        
        if ($flag == self::EV_SIGNAL)
        {
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
        }
        
        $real_flag = $flag == self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;
        
        $this->_allEvents[$fd_key][$flag] = event_new();
        
        if(!event_set($this->_allEvents[$fd_key][$flag], $fd, $real_flag, $func, null))
        {
            return false;
        }
        
        if(!event_base_set($this->_allEvents[$fd_key][$flag], $this->_eventBase))
        {
            return false;
        }
        
        if(!event_add($this->_allEvents[$fd_key][$flag]))
        {
            return false;
        }
        return true;
    }
    
    /**
     * 删除事件
     * @see Events\EventInterface::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch($flag)
        {
            case EventInterface::EV_READ:
            case EventInterface::EV_WRITE:
                if(isset($this->_allEvents[$fd_key][$flag]))
                {
                    event_del($this->_allEvents[$fd_key][$flag]);
                }
                unset($this->_allEvents[$fd_key][$flag]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
            case  EventInterface::EV_SIGNAL:
                if(isset($this->_eventSignal[$fd_key]))
                {
                    event_del($this->_eventSignal[$fd_key]);
                }
                unset($this->_eventSignal[$fd_key]);
        }
        return true;
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

