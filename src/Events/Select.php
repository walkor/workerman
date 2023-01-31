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

use SplPriorityQueue;
use Throwable;

/**
 * select eventloop
 */
class Select implements EventInterface
{
    /**
     * Running.
     * @var bool
     */
    protected bool $running = true;

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    protected array $readEvents = [];

    /**
     * All listeners for read/write event.
     *
     * @var array
     */
    protected array $writeEvents = [];

    /**
     * @var array
     */
    protected array $exceptEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected array $signalEvents = [];

    /**
     * Fds waiting for read event.
     *
     * @var array
     */
    protected array $readFds = [];

    /**
     * Fds waiting for write event.
     *
     * @var array
     */
    protected array $writeFds = [];

    /**
     * Fds waiting for except event.
     *
     * @var array
     */
    protected array $exceptFds = [];

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var SplPriorityQueue
     */
    protected SplPriorityQueue $scheduler;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * Timer id.
     *
     * @var int
     */
    protected int $timerId = 1;

    /**
     * Select timeout.
     *
     * @var int
     */
    protected int $selectTimeout = 100000000;

    /**
     * @var ?callable
     */
    protected $errorHandler = null;

    /**
     * Construct.
     */
    public function __construct()
    {
        // Init SplPriorityQueue.
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = \microtime(true) + $delay;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args];
        $selectTimeout = ($runTime - \microtime(true)) * 1000000;
        $selectTimeout = $selectTimeout <= 0 ? 1 : (int)$selectTimeout;
        if ($this->selectTimeout > $selectTimeout) {
            $this->selectTimeout = $selectTimeout;
        }
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = \microtime(true) + $interval;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args, $interval];
        $selectTimeout = ($runTime - \microtime(true)) * 1000000;
        $selectTimeout = $selectTimeout <= 0 ? 1 : (int)$selectTimeout;
        if ($this->selectTimeout > $selectTimeout) {
            $this->selectTimeout = $selectTimeout;
        }
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func)
    {
        $count = \count($this->readFds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fdKey = (int)$stream;
        $this->readEvents[$fdKey] = $func;
        $this->readFds[$fdKey] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            unset($this->readEvents[$fdKey], $this->readFds[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func)
    {
        $count = \count($this->writeFds);
        if ($count >= 1024) {
            echo "Warning: system call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.\n";
        } else if (\DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            echo "Warning: system call select exceeded the maximum number of connections 256.\n";
        }
        $fdKey = (int)$stream;
        $this->writeEvents[$fdKey] = $func;
        $this->writeFds[$fdKey] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            unset($this->writeEvents[$fdKey], $this->writeFds[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onExcept($stream, $func)
    {
        $fdKey = (int)$stream;
        $this->exceptEvents[$fdKey] = $func;
        $this->exceptFds[$fdKey] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offExcept($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->exceptEvents[$fdKey])) {
            unset($this->exceptEvents[$fdKey], $this->exceptFds[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func)
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        $this->signalEvents[$signal] = $func;
        \pcntl_signal($signal, [$this, 'signalHandler']);
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (\DIRECTORY_SEPARATOR !== '/') {
            return false;
        }
        \pcntl_signal($signal, SIG_IGN);
        if (isset($this->signalEvents[$signal])) {
            unset($this->signalEvents[$signal]);
            return true;
        }
        return false;
    }

    /**
     * Signal handler.
     *
     * @param int $signal
     */
    public function signalHandler(int $signal)
    {
        $this->signalEvents[$signal]($signal);
    }

    /**
     * Tick for timer.
     *
     * @return void
     * @throws Throwable
     */
    protected function tick()
    {
        $tasksToInsert = [];
        while (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $timerId = $schedulerData['data'];
            $nextRunTime = -$schedulerData['priority'];
            $timeNow = \microtime(true);
            $this->selectTimeout = (int)(($nextRunTime - $timeNow) * 1000000);
            if ($this->selectTimeout <= 0) {
                $this->scheduler->extract();

                if (!isset($this->eventTimer[$timerId])) {
                    continue;
                }

                // [func, args, timer_interval]
                $taskData = $this->eventTimer[$timerId];
                if (isset($taskData[2])) {
                    $nextRunTime = $timeNow + $taskData[2];
                    $tasksToInsert[] = [$timerId, -$nextRunTime];
                } else {
                    unset($this->eventTimer[$timerId]);
                }
                try {
                    $taskData[0](...$taskData[1]);
                } catch (Throwable $e) {
                    $this->error($e);
                    continue;
                }
            } else {
                break;
            }
        }
        foreach ($tasksToInsert as $item) {
            $this->scheduler->insert($item[0], $item[1]);
        }
        if (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $nextRunTime = -$schedulerData['priority'];
            $timeNow = \microtime(true);
            $this->selectTimeout = \max((int)(($nextRunTime - $timeNow) * 1000000), 0);
            return;
        }
        $this->selectTimeout = 100000000;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        while ($this->running) {
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
                $fdKey = (int)$fd;
                if (isset($this->readEvents[$fdKey])) {
                    $this->readEvents[$fdKey]($fd);
                }
            }

            foreach ($write as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->writeEvents[$fdKey])) {
                    $this->writeEvents[$fdKey]($fd);
                }
            }

            foreach ($except as $fd) {
                $fdKey = (int)$fd;
                if (isset($this->exceptEvents[$fdKey])) {
                    $this->exceptEvents[$fdKey]($fd);
                }
            }

            if (!empty($this->signalEvents)) {
                // Calls signal handlers for pending signals
                \pcntl_signal_dispatch();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->running = false;
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
    public function getTimerCount(): int
    {
        return \count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler)
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorHandler(): ?callable
    {
        return $this->errorHandler;
    }

    /**
     * @param Throwable $e
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $e)
    {
        if (!$this->errorHandler) {
            throw new $e;
        }
        ($this->errorHandler)($e);
    }

}
