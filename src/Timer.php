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

use Revolt\EventLoop;
use Workerman\Events\EventInterface;
use Workerman\Events\Revolt;
use Workerman\Events\Select;
use Workerman\Events\Swoole;
use Swoole\Coroutine\System;
use Exception;

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
     * @param float    $timeInterval
     * @param callable $func
     * @param mixed    $args
     * @param bool $persistent
     * @return int|bool
     */
    public static function add(float $timeInterval, callable $func, $args = [], bool $persistent = true)
    {
        if ($timeInterval < 0) {
            Worker::safeEcho(new Exception("bad time_interval"));
            return false;
        }

        if ($args === null) {
            $args = [];
        }

        if (self::$event) {
            return $persistent ? self::$event->repeat($timeInterval, $func, $args) : self::$event->delay($timeInterval, $func, $args);
        }
        
        // If not workerman runtime just return.
        if (!Worker::getAllWorkers()) {
            return false;
        }

        if (!\is_callable($func)) {
            Worker::safeEcho(new Exception("not callable"));
            return false;
        }

        if (empty(self::$tasks)) {
            \pcntl_alarm(1);
        }

        $runTime = \time() + $timeInterval;
        if (!isset(self::$tasks[$runTime])) {
            self::$tasks[$runTime] = [];
        }

        self::$timerId = self::$timerId == \PHP_INT_MAX ? 1 : ++self::$timerId;
        self::$status[self::$timerId] = true;
        self::$tasks[$runTime][self::$timerId] = [$func, (array)$args, $persistent, $timeInterval];

        return self::$timerId;
    }

    /**
     * Coroutine sleep.
     *
     * @param float $delay
     * @return null
     */
    public static function sleep(float $delay)
    {
        switch (Worker::$eventLoopClass) {
            // Fiber
            case Revolt::class:
                $suspension = EventLoop::getSuspension();
                static::add($delay, function () use ($suspension) {
                    $suspension->resume();
                }, null, false);
                $suspension->suspend();
                return null;
            // Swoole
            case Swoole::class:
                System::sleep($delay);
                return null;
        }
        // Swow or non coroutine environment
        usleep($delay * 1000 * 1000);
        return null;
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
        $timeNow = \time();
        foreach (self::$tasks as $runTime => $taskData) {
            if ($timeNow >= $runTime) {
                foreach ($taskData as $index => $oneTask) {
                    $taskFunc     = $oneTask[0];
                    $taskArgs     = $oneTask[1];
                    $persistent   = $oneTask[2];
                    $timeInterval = $oneTask[3];
                    try {
                        $taskFunc(...$taskArgs);
                    } catch (\Throwable $e) {
                        Worker::safeEcho($e);
                    }
                    if($persistent && !empty(self::$status[$index])) {
                        $newRunTime = \time() + $timeInterval;
                        if(!isset(self::$tasks[$newRunTime])) self::$tasks[$newRunTime] = [];
                        self::$tasks[$newRunTime][$index] = [$taskFunc, (array)$taskArgs, $persistent, $timeInterval];
                    }
                }
                unset(self::$tasks[$runTime]);
            }
        }
    }

    /**
     * Remove a timer.
     *
     * @param mixed $timerId
     * @return bool
     */
    public static function del($timerId)
    {
        if (self::$event) {
            return self::$event->deleteTimer($timerId);
        }

        foreach(self::$tasks as $runTime => $taskData) 
        {
            if(array_key_exists($timerId, $taskData)) unset(self::$tasks[$runTime][$timerId]);
        }

        if(array_key_exists($timerId, self::$status)) unset(self::$status[$timerId]);

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
