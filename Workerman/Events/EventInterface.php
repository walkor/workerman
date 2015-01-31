<?php
namespace Workerman\Events;

interface EventInterface
{
    /**
     * read event
     * @var int
     */
    const EV_READ = 1;
    
    /**
     * write event
     * @var int
     */
    const EV_WRITE = 2;
    
    /**
     * signal
     * @var int
     */
    const EV_SIGNAL = 4;
    
    /**
     * add 
     * @param resource $fd
     * @param int $flag
     * @param callable $func
     * @return bool
     */
    public function add($fd, $flag, $func);
    
    /**
     * del
     * @param resource $fd
     * @param int $flag
     * @return bool
     */
    public function del($fd, $flag);
    
    /**
     * loop
     * @return void
     */
    public function loop();
}
