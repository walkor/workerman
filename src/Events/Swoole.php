<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */

declare(strict_types=1);

namespace Workerman\Events;

use Swoole\Event;
use Swoole\Process;
use Swoole\Timer;
use Throwable;

class Swoole implements EventInterface
{
    /**
     * All listeners for read timer
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * All listeners for read event.
     * @var array
     */
    protected array $readEvents = [];

    /**
     * All listeners for write event.
     * @var array
     */
    protected array $writeEvents = [];

    /**
     * @var ?callable
     */
    protected $errorHandler = null;

    /**
     * {@inheritdoc}
     * @throws Throwable
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $t = (int)($delay * 1000);
        $t = max($t, 1);
        $timerId = Timer::after($t, function () use ($func, $args, &$timerId) {
            unset($this->eventTimer[$timerId]);
            try {
                $func(...$args);
            } catch (Throwable $e) {
                $this->error($e);
            }
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            $res = Timer::clear($timerId);
            unset($this->eventTimer[$timerId]);
            return $res;
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
     * @throws Throwable
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $t = (int)($interval * 1000);
        $t = max($t, 1);
        $timerId = Timer::tick($t, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (Throwable $e) {
                $this->error($e);
            }
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd]) && !isset($this->writeEvents[$fd])) {
            Event::add($stream, $func, null, SWOOLE_EVENT_READ);
        } else {
            if (isset($this->writeEvents[$fd])) {
                Event::set($stream, $func, null, SWOOLE_EVENT_READ | SWOOLE_EVENT_WRITE);
            } else {
                Event::set($stream, $func, null, SWOOLE_EVENT_READ);
            }
        }
        $this->readEvents[$fd] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd])) {
            return false;
        }
        unset($this->readEvents[$fd]);
        if (!isset($this->writeEvents[$fd])) {
            Event::del($stream);
            return true;
        }
        Event::set($stream, null, null, SWOOLE_EVENT_WRITE);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd]) && !isset($this->writeEvents[$fd])) {
            Event::add($stream, null, $func, SWOOLE_EVENT_WRITE);
        } else {
            if (isset($this->readEvents[$fd])) {
                Event::set($stream, null, $func, SWOOLE_EVENT_WRITE | SWOOLE_EVENT_READ);
            } else {
                Event::set($stream, null, $func, SWOOLE_EVENT_WRITE);
            }
        }
        $this->writeEvents[$fd] = $stream;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fd = (int)$stream;
        if (!isset($this->writeEvents[$fd])) {
            return false;
        }
        unset($this->writeEvents[$fd]);
        if (!isset($this->readEvents[$fd])) {
            Event::del($stream);
            return true;
        }
        Event::set($stream, null, null, SWOOLE_EVENT_READ);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        Process::signal($signal, $func);
    }

    /**
     * Please see https://wiki.swoole.com/#/process/process?id=signal
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        return Process::signal($signal, null);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $timerId) {
            Timer::clear($timerId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        // Avoid process exit due to no listening
        Timer::tick(100000000, static fn() => null);
        Event::wait();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function stop(): void
    {
        Event::exit();
        posix_kill(posix_getpid(), SIGINT);
    }

    /**
     * Get timer count.
     *
     * @return integer
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
    public function error(Throwable $e): void
    {
        if (!$this->errorHandler) {
            throw new $e;
        }
        ($this->errorHandler)($e);
    }

}
