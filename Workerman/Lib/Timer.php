<?php
namespace Workerman\Lib;
use \Workerman\Events\EventInterface;
use \Exception;

/**
 * 
 * 定时器
 * 
 * <b>example:</b>
 * <pre>
 * <code>
 * Workerman\Lib\Timer::init();
 * Workerman\Lib\Timer::add($time_interval, callback, array($arg1, $arg2..));
 * <code>
 * </pre>
* @author walkor <walkor@workerman.net>
 */
class Timer 
{
    /**
     * [
     *   run_time => [[$func, $args, $persistent, timelong],[$func, $args, $persistent, timelong],..]],
     *   run_time => [[$func, $args, $persistent, timelong],[$func, $args, $persistent, timelong],..]],
     *   .. 
     * ]
     * @var array
     */
    protected static $tasks = array();
    
    
    /**
     * 初始化
     * @return void
     */
    public static function init($event = null)
    {
        if($event)
        {
            $event->add(SIGALRM, EventInterface::EV_SIGNAL, array('\Workerman\Lib\Timer', 'signalHandle'));
        }
        else 
        {
            pcntl_signal(SIGALRM, array('\Workerman\Lib\Timer', 'signalHandle'), false);
        }
    }
    
    /**
     * 信号处理函数，只处理ALARM事件
     * @return void
     */
    public static function signalHandle()
    {
        pcntl_alarm(1);
        self::tick();
    }
    
    
    /**
     * 添加一个定时器
     * @param int $time_interval
     * @param callback $func
     * @param mix $args
     * @return void
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if($time_interval <= 0)
        {
            return false;
        }
        if(!is_callable($func))
        {
            echo new Exception("not callable");
            return false;
        }
        
        if(empty(self::$tasks))
        {
            pcntl_alarm(1);
        }
        
        $time_now = time();
        $run_time = $time_now + $time_interval;
        if(!isset(self::$tasks[$run_time]))
        {
            self::$tasks[$run_time] = array();
        }
        self::$tasks[$run_time][] = array($func, $args, $persistent, $time_interval);
        return true;
    }
    
    
    /**
     * 尝试触发定时回调
     * @return void
     */
    public static function tick()
    {
        if(empty(self::$tasks))
        {
            pcntl_alarm(0);
            return;
        }
        
        $time_now = time();
        foreach (self::$tasks as $run_time=>$task_data)
        {
            if($time_now >= $run_time)
            {
                foreach($task_data as $index=>$one_task)
                {
                    $task_func = $one_task[0];
                    $task_args = $one_task[1];
                    $persistent = $one_task[2];
                    $time_interval = $one_task[3];
                    try 
                    {
                        call_user_func_array($task_func, $task_args);
                    }
                    catch(\Exception $e)
                    {
                        echo $e;
                    }
                    if($persistent)
                    {
                        self::add($time_interval, $task_func, $task_args);
                    }
                }
                unset(self::$tasks[$run_time]);
            }
        }
    }
    
    /**
     * 删除所有定时
     */
    public static function delAll()
    {
        self::$tasks = array();
        pcntl_alarm(0);
    }
}
