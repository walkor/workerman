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
namespace Workerman\Events;

/**
 * select eventloop
 */
class Select implements EventInterface
{
    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    public $allEvents = array();

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    public $signalEvents = array();

    /**
     * Fds waiting for read event.
     *
     * @var array
     */
    protected $readFds = array();

    /**
     * Fds waiting for write event.
     *
     * @var array
     */
    protected $writeFds = array();

    /**
     * Fds waiting for except event.
     *
     * @var array
     */
    protected $exceptFds = array();

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var \SplPriorityQueue
     */
    protected $scheduler = null;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    protected $eventTimer = array();

    /**
     * Timer id.
     *
     * @var int
     */
    protected $timerId = 1;

    /**
     * Select timeout.
     *
     * @var int
     */
    protected $selectTimeout = 100000000;

    /**
     * Paired socket channels
     *
     * @var array
     */
    protected $channel = array();

    /**
     * Construct.
     */
    public function __construct()
    {
        // Create a pipeline and put into the collection of the read to read the descriptor to avoid empty polling.
        $this->channel = stream_socket_pair(DIRECTORY_SEPARATOR === '/' ? STREAM_PF_UNIX : STREAM_PF_INET,
            STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if($this->channel) {
            stream_set_blocking($this->channel[0], 0);
            $this->readFds[0] = $this->channel[0];
        }
        // Init SplPriorityQueue.
        $this->scheduler = new \SplPriorityQueue();
        $this->scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function add($fd, $flag, $func, $args = array())
    {
        switch ($flag) {
            case self::EV_READ:
                $fd_key                           = (int)$fd;
                $this->allEvents[$fd_key][$flag] = array($func, $fd);
                $this->readFds[$fd_key]          = $fd;
                break;
            case self::EV_WRITE:
                $fd_key                           = (int)$fd;
                $this->allEvents[$fd_key][$flag] = array($func, $fd);
                $this->writeFds[$fd_key]         = $fd;
                break;
            case self::EV_EXCEPT:
                $fd_key = (int)$fd;
                $this->allEvents[$fd_key][$flag] = array($func, $fd);
                $this->exceptFds[$fd_key] = $fd;
                break;
            case self::EV_SIGNAL:
                // Windows not support signal.
                if(DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                $fd_key                              = (int)$fd;
                $this->signalEvents[$fd_key][$flag] = array($func, $fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $run_time = microtime(true) + $fd;
                $this->scheduler->insert($this->timerId, -$run_time);
                $this->eventTimer[$this->timerId] = array($func, (array)$args, $flag, $fd);
                $this->tick();
                return $this->timerId++;
        }

        return true;
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        call_user_func_array($this->signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }

    /**
     * {@inheritdoc}
     */
    public function del($fd, $flag)
    {
        $fd_key = (int)$fd;
        switch ($flag) {
            case self::EV_READ:
                unset($this->allEvents[$fd_key][$flag], $this->readFds[$fd_key]);
                if (empty($this->allEvents[$fd_key])) {
                    unset($this->allEvents[$fd_key]);
                }
                return true;
            case self::EV_WRITE:
                unset($this->allEvents[$fd_key][$flag], $this->writeFds[$fd_key]);
                if (empty($this->allEvents[$fd_key])) {
                    unset($this->allEvents[$fd_key]);
                }
                return true;
            case self::EV_EXCEPT:
                unset($this->allEvents[$fd_key][$flag], $this->exceptFds[$fd_key]);
                if(empty($this->allEvents[$fd_key]))
                {
                    unset($this->allEvents[$fd_key]);
                }
                return true;
            case self::EV_SIGNAL:
                if(DIRECTORY_SEPARATOR !== '/') {
                    return false;
                }
                unset($this->signalEvents[$fd_key]);
                pcntl_signal($fd, SIG_IGN);
                break;
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE;
                unset($this->eventTimer[$fd_key]);
                return true;
        }
        return false;
    }

    /**
     * Tick for timer.
     *
     * @return void
     */
    protected function tick()
    {
        while (!$this->scheduler->isEmpty()) {
            $scheduler_data       = $this->scheduler->top();
            $timer_id             = $scheduler_data['data'];
            $next_run_time        = -$scheduler_data['priority'];
            $time_now             = microtime(true);
            $this->selectTimeout = ($next_run_time - $time_now) * 1000000;
            if ($this->selectTimeout <= 0) {
                $this->scheduler->extract();

                if (!isset($this->eventTimer[$timer_id])) {
                    continue;
                }

                // [func, args, flag, timer_interval]
                $task_data = $this->eventTimer[$timer_id];
                if ($task_data[2] === self::EV_TIMER) {
                    $next_run_time = $time_now + $task_data[3];
                    $this->scheduler->insert($timer_id, -$next_run_time);
                }
                call_user_func_array($task_data[0], $task_data[1]);
                if (isset($this->eventTimer[$timer_id]) && $task_data[2] === self::EV_TIMER_ONCE) {
                    $this->del($timer_id, self::EV_TIMER_ONCE);
                }
                continue;
            }
            return;
        }
        $this->selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function clearAllTimer()
    {
        $this->scheduler = new \SplPriorityQueue();
        $this->scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = array();
    }

    /**
     * {@inheritdoc}
     */
    public function loop()
    {
        $e = null;
        while (1) {
            if(DIRECTORY_SEPARATOR === '/') {
                // Calls signal handlers for pending signals
                pcntl_signal_dispatch();
            }

            $read  = $this->readFds;
            $write = $this->writeFds;
            $except = $this->writeFds;

            // Waiting read/write/signal/timeout events.
            $ret = @stream_select($read, $write, $except, 0, $this->selectTimeout);

            if (!$this->scheduler->isEmpty()) {
                $this->tick();
            }

            if (!$ret) {
                continue;
            }

            if ($read) {
                foreach ($read as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->allEvents[$fd_key][self::EV_READ])) {
                        call_user_func_array($this->allEvents[$fd_key][self::EV_READ][0],
                            array($this->allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }

            if ($write) {
                foreach ($write as $fd) {
                    $fd_key = (int)$fd;
                    if (isset($this->allEvents[$fd_key][self::EV_WRITE])) {
                        call_user_func_array($this->allEvents[$fd_key][self::EV_WRITE][0],
                            array($this->allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }

            if($except) {
                foreach($except as $fd) {
                    $fd_key = (int) $fd;
                    if(isset($this->allEvents[$fd_key][self::EV_EXCEPT])) {
                        call_user_func_array($this->allEvents[$fd_key][self::EV_EXCEPT][0],
                            array($this->allEvents[$fd_key][self::EV_EXCEPT][1]));
                    }
                }
            }
        }
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function destroy()
    {

    }
}
