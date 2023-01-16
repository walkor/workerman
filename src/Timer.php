<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman;

use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Worker;
use \Exception;

/**
 * Timer.
 */
class Timer
{
    /**
     * Tasks that based on ALARM signal.
     * [
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   run_time => [[$func, $args, $persistent, time_interval],[$func, $args, $persistent, time_interval],..]],
     *   ..
     * ]
     *
     * @var array
     */
    protected static $tasks = [];

    /**
     * event
     *
     * @var Select
     */
    protected static $event = null;

    /**
     * timer id
     *
     * @var int
     */
    protected static $timerId = 0;

    /**
     * timer status
     * [
     *   timer_id1 => bool,
     *   timer_id2 => bool,
     *   ....................,
     * ]
     *
     * @var array
     */
    protected static $status = [];

    /**
     * Init.
     *
     * @param EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        if ($event) {
            self::$event = $event;
            return;
        }
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(\SIGALRM, ['\Workerman\Timer', 'signalHandle'], false);
        }
    }

    /**
     * ALARM signal handler.
     *
     * @return void
     */
    public static function signalHandle()
    {
        if (!self::$event) {
            \pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * Add a timer.
     *
     * @param float    $time_interval
     * @param callable $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int|bool
     */
    public static function add(float $time_interval, $func, $args = [], $persistent = true)
    {
        if ($time_interval < 0) {
            Worker::safeEcho(new Exception("bad time_interval"));
            return false;
        }

        if ($args === null) {
            $args = [];
        }

        if (self::$event) {
            return $persistent ? self::$event->repeat($time_interval, $func, $args) : self::$event->delay($time_interval, $func, $args);
        }
        
        // If not workerman runtime just return.
        if (!Worker::getAllWorkers()) {
            return;
        }

        if (!\is_callable($func)) {
            Worker::safeEcho(new Exception("not callable"));
            return false;
        }

        if (empty(self::$tasks)) {
            \pcntl_alarm(1);
        }

        $run_time = \time() + $time_interval;
        if (!isset(self::$tasks[$run_time])) {
            self::$tasks[$run_time] = [];
        }

        self::$timerId = self::$timerId == \PHP_INT_MAX ? 1 : ++self::$timerId;
        self::$status[self::$timerId] = true;
        self::$tasks[$run_time][self::$timerId] = [$func, (array)$args, $persistent, $time_interval];

        return self::$timerId;
    }

    /**
     * @param float $delay
     * @param $func
     * @param array $args
     * @return bool|int
     */
    public static function delay(float $delay, $func, $args = [])
    {
        return static::add($delay, $func, $args, false);
    }


    /**
     * Tick.
     *
     * @return void
     */
    public static function tick()
    {
        if (empty(self::$tasks)) {
            \pcntl_alarm(0);
            return;
        }
        $time_now = \time();
        foreach (self::$tasks as $run_time => $task_data) {
            if ($time_now >= $run_time) {
                foreach ($task_data as $index => $one_task) {
                    $task_func     = $one_task[0];
                    $task_args     = $one_task[1];
                    $persistent    = $one_task[2];
                    $time_interval = $one_task[3];
                    try {
                        $task_func(...$task_args);
                    } catch (\Throwable $e) {
                        Worker::safeEcho($e);
                    }
                    if($persistent && !empty(self::$status[$index])) {
                        $new_run_time = \time() + $time_interval;
                        if(!isset(self::$tasks[$new_run_time])) self::$tasks[$new_run_time] = [];
                        self::$tasks[$new_run_time][$index] = [$task_func, (array)$task_args, $persistent, $time_interval];
                    }
                }
                unset(self::$tasks[$run_time]);
            }
        }
    }

    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$event) {
            return self::$event->deleteTimer($timer_id);
        }

        foreach(self::$tasks as $run_time => $task_data) 
        {
            if(array_key_exists($timer_id, $task_data)) unset(self::$tasks[$run_time][$timer_id]);
        }

        if(array_key_exists($timer_id, self::$status)) unset(self::$status[$timer_id]);

        return true;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll()
    {
        self::$tasks = self::$status = [];
        if (\function_exists('pcntl_alarm')) {
            \pcntl_alarm(0);
        }
        if (self::$event) {
            self::$event->deleteAllTimer();
        }
    }
}
