<?php
namespace Man\Core\Lib;
/**
 * 
 * 定时任务
 * 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * \Man\Core\Lib\Task::init();
 * \Man\Core\Lib\Task::add(5, array('class', 'method'), array($arg1, $arg2..));
 * <code>
 * </pre>
* @author walkor <walkor@workerman.net>
 */
class Task 
{
    /**
     * 每个任务定时时长及对应的任务（函数）
     * [
     *   run_time => [[$func, $args, $persistent, timelong],[$func, $args, $persistent, timelong],..]],
     *   run_time => [[$func, $args, $persistent, timelong],[$func, $args, $persistent, timelong],..]],
     *   .. 
     * ]
     * @var array
     */
    protected static $tasks = array();
    
    
    /**
     * 初始化任务
     * @return void
     */
    public static function init($event = null)
    {
        pcntl_alarm(1);
        if($event)
        {
            $event->add(SIGALRM, \Man\Core\Events\BaseEvent::EV_SIGNAL, array('\Man\Core\Lib\Task', 'signalHandle'));
        }
        else 
        {
            pcntl_signal(SIGALRM, array('\Man\Core\Lib\Task', 'signalHandle'), false);
        }
    }
    
    /**
     * 捕捉alarm信号
     * @return void
     */
    public static function signalHandle()
    {
        pcntl_alarm(1);
        self::tick();
    }
    
    
    /**
     * 
     * 添加一个任务
     * 
     * @param int $time_long 多长时间运行一次 单位秒
     * @param callback $func 任务运行的函数或方法
     * @param mix $args 任务运行的函数或方法使用的参数
     * @return void
     */
    public static function add($time_long, $func, $args = array(), $persistent = true)
    {
        if($time_long <= 0)
        {
            return false;
        }
        if(!is_callable($func) && class_exists('Log'))
        {
            \Man\Core\Lib\Log::add(var_export($func, true). "not callable\n");
            return false;
        }
        $time_now = time();
        $run_time = $time_now + $time_long;
        if(!isset(self::$tasks[$run_time]))
        {
            self::$tasks[$run_time] = array();
        }
        self::$tasks[$run_time][] = array($func, $args, $persistent, $time_long);
        return true;
    }
    
    
    /**
     * 
     * 定时被调用，用于触发定时任务
     * 
     * @return void
     */
    public static function tick()
    {
        if(empty(self::$tasks))
        {
            return;
        }
        
        $time_now = time();
        foreach (self::$tasks as $run_time=>$task_data)
        {
            // 时间到了就运行一下
            if($time_now >= $run_time)
            {
                foreach($task_data as $index=>$one_task)
                {
                    $task_func = $one_task[0];
                    $task_args = $one_task[1];
                    $persistent = $one_task[2];
                    $time_long = $one_task[3];
                    call_user_func_array($task_func, $task_args);
                    // 持久的放入下一个任务队列
                    if($persistent)
                    {
                        self::add($time_long, $task_func, $task_args);
                    }
                }
                unset(self::$tasks[$run_time]);
            }
        }
    }
    
    /**
     * 删除所有的任务
     */
    public static function delAll()
    {
        self::$tasks = array();
    }
    
}
