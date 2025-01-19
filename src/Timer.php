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

namespace Workerman;

use RuntimeException;
use Throwable;
use Workerman\Events\EventInterface;
use Workerman\Events\Fiber;
use Workerman\Events\Swoole;
use Revolt\EventLoop;
use Swoole\Coroutine\System;
use function function_exists;
use function pcntl_alarm;
use function pcntl_signal;
use function time;
use const PHP_INT_MAX;
use const SIGALRM;

/**
 * Timer.
 */
class Timer
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
    protected static array $tasks = [];

    /**
     * Event
     *
     * @var ?EventInterface
     */
    protected static ?EventInterface $event = null;

    /**
     * Timer id
     *
     * @var int
     */
    protected static int $timerId = 0;

    /**
     * Timer status
     * [
     *   timer_id1 => bool,
     *   timer_id2 => bool,
     *   ....................,
     * ]
     *
     * @var array
     */
    protected static array $status = [];

    /**
     * Init.
     *
     * @param EventInterface|null $event
     * @return void
     */
    public static function init(?EventInterface $event = null): void
    {
        if ($event) {
            self::$event = $event;
            return;
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, self::signalHandle(...), false);
        }
    }

    /**
     * Repeat.
     *
     * @param float $timeInterval
     * @param callable $func
     * @param array $args
     * @return int
     */
    public static function repeat(float $timeInterval, callable $func, array $args = []): int
    {
        return self::$event->repeat($timeInterval, $func, $args);
    }

    /**
     * Delay.
     *
     * @param float $timeInterval
     * @param callable $func
     * @param array $args
     * @return int
     */
    public static function delay(float $timeInterval, callable $func, array $args = []): int
    {
        return self::$event->delay($timeInterval, $func, $args);
    }

    /**
     * ALARM signal handler.
     *
     * @return void
     */
    public static function signalHandle(): void
    {
        if (!self::$event) {
            pcntl_alarm(1);
            self::tick();
        }
    }

    /**
     * Add a timer.
     *
     * @param float $timeInterval
     * @param callable $func
     * @param null|array $args
     * @param bool $persistent
     * @return int
     */
    public static function add(float $timeInterval, callable $func, ?array $args = [], bool $persistent = true): int
    {
        if ($timeInterval < 0) {
            throw new RuntimeException('$timeInterval can not less than 0');
        }

        if ($args === null) {
            $args = [];
        }

        if (self::$event) {
            return $persistent ? self::$event->repeat($timeInterval, $func, $args) : self::$event->delay($timeInterval, $func, $args);
        }

        // If not workerman runtime just return.
        if (!Worker::getAllWorkers()) {
            throw new RuntimeException('Timer can only be used in workerman running environment');
        }

        if (empty(self::$tasks)) {
            pcntl_alarm(1);
        }

        $runTime = time() + $timeInterval;
        if (!isset(self::$tasks[$runTime])) {
            self::$tasks[$runTime] = [];
        }

        self::$timerId = self::$timerId == PHP_INT_MAX ? 1 : ++self::$timerId;
        self::$status[self::$timerId] = true;
        self::$tasks[$runTime][self::$timerId] = [$func, (array)$args, $persistent, $timeInterval];

        return self::$timerId;
    }

    /**
     * Coroutine sleep.
     *
     * @param float $delay
     * @return void
     */
    public static function sleep(float $delay): void
    {
        switch (Worker::$eventLoopClass) {
            // Fiber
            case Fiber::class:
                $suspension = EventLoop::getSuspension();
                static::add($delay, function () use ($suspension) {
                    $suspension->resume();
                }, null, false);
                $suspension->suspend();
                return;
            // Swoole
            case Swoole::class:
                System::sleep($delay);
                return;
        }
        usleep((int)($delay * 1000 * 1000));
    }

    /**
     * Tick.
     *
     * @return void
     */
    protected static function tick(): void
    {
        if (empty(self::$tasks)) {
            pcntl_alarm(0);
            return;
        }
        $timeNow = time();
        foreach (self::$tasks as $runTime => $taskData) {
            if ($timeNow >= $runTime) {
                foreach ($taskData as $index => $oneTask) {
                    $taskFunc = $oneTask[0];
                    $taskArgs = $oneTask[1];
                    $persistent = $oneTask[2];
                    $timeInterval = $oneTask[3];
                    try {
                        $taskFunc(...$taskArgs);
                    } catch (Throwable $e) {
                        Worker::safeEcho((string)$e);
                    }
                    if ($persistent && !empty(self::$status[$index])) {
                        $newRunTime = time() + $timeInterval;
                        if (!isset(self::$tasks[$newRunTime])) {
                            self::$tasks[$newRunTime] = [];
                        }
                        self::$tasks[$newRunTime][$index] = [$taskFunc, (array)$taskArgs, $persistent, $timeInterval];
                    }
                }
                unset(self::$tasks[$runTime]);
            }
        }
    }

    /**
     * Remove a timer.
     *
     * @param int $timerId
     * @return bool
     */
    public static function del(int $timerId): bool
    {
        if (self::$event) {
            return self::$event->offDelay($timerId);
        }
        foreach (self::$tasks as $runTime => $taskData) {
            if (array_key_exists($timerId, $taskData)) {
                unset(self::$tasks[$runTime][$timerId]);
            }
        }
        if (array_key_exists($timerId, self::$status)) {
            unset(self::$status[$timerId]);
        }
        return true;
    }

    /**
     * Remove all timers.
     *
     * @return void
     */
    public static function delAll(): void
    {
        self::$tasks = self::$status = [];
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
        self::$event?->deleteAllTimer();
    }
}
