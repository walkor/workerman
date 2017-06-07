<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    有个鬼<42765633@qq.com>
 * @copyright 有个鬼<42765633@qq.com>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Events;

use Workerman\Worker;

/**
 * libevent eventloop
 */
class Event implements EventInterface
{
    /**
     * Event base.
     * @var object
     */
    protected $eventBase = null;
    
    /**
     * All listeners for read/write event.
     * @var array
     */
    protected $allEvents = array();
    
    /**
     * Event listeners of signal.
     * @var array
     */
    protected $eventSignal = array();
    
    /**
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected $eventTimer = array();

    /**
     * Timer id.
     * @var int
     */
    protected static $timerId = 1;
    
    /**
     * construct
     * @return void
     */
    public function __construct()
    {
        $this->eventBase = new \EventBase();
    }
   
    /**
     * @see EventInterface::add()
     */
    public function add($fd, $flag, $func, $args=array())
    {
        switch ($flag) {
            case self::EV_SIGNAL:

                $fd_key = (int)$fd;
                $event = \Event::signal($this->eventBase, $fd, $func);
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->eventSignal[$fd_key] = $event;
                return true;

            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:

                $param = array($func, (array)$args, $flag, $fd, self::$timerId);
                $event = new \Event($this->eventBase, -1, \Event::TIMEOUT|\Event::PERSIST, array($this, "timerCallback"), $param);
                if (!$event||!$event->addTimer($fd)) {
                    return false;
                }
                $this->eventTimer[self::$timerId] = $event;
                return self::$timerId++;
                
            default :
                $fd_key = (int)$fd;
                $real_flag = $flag === self::EV_READ ? \Event::READ | \Event::PERSIST : \Event::WRITE | \Event::PERSIST;
                $event = new \Event($this->eventBase, $fd, $real_flag, $func, $fd);
                if (!$event||!$event->add()) {
                    return false;
                }
                $this->allEvents[$fd_key][$flag] = $event;
                return true;
        }
    }
    
    /**
     * @see Events\EventInterface::del()
     */
    public function del($fd, $flag)
    {
        switch ($flag) {

            case self::EV_READ:
            case self::EV_WRITE:

                $fd_key = (int)$fd;
                if (isset($this->allEvents[$fd_key][$flag])) {
                    $this->allEvents[$fd_key][$flag]->del();
                    unset($this->allEvents[$fd_key][$flag]);
                }
                if (empty($this->allEvents[$fd_key])) {
                    unset($this->allEvents[$fd_key]);
                }
                break;

            case  self::EV_SIGNAL:
                $fd_key = (int)$fd;
                if (isset($this->eventSignal[$fd_key])) {
                    $this->eventSignal[$fd_key]->del();
                    unset($this->eventSignal[$fd_key]);
                }
                break;

            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                if (isset($this->eventTimer[$fd])) {
                    $this->eventTimer[$fd]->del();
                    unset($this->eventTimer[$fd]);
                }
                break;
        }
        return true;
    }
    
    /**
     * Timer callback.
     * @param null $fd
     * @param int $what
     * @param int $timer_id
     */
    public function timerCallback($fd, $what, $param)
    {
        $timer_id = $param[4];
        
        if ($param[2] === self::EV_TIMER_ONCE) {
            $this->eventTimer[$timer_id]->del();
            unset($this->eventTimer[$timer_id]);
        }

        try {
            call_user_func_array($param[0], $param[1]);
        } catch (\Exception $e) {
            Worker::log($e);
            exit(250);
        } catch (\Error $e) {
            Worker::log($e);
            exit(250);
        }
    }
    
    /**
     * @see Events\EventInterface::clearAllTimer() 
     * @return void
     */
    public function clearAllTimer()
    {
        foreach ($this->eventTimer as $event) {
            $event->del();
        }
        $this->eventTimer = array();
    }
     

    /**
     * @see EventInterface::loop()
     */
    public function loop()
    {
        $this->eventBase->loop();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {
        foreach ($this->eventSignal as $event) {
            $event->del();
        }
    }
}
