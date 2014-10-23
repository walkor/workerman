<?php 
namespace Man\Core\Events;
/**
 * 
 * 事件轮询库的通用接口
 * 其它事件轮询库需要实现这些接口才能在这个server框架中使用
 * 
 * @author walkor <walkor@workerman.net>
 *
 */
interface BaseEvent
{
    /**
     * 数据可读事件
     * @var integer
     */
    const EV_READ = 1;
    
    /**
     * 数据可写事件
     * @var integer
     */
    const EV_WRITE = 2;
    
    /**
     * 信号事件
     * @var integer
     */
    const EV_SIGNAL = 4;
    
    /**
     * 事件添加
     * @param resource $fd
     * @param int $flag
     * @param callable $func
     */
    public function add($fd, $flag, $func);
    
    /**
     * 事件删除
     * @param resource $fd
     * @param int $flag
     */
    public function del($fd, $flag);
    
    /**
     * 轮询
     */
    public function loop();
}

