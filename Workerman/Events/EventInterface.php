<?php
namespace Workerman\Events;

interface EventInterface
{
    /**
     * 读事件
     * @var int
     */
    const EV_READ = 1;
    
    /**
     * 写事件
     * @var int
     */
    const EV_WRITE = 2;
    
    /**
     * 信号事件
     * @var int
     */
    const EV_SIGNAL = 4;
    
    /**
     * 连续的定时事件
     * @var int
     */
    const EV_TIMER = 8;
    
    /**
     * 定时一次
     * @var int 
     */
    const EV_TIMER_ONCE = 16;
    
    /**
     * 添加事件回调 
     * @param resource $fd
     * @param int $flag
     * @param callable $func
     * @return bool
     */
    public function add($fd, $flag, $func, $args = null);
    
    /**
     * 删除事件回调
     * @param resource $fd
     * @param int $flag
     * @return bool
     */
    public function del($fd, $flag);
    
    /**
     * 清除所有定时器
     * @return void
     */
    public function clearAllTimer();
    
    /**
     * 事件循环
     * @return void
     */
    public function loop();
}
