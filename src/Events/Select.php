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
    protected $_readEvents = [];

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    protected $_writeEvents = [];

    /**
     * @var array
     */
    protected $_exceptEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $_signalEvents = [];

    /**
     * Fds waiting for read event.
     *
     * @var array
     */
    protected $_readFds = [];

    /**
     * Fds waiting for write event.
     *
     * @var array
     */
    protected $_writeFds = [];

    /**
     * Fds waiting for except event.
     *
     * @var array
     */
    protected $_exceptFds = [];

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var \SplPriorityQueue
     */
    protected $_scheduler = null;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    protected $_eventTimer = [];

    /**
     * Timer id.
     *
     * @var int
     */
    protected $_timerId = 1;

    /**
     * Select timeout.
     *
     * @var int
     */
    protected $_selectTimeout = 100000000;

    /**
     * Construct.
     */
    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $timer_id = $this->_timerId++;
        $run_time = \microtime(true) + $delay;
        $this->_scheduler->insert($timer_id, -$run_time);
        $this->_eventTimer[$timer_id] = [$func, (array)$args];
        $select_timeout = ($run_time - \microtime(true)) * 1000000;
        $select_timeout = $select_timeout <= 0 ? 1 : (int)$select_timeout;
        if ($this->_selectTimeout > $select_timeout) {
            $this->_selectTimeout = $select_timeout;
        }
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $delay, $func, $args)
    {
        $timer_id = $this->_timerId++;
        $run_time = \microtime(true) + $delay;
        $this->_scheduler->insert($timer_id, -$run_time);
        $this->_eventTimer[$timer_id] = [$func, (array)$args, $delay];
        $select_timeout = ($run_time - \microtime(true)) * 1000000;
        if ($this->_selectTimeout > $select_timeout) {
            $this->_selectTimeout = $select_timeout;
        }
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->_eventTimer[$timer_id])) {
            unset($this->_eventTimer[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $count = \count($this->_readFds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fd_key = (int)$stream;
        $this->_readEvents[$fd_key] = $func;
        $this->_readFds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        unset($this->_readEvents[$fd_key], $this->_readFds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $count = \count($this->_writeFds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fd_key = (int)$stream;
        $this->_writeEvents[$fd_key] = $func;
        $this->_writeFds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        unset($this->_writeEvents[$fd_key], $this->_writeFds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onExcept($stream, $func)
    {
        $fd_key = (int)$stream;
        $this->_exceptEvents[$fd_key] = $func;
        $this->_exceptFds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offExcept($stream)
    {
        $fd_key = (int)$stream;
        unset($this->_exceptEvents[$fd_key], $this->_exceptFds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return null;
        }
        $this->_signalEvents[$signal] = $func;
        \pcntl_signal($signal, [$this, 'signalHandler']);
    }

    /**
     * {@inheritdoc}
     */
    public function offsignal($signal)
    {
        unset($this->_signalEvents[$signal]);
        \pcntl_signal($signal, SIG_IGN);
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        $this->_signalEvents[$signal]($signal);
    }

    /**
     * Tick for timer.
     *
     * @return void
     */
    protected function tick()
    {
        while (!$this->_scheduler->isEmpty()) {
            $scheduler_data = $this->_scheduler->top();
            $timer_id = $scheduler_data['data'];
            $next_run_time = -$scheduler_data['priority'];
            $time_now = \microtime(true);
            $this->_selectTimeout = (int)($next_run_time - $time_now) * 1000000;
            if ($this->_selectTimeout <= 0) {
                $this->_scheduler->extract();

                if (!isset($this->_eventTimer[$timer_id])) {
                    continue;
                }

                // [func, args, timer_interval]
                $task_data = $this->_eventTimer[$timer_id];
                if (isset($task_data[2])) {
                    $next_run_time = $time_now + $task_data[2];
                    $this->_scheduler->insert($timer_id, -$next_run_time);
                } else {
                    unset($this->_eventTimer[$timer_id]);
                }
                $task_data[0]($task_data[1]);
                continue;
            }
            return;
        }
        $this->_selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        $this->_scheduler = new \SplPriorityQueue();
        $this->_scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->_eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        while (1) {
            if (\DIRECTORY_SEPARATOR === '/') {
                // Calls signal handlers for pending signals
                \pcntl_signal_dispatch();
            }

            $read = $this->_readFds;
            $write = $this->_writeFds;
            $except = $this->_exceptFds;

            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                try {
                    @stream_select($read, $write, $except, 0, $this->_selectTimeout);
                } catch (\Throwable $e) {
                }

            } else {
                $this->_selectTimeout >= 1 && usleep($this->_selectTimeout);
                $ret = false;
            }

            if (!$this->_scheduler->isEmpty()) {
                $this->tick();
            }

            foreach ($read as $fd) {
                $fd_key = (int)$fd;
                if (isset($this->_readEvents[$fd_key])) {
                    $this->_readEvents[$fd_key]($fd);
                }
            }

            foreach ($write as $fd) {
                $fd_key = (int)$fd;
                if (isset($this->_writeEvents[$fd_key])) {
                    $this->_writeEvents[$fd_key]($fd);
                }
            }

            foreach ($except as $fd) {
                $fd_key = (int)$fd;
                if (isset($this->_exceptEvents[$fd_key])) {
                    $this->_exceptEvents[$fd_key]($fd);
                }
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->deleteAllTimer();
        foreach ($this->_signalEvents as $signal => $item) {
            $this->offsignal($signal);
        }
        $this->_readFds = $this->_writeFds = $this->_exceptFds = $this->_readEvents
            = $this->_writeEvents = $this->_exceptEvents = $this->_signalEvents = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }

}
