<?php 
namespace Man\Core\Events;
/**
 * 
 * 事件轮询库的通用接口
 * 其它事件轮询库需要实现这些接口才能在这个server框架中使用
 * 目前 Select libevent libev libuv这些事件轮询库已经封装好这些接口可以直接使用
 * 
 * @author walkor <worker-man@qq.com>
 *
 */
interface BaseEvent
{
    // 数据可读事件
    const EV_READ = 1;
    
    // 数据可写事件
    const EV_WRITE = 2;
    
    // 信号事件
    const EV_SIGNAL = 4;
    
    // 事件添加
    public function add($fd, $flag, $func);
    
    // 事件删除
    public function del($fd, $flag);
    
    // 轮询事件
    public function loop();
}

