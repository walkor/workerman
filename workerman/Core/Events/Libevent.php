<?php 
namespace Man\Core\Events;
require_once WORKERMAN_ROOT_DIR . 'Core/Events/interfaces.php';
/**
 * 
 * libevent事件轮询库的封装
 * 
 * @author walkor <walkor@workerman.net>
 */
class Libevent implements BaseEvent
{
    /**
     * eventBase实例
     * @var object
     */
    public $eventBase = null;
    
    /**
     * 记录所有监听事件 
     * @var array
     */
    public $allEvents = array();
    
    /**
     * 记录信号回调函数
     * @var array
     */
    public $eventSignal = array();
    
    /**
     * 初始化eventBase
     * @return void
     */
    public function __construct()
    {
        $this->eventBase = event_base_new();
    }
   
    /**
     * 添加事件
     * @see \Man\Core\Events\BaseEvent::add()
     */
    public function add($fd, $flag, $func, $args = null)
    {
        $fd_key = (int)$fd;
        
        if ($flag == self::EV_SIGNAL)
        {
            $real_flag = EV_SIGNAL | EV_PERSIST;
            // 创建一个用于监听的event
            $this->eventSignal[$fd_key] = event_new();
            // 设置监听处理函数
            if(!event_set($this->eventSignal[$fd_key], $fd, $real_flag, $func, $args))
            {
                return false;
            }
            // 设置event base
            if(!event_base_set($this->eventSignal[$fd_key], $this->eventBase))
            {
                return false;
            }
            // 添加事件
            if(!event_add($this->eventSignal[$fd_key]))
            {
                return false;
            }
            return true;
        }
        
        $real_flag = $flag == self::EV_READ ? EV_READ | EV_PERSIST : EV_WRITE | EV_PERSIST;
        
        // 创建一个用于监听的event
        $this->allEvents[$fd_key][$flag] = event_new();
        
        // 设置监听处理函数
        if(!event_set($this->allEvents[$fd_key][$flag], $fd, $real_flag, $func, $args))
        {
            return false;
        }
        
        // 设置event base
        if(!event_base_set($this->allEvents[$fd_key][$flag], $this->eventBase))
        {
            return false;
        }
        
        // 添加事件
        if(!event_add($this->allEvents[$fd_key][$flag]))
        {
            return false;
        }
        
        return true;
    }
    
    /**
     * 删除fd的某个事件
     * @see \Man\Core\Events\BaseEvent::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch($flag)
        {
            // 读事件
            case \Man\Core\Events\BaseEvent::EV_READ:
            case \Man\Core\Events\BaseEvent::EV_WRITE:
                if(isset($this->allEvents[$fd_key][$flag]))
                {
                    event_del($this->allEvents[$fd_key][$flag]);
                }
                unset($this->allEvents[$fd_key][$flag]);
                if(empty($this->allEvents[$fd_key]))
                {
                    unset($this->allEvents[$fd_key]);
                }
            case  \Man\Core\Events\BaseEvent::EV_SIGNAL:
                if(isset($this->eventSignal[$fd_key]))
                {
                    event_del($this->eventSignal[$fd_key]);
                }
                unset($this->eventSignal[$fd_key]);
        }
        return true;
    }

    /**
     * 轮训主循环
     * @see \Man\Core\Events\BaseEvent::loop()
     */
    public function loop()
    {
        event_base_loop($this->eventBase);
    }
}

