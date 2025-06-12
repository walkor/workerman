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

declare(strict_types=1);

namespace Workerman\Events;

use SplPriorityQueue;
use Throwable;
use function count;
use function max;
use function microtime;
use function pcntl_signal;
use function pcntl_signal_dispatch;
use const DIRECTORY_SEPARATOR;

/**
 * select eventloop
 */
final class Select implements EventInterface
{
    /**
     * Running.
     *
     * @var bool
     */
    private bool $running = true;

    /**
     * All listeners for read/write event.
     *
     * @var array<int, callable>
     */
    private array $readEvents = [];

    /**
     * All listeners for read/write event.
     *
     * @var array<int, callable>
     */
    private array $writeEvents = [];

    /**
     * @var array<int, callable>
     */
    private array $exceptEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array<int, callable>
     */
    private array $signalEvents = [];

    /**
     * Fds waiting for read event.
     *
     * @var array<int, resource>
     */
    private array $readFds = [];

    /**
     * Fds waiting for write event.
     *
     * @var array<int, resource>
     */
    private array $writeFds = [];

    /**
     * Fds waiting for except event.
     *
     * @var array<int, resource>
     */
    private array $exceptFds = [];

    /**
     * Timer scheduler.
     * {['data':timer_id, 'priority':run_timestamp], ..}
     *
     * @var SplPriorityQueue
     */
    private SplPriorityQueue $scheduler;

    /**
     * All timer event listeners.
     * [[func, args, flag, timer_interval], ..]
     *
     * @var array
     */
    private array $eventTimer = [];

    /**
     * Timer id.
     *
     * @var int
     */
    private int $timerId = 1;

    /**
     * Select timeout.
     *
     * @var int
     */
    private int $selectTimeout = self::MAX_SELECT_TIMOUT_US;

    /**
     * Next run time of the timer.
     *
     * @var float
     */
    private float $nextTickTime = 0;

    /**
     * @var ?callable
     */
    private $errorHandler = null;

    /**
     * Select timeout.
     *
     * @var int
     */
    const MAX_SELECT_TIMOUT_US = 800000;

    /**
     * Construct.
     */
    public function __construct()
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $delay;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args];
        if ($this->nextTickTime == 0 || $this->nextTickTime > $runTime) {
            $this->setNextTickTime($runTime);
        }
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $runTime = microtime(true) + $interval;
        $this->scheduler->insert($timerId, -$runTime);
        $this->eventTimer[$timerId] = [$func, $args, $interval];
        if ($this->nextTickTime == 0 || $this->nextTickTime > $runTime) {
            $this->setNextTickTime($runTime);
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
    public function onReadable($stream, callable $func): void
    {
        $count = count($this->readFds);
        if ($count >= 1024) {
            trigger_error("System call select exceeded the maximum number of connections 1024, please install event extension for more connections.", E_USER_WARNING);
        } else if (DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            trigger_error("System call select exceeded the maximum number of connections 256.", E_USER_WARNING);
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
    public function onWritable($stream, callable $func): void
    {
        $count = count($this->writeFds);
        if ($count >= 1024) {
            trigger_error("System call select exceeded the maximum number of connections 1024, please install event/libevent extension for more connections.", E_USER_WARNING);
        } else if (DIRECTORY_SEPARATOR !== '/' && $count >= 256) {
            trigger_error("System call select exceeded the maximum number of connections 256.", E_USER_WARNING);
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
     * On except.
     *
     * @param resource $stream
     * @param callable $func
     */
    public function onExcept($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $this->exceptEvents[$fdKey] = $func;
        $this->exceptFds[$fdKey] = $stream;
    }

    /**
     * Off except.
     *
     * @param resource $stream
     * @return bool
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
    public function onSignal(int $signal, callable $func): void
    {
        if (!function_exists('pcntl_signal')) {
            return;
        }
        $this->signalEvents[$signal] = $func;
        pcntl_signal($signal, fn () => $this->safeCall($this->signalEvents[$signal], [$signal]));
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (!function_exists('pcntl_signal')) {
            return false;
        }
        pcntl_signal($signal, SIG_IGN);
        if (isset($this->signalEvents[$signal])) {
            unset($this->signalEvents[$signal]);
            return true;
        }
        return false;
    }

    /**
     * Tick for timer.
     *
     * @return void
     */
    protected function tick(): void
    {
        $tasksToInsert = [];
        while (!$this->scheduler->isEmpty()) {
            $schedulerData = $this->scheduler->top();
            $timerId = $schedulerData['data'];
            $nextRunTime = -$schedulerData['priority'];
            $timeNow = microtime(true);
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
                $this->safeCall($taskData[0], $taskData[1]);
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
            $this->setNextTickTime($nextRunTime);
            return;
        }
        $this->setNextTickTime(0);
    }

    /**
     * Set next tick time.
     *
     * @param float $nextTickTime
     * @return void
     */
    protected function setNextTickTime(float $nextTickTime): void
    {
        $this->nextTickTime = $nextTickTime;
        if ($nextTickTime == 0) {
            $this->selectTimeout = self::MAX_SELECT_TIMOUT_US;
            return;
        }
        $this->selectTimeout = min(max((int)(($nextTickTime - microtime(true)) * 1000000), 0), self::MAX_SELECT_TIMOUT_US);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        $this->scheduler = new SplPriorityQueue();
        $this->scheduler->setExtractFlags(SplPriorityQueue::EXTR_BOTH);
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        while ($this->running) {
            $read = $this->readFds;
            $write = $this->writeFds;
            $except = $this->exceptFds;
            if ($read || $write || $except) {
                // Waiting read/write/signal/timeout events.
                try {
                    @stream_select($read, $write, $except, 0, $this->selectTimeout);
                } catch (Throwable) {
                    // do nothing
                }
            } else {
                $this->selectTimeout >= 1 && usleep($this->selectTimeout);
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

            if ($this->nextTickTime > 0) {
                if (microtime(true) >= $this->nextTickTime) {
                    $this->tick();
                } else {
                    $this->selectTimeout = (int)(($this->nextTickTime - microtime(true)) * 1000000);
                }
            }

            // The $this->signalEvents are empty under Windows, make sure not to call pcntl_signal_dispatch.
            if ($this->signalEvents) {
                // Calls signal handlers for pending signals
                pcntl_signal_dispatch();
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        $this->running = false;
        $this->deleteAllTimer();
        foreach ($this->signalEvents as $signal => $item) {
            $this->offsignal($signal);
        }
        $this->readFds = [];
        $this->writeFds = [];
        $this->exceptFds = [];
        $this->readEvents = [];
        $this->writeEvents = [];
        $this->exceptEvents = [];
        $this->signalEvents = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * @param callable $func
     * @param array $args
     * @return void
     */
    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (Throwable $e) {
            if ($this->errorHandler === null) {
                echo $e;
            } else {
                ($this->errorHandler)($e);
            }
        }
    }
}
