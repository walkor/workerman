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
namespace Workerman\Lib;

use Workerman\Events\EventInterface;
use Exception;

/**
 * Timer.
 *
 * example:
 * Workerman\Lib\Timer::add($time_interval, callback, array($arg1, $arg2..));
 */
class Timer
{
    /**
     *  Available Timer engines.
     *
     * @var array
     */
    protected static $_engines = array(
      'swoole'  => '\Workerman\Lib\Timer\Swoole',
      'pcntl'   => '\Workerman\Lib\Timer\Pcntl',
    );


    /**
     * timerName
     *
     * @var string
     */
    public static $timerName= '';

    /**
     * timerClass
     *
     * @var string
     */
    public static $timerClass = '';

    /**
     * Init.
     *
     * @param \Workerman\Events\EventInterface $event
     * @return void
     */
    public static function init($event = null)
    {
        $timerClass =   self::getTimerEngineName();
        $timerClass::init($event);
    }

    protected static function getTimerEngineName()
    {
        if (static::$timerClass) {
            return static::$timerClass;
        }

        $engine = '';
        foreach (static::$_engines as $name=>$class) {
            if (extension_loaded($name)) {
                static::$timerClass = $class;
                static::$timerName  = $name;

                break;
            }
        }
        return static::$timerClass;
    }



    public static function getEngineName()
    {
        return static::$timerName;
    }

    /**
     * Add a timer.
     *
     * @param float    $time_interval
     * @param callable $func
     * @param mixed    $args
     * @param bool     $persistent
     * @return int/false
     */
    public static function add($time_interval, $func, $args = array(), $persistent = true)
    {
        $timerClass =   self::getTimerEngineName();
        return $timerClass::add($time_interval, $func, $args, $persistent) ;
    }

    /**
     * Remove a timer.
     *
     * @param mixed $timer_id
     * @return bool
     */
    public static function del($timer_id)
    {
        $timerClass =   self::getTimerEngineName();
        return $timerClass::del($timer_id);
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll()
    {
        $timerClass =   self::getTimerEngineName();
        return $timerClass::delAll();
    }
}
