<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    JingKe Wu<hi.laow@gmail.com>
 * @copyright JingKe Wu<hi.laow@gmail.com>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Lib\Timer;

use Exception;

/**
 * Timer.
 *
 * example:
 * Workerman\Lib\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Swoole implements TimerInterface
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
    protected static $_tasks = array();

    /**
     * Init.
     *
     * @param \Workerman\Events\EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        static::$_tasks = array();
    }

    /**
     * Add a timer.
     *
     * @param float $time_interval 单位s
     * @param callback $func
     * @param mixed $args
     * @param bool $persistent
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        if ($time_interval <= 0) {
            echo new Exception("bad time_interval");
            return false;
        }

        if (!is_callable($func)) {
            echo new Exception("not callable");
            return false;
        }
        if ($args === null) {
            $args = array();
        }
        $real_func = function () use ($func, $args,$persistent, &$timerid) {
            call_user_func_array($func, $args);
            if ($persistent === false) {
                unset(self::$_tasks[$timerid]);
            }
        };
        $real_time = $time_interval*1000;
        if ($persistent === true) {
            $timerid = swoole_timer_tick($real_time, $real_func);
        } else {
            $timerid = swoole_timer_after($real_time, $real_func);
        }
        if (!isset(self::$_tasks)) {
            self::$_tasks = array();
        }
        self::$_tasks[$timerid] = array($func, (array)$args, $persistent, $real_time);
        return $timerid;
    }


    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        if (self::$_tasks[$timer_id]) {
            unset(self::$_tasks[$timer_id]);
            return swoole_timer_clear($timer_id);
        }

        return false;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll()
    {
        if (count(self::$_tasks) > 0) {
            foreach (self::$_tasks as $k => $v) {
                swoole_timer_clear($k);
            }
            self::$_tasks = array();
        }
    }
}
