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
 * select eventloop
 */
class Select implements EventInterface
{
    /**
     * 所有的事件
     * @var array
     */
    public $_allEvents = array();
    
    /**
     * 所有信号事件
     * @var array
     */
    public $_signalEvents = array();
    
    /**
     * 监听这些描述符的读事件
     * @var array
     */
    protected $_readFds = array();
    
    /**
     * 监听这些描述符的写事件
     * @var array
     */
    protected $_writeFds = array();
    
    /**
     * 任务调度器，最大堆
     * {['data':timer_id, 'priority':run_timestamp], ..}
     * @var SplPriorityQueue
     */
    protected $_scheduler = null;
    
    /**
     * 定时任务
     * [[func, args, flag, timer_interval], ..]
     * @var array
     */
    protected $_task = array();
    
    /**
     * 定时器id
     * @var int
     */
    protected $_timerId = 1;
    
    /**
     * select超时时间，单位：微妙
     * @var int
     */
    protected $_selectTimeout = 100000000;
    
    /**
     * 构造函数
     * @return void
     */
    public function __construct()
    {
        // 创建一个管道，放入监听读的描述符集合中，避免空轮询
        $this->channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if($this->channel)
        {
            stream_set_blocking($this->channel[0], 0);
            $this->_readFds[0] = $this->channel[0];
        }
        // 初始化优先队列(最大堆)
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }
    
    /**
     * 添加事件及处理函数
     * @see Events\EventInterface::add()
     */
    public function add($fd, $flag, $func, $args = null)
    {
        switch ($flag)
        {
            case self::EV_READ:
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_readFds[$fd_key] = $fd;
                break;
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_writeFds[$fd_key] = $fd;
                break;
            case self::EV_SIGNAL:
                $fd_key = (int)$fd;
                $this->_signalEvents[$fd_key][$flag] = array($func, $fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                // $fd 为 定时的时间间隔，单位为秒，支持小数，能精确到0.001秒
                $run_time = microtime(true)+$fd;
                $this->_scheduler->insert($this->_timerId, -$run_time);
                $this->_task[$this->_timerId] = array($func, $args, $flag, $fd);
                $this->tick();
                return $this->_timerId++;
        }
        
        return true;
    }
    
    /**
     * 信号处理函数
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        call_user_func_array($this->_signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }
    
    /**
     * 删除某个描述符的某类事件的监听
     * @see Events\EventInterface::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch ($flag)
        {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                return true;
            case self::EV_SIGNAL:
                unset($this->_signalEvents[$fd_key]);
                pcntl_signal($fd, SIG_IGN);
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE;
                // $fd_key为要删除的定时器id，即timerId
                unset($this->_task[$fd_key]);
                return true;
        }
        return false;;
    }
    
    /**
     * 检查是否有可执行的定时任务，有的话执行
     * @return void
     */
    protected function tick()
    {
        while(!$this->_scheduler->isEmpty())
        {
            $scheduler_data = $this->_scheduler->top();
            $timer_id = $scheduler_data['data'];
            $next_run_time = -$scheduler_data['priority'];
            $time_now = microtime(true);
            if($time_now >= $next_run_time)
            {
                $this->_scheduler->extract();
                
                // 如果任务不存在，则是对应的定时器已经删除
                if(!isset($this->_task[$timer_id]))
                {
                    continue;
                }
                
                // 任务数据[func, args, flag, timer_interval]
                $task_data = $this->_task[$timer_id];
                // 如果是持续的定时任务，再把任务加到定时队列
                if($task_data[2] === self::EV_TIMER)
                {
                    $next_run_time = $time_now+$task_data[3];
                    $this->_scheduler->insert($timer_id, -$next_run_time);
                }
                // 尝试执行任务
                try
                {
                    call_user_func_array($task_data[0], $task_data[1]);
                }
                catch(\Exception $e)
                {
                    echo $e;
                }
                continue;
            }
            else
            {
                // 设定超时时间
                $this->_selectTimeout = ($next_run_time - $time_now)*1000000;
                return;
            }
        }
        $this->_selectTimeout = 100000000;
    }
    
    /**
     * 删除所有定时器
     * @return void
     */
    public function clearAllTimer()
    {
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->_task = array();
    }
    
    /**
     * 主循环
     * @see Events\EventInterface::loop()
     */
    public function loop()
    {
        $e = null;
        while (1)
        {
            // 如果有信号，尝试执行信号处理函数
            pcntl_signal_dispatch();
            
            $read = $this->_readFds;
            $write = $this->_writeFds;
            // 等待可读或者可写事件
            @stream_select($read, $write, $e, 0, $this->_selectTimeout);
            
            // 尝试执行定时任务
            if(!$this->_scheduler->isEmpty())
            {
                $this->tick();
            }
            
            // 这些描述符可读，执行对应描述符的读回调函数
            if($read)
            {
                foreach($read as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_READ]))
                    {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_READ][0], array($this->_allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }
            
            // 这些描述符可写，执行对应描述符的写回调函数
            if($write)
            {
                foreach($write as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_WRITE]))
                    {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_WRITE][0], array($this->_allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }
        }
    }
}
