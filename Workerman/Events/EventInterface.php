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
     * 添加事件回调 
     * @param resource $fd
     * @param int $flag
     * @param callable $func
     * @return bool
     */
    public function add($fd, $flag, $func);
    
    /**
     * 删除事件回调
     * @param resource $fd
     * @param int $flag
     * @return bool
     */
    public function del($fd, $flag);
    
    /**
     * 事件循环
     * @return void
     */
    public function loop();
}
