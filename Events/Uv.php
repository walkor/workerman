<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author  爬山虎<blogdaren@163.com>
 * @link    http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;

use Workerman\Worker;

/**
 * libuv eventloop
 */
class Uv implements EventInterface
{
    /**
     * Event Loop.
     * @var object
     */
    protected $_eventLoop = null;

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    protected $_allEvents = array();

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $_eventSignal = array();

    /**
     * All timer event listeners.
     *
     * @var array
     */
    protected $_eventTimer = array();

    /**
     * Timer id.
     *
     * @var int
     */
    protected static $_timerId = 1;

    /**
     * @brief   Constructor
     *
     * @param   object $loop
     *
     * @return  void
     */
    public function __construct(\UVLoop $loop = null)
    {
        if(!extension_loaded('uv')) 
        {
            throw new \Exception(__CLASS__ . ' requires the UV extension, but detected it has NOT been installed yet.');
        } 

        if(empty($loop) || !$loop instanceof \UVLoop) 
        {
            $this->_eventLoop = \uv_default_loop();
            return;
        } 

        $this->_eventLoop = $loop;
    }

    /**
     * @brief    Add a timer
     *
     * @param    resource   $fd
     * @param    int        $flag
     * @param    callback   $func
     * @param    mixed      $args
     *
     * @return   mixed
     */
    public function add($fd, $flag, $func, $args = null)
    {
        switch ($flag) 
        {
            case self::EV_SIGNAL:
                $signalCallback = function($watcher, $socket)use($func, $fd){
                    try {
                        \call_user_func($func, $fd);
                    } catch (\Exception $e) {
                        Worker::stopAll(250, $e);
                    } catch (\Error $e) {
                        Worker::stopAll(250, $e);
                    }
                };
                $signalWatcher = \uv_signal_init(); 
                \uv_signal_start($signalWatcher, $signalCallback, $fd);
                $this->_eventSignal[$fd] = $signalWatcher;
                return true;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $repeat = $flag === self::EV_TIMER_ONCE ? 0 : (int)($fd * 1000);
                $param  = array($func, (array)$args, $flag, $fd, self::$_timerId);
                $timerWatcher = \uv_timer_init(); 
                \uv_timer_start($timerWatcher, 1, $repeat, function($watcher)use($param){
                    call_user_func_array([$this, 'timerCallback'], [$param]);
                });
                $this->_eventTimer[self::$_timerId] = $timerWatcher;
                return self::$_timerId++;
            case self::EV_READ:
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                $ioCallback = function($watcher, $status, $events, $fd)use($func){
                    try {
                        \call_user_func($func, $fd);
                    } catch (\Exception $e) {
                        Worker::stopAll(250, $e);
                    } catch (\Error $e) {
                        Worker::stopAll(250, $e);
                    }
                };
                $ioWatcher = \uv_poll_init($this->_eventLoop, $fd); 
                $real_flag = $flag === self::EV_READ ? \Uv::READABLE : \Uv::WRITABLE;
                \uv_poll_start($ioWatcher, $real_flag, $ioCallback);
                $this->_allEvents[$fd_key][$flag] = $ioWatcher;
                return true;
            default:
                break;
        }
    }

    /**
     * @brief    Remove a timer
     *
     * @param    resource   $fd
     * @param    int        $flag
     *
     * @return   boolean
     */
    public function del($fd, $flag)
    {
        switch ($flag) 
        {
            case self::EV_READ:
            case self::EV_WRITE:
                $fd_key = (int)$fd;
                if (isset($this->_allEvents[$fd_key][$flag])) {
                    $watcher = $this->_allEvents[$fd_key][$flag];
                    \uv_is_active($watcher) && \uv_poll_stop($watcher);
                    unset($this->_allEvents[$fd_key][$flag]);
                }
                if (empty($this->_allEvents[$fd_key])) {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            case self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if (isset($this->_eventSignal[$fd_key])) {
                    $watcher = $this->_eventSignal[$fd_key];
                    \uv_is_active($watcher) && \uv_signal_stop($watcher);
                    unset($this->_eventSignal[$fd_key]);
                }
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                if (isset($this->_eventTimer[$fd])) {
                    $watcher = $this->_eventTimer[$fd];
                    \uv_is_active($watcher) && \uv_timer_stop($watcher);
                    unset($this->_eventTimer[$fd]);
                }
                break;
        }

        return true;
    }

    /**
     * @brief    Timer callback  
     *
     * @param    array  $input
     *
     * @return   void
     */
    public function timerCallback($input)
    {
        if(!is_array($input)) return;

        $timer_id = $input[4];

        if ($input[2] === self::EV_TIMER_ONCE) 
        {
            $watcher = $this->_eventTimer[$timer_id];
            \uv_is_active($watcher) && \uv_timer_stop($watcher);
            unset($this->_eventTimer[$timer_id]);
        }

        try {
            \call_user_func_array($input[0], $input[1]);
        } catch (\Exception $e) {
            Worker::stopAll(250, $e);
        } catch (\Error $e) {
            Worker::stopAll(250, $e);
        }
    }

    /**
     * @brief   Remove all timers
     *
     * @return  void 
     */
    public function clearAllTimer()
    {
        if(!is_array($this->_eventTimer)) return;

        foreach($this->_eventTimer as $watcher) 
        {
            \uv_is_active($watcher) && \uv_timer_stop($watcher);
        }

        $this->_eventTimer = array();
    }

    /**
     * @brief   Start loop   
     *
     * @return  void
     */
    public function loop()
    {
        \Uv_run();
    }

    /**
     * @brief   Destroy loop
     *
     * @return  void
     */
    public function destroy()
    {
        !empty($this->_eventLoop) && \uv_loop_delete($this->_eventLoop);
        $this->_allEvents = [];
    }

    /**
     * @brief   Get timer count
     *
     * @return  integer
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }
}
