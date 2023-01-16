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

use Throwable;
use Workerman\Worker;

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
    protected $readEvents = [];

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    protected $writeEvents = [];

    /**
     * @var array
     */
    protected $exceptEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $signalEvents = [];

    /**
     * Fds waiting for read event.
     *
     * @var array
     */
    protected $readFds = [];

    /**
     * Fds waiting for write event.
     *
     * @var array
     */
    protected $writeFds = [];

    /**
     * Fds waiting for except event.
     *
     * @var array
     */
    protected $exceptFds = [];

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
    protected $eventTimer = [];

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
     * Construct.
     */
    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->scheduler = new \SplPriorityQueue();
        $this->scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $timer_id = $this->timerId++;
        $run_time = \microtime(true) + $delay;
        $this->scheduler->insert($timer_id, -$run_time);
        $this->eventTimer[$timer_id] = [$func, (array)$args];
        $select_timeout = ($run_time - \microtime(true)) * 1000000;
        $select_timeout = $select_timeout <= 0 ? 1 : (int)$select_timeout;
        if ($this->selectTimeout > $select_timeout) {
            $this->selectTimeout = $select_timeout;
        }
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $delay, $func, $args)
    {
        $timer_id = $this->timerId++;
        $run_time = \microtime(true) + $delay;
        $this->scheduler->insert($timer_id, -$run_time);
        $this->eventTimer[$timer_id] = [$func, (array)$args, $delay];
        $select_timeout = ($run_time - \microtime(true)) * 1000000;
        $select_timeout = $select_timeout <= 0 ? 1 : (int)$select_timeout;
        if ($this->selectTimeout > $select_timeout) {
            $this->selectTimeout = $select_timeout;
        }
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->eventTimer[$timer_id])) {
            unset($this->eventTimer[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $count = \count($this->readFds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fd_key = (int)$stream;
        $this->readEvents[$fd_key] = $func;
        $this->readFds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        unset($this->readEvents[$fd_key], $this->readFds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $count = \count($this->writeFds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fd_key = (int)$stream;
        $this->writeEvents[$fd_key] = $func;
        $this->writeFds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        unset($this->writeEvents[$fd_key], $this->writeFds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onExcept($stream, $func)
    {
        $fd_key = (int)$stream;
        $this->exceptEvents[$fd_key] = $func;
        $this->exceptFds[$fd_key] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offExcept($stream)
    {
        $fd_key = (int)$stream;
        unset($this->exceptEvents[$fd_key], $this->exceptFds[$fd_key]);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return null;
        }
        $this->signalEvents[$signal] = $func;
        \pcntl_signal($signal, [$this, 'signalHandler']);
    }

    /**
     * {@inheritdoc}
     */
    public function offsignal($signal)
    {
        unset($this->signalEvents[$signal]);
        \pcntl_signal($signal, SIG_IGN);
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        $this->signalEvents[$signal]($signal);
    }

    /**
     * Tick for timer.
     *
     * @return void
     */
    protected function tick()
    {
        $tasks_to_insert = [];
        while (!$this->scheduler->isEmpty()) {
            $scheduler_data = $this->scheduler->top();
            $timer_id = $scheduler_data['data'];
            $next_run_time = -$scheduler_data['priority'];
            $time_now = \microtime(true);
            $this->selectTimeout = (int)(($next_run_time - $time_now) * 1000000);
            if ($this->selectTimeout <= 0) {
                $this->scheduler->extract();

                if (!isset($this->eventTimer[$timer_id])) {
                    continue;
                }

                // [func, args, timer_interval]
                $task_data = $this->eventTimer[$timer_id];
                if (isset($task_data[2])) {
                    $next_run_time = $time_now + $task_data[2];
                    $tasks_to_insert[] = [$timer_id, -$next_run_time];
                } else {
                    unset($this->eventTimer[$timer_id]);
                }
                try {
                    $task_data[0]($task_data[1]);
                } catch (Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            } else {
                break;
            }
        }
        foreach ($tasks_to_insert as $item) {
            $this->scheduler->insert($item[0], $item[1]);
        }
        if (!$this->scheduler->isEmpty()) {
            $scheduler_data = $this->scheduler->top();
            $next_run_time = -$scheduler_data['priority'];
            $time_now = \microtime(true);
            $this->selectTimeout = \max((int)(($next_run_time - $time_now) * 1000000), 0);
            return;
        }
        $this->selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        $this->scheduler = new \SplPriorityQueue();
        $this->scheduler->setExtractFlags(\SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = [];
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

            $read = $this->readFds;
            $write = $this->writeFds;
            $except = $this->exceptFds;

            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                try {
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable $e) {
                }

            } else {
                $this->selectTimeout >= 1 && usleep($this->selectTimeout);
            }

            if (!$this->scheduler->isEmpty()) {
                $this->tick();
            }

            foreach ($read as $fd) {
                $fd_key = (int)$fd;
                if (isset($this->readEvents[$fd_key])) {
                    $this->readEvents[$fd_key]($fd);
                }
            }

            foreach ($write as $fd) {
                $fd_key = (int)$fd;
                if (isset($this->writeEvents[$fd_key])) {
                    $this->writeEvents[$fd_key]($fd);
                }
            }

            foreach ($except as $fd) {
                $fd_key = (int)$fd;
                if (isset($this->exceptEvents[$fd_key])) {
                    $this->exceptEvents[$fd_key]($fd);
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
        foreach ($this->signalEvents as $signal => $item) {
            $this->offsignal($signal);
        }
        $this->readFds = $this->writeFds = $this->exceptFds = $this->readEvents
            = $this->writeEvents = $this->exceptEvents = $this->signalEvents = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->eventTimer);
    }

}
