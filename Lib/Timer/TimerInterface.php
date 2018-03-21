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

interface TimerInterface
{
    /**
     * Init.
     *
     * @param  $event
     * @return void
     */
    public static function init($event = null);


    /**
     * Add a timer.
     *
     * @param float    $time_interval
     * @param callable $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true);
    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id);


    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll();
}
