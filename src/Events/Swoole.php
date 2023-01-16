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

namespace Workerman\Events;

use Workerman\Worker;
use Swoole\Event;
use Swoole\Timer;
use Swoole\Process;

class Swoole implements EventInterface
{
    /**
     * All listeners for read timer
     * @var array
     */
    protected $eventTimer = [];

    /**
     * All listeners for read event.
     * @var array
     */
    protected $readEvents = [];

    /**
     * All listeners for write event.
     * @var array
     */
    protected $writeEvents = [];

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $t = (int)($delay * 1000);
        $t = $t < 1 ? 1 : $t;
        $timerId = Timer::after($t, function () use ($func, $args, &$timerId) {
            unset($this->eventTimer[$timerId]);
            try {
                $func(...(array)$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timerId)
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
    public function repeat(float $interval, $func, $args)
    {
        if ($this->mapId > \PHP_INT_MAX) {
            $this->mapId = 0;
        }
        $t = (int)($interval * 1000);
        $t = $t < 1 ? 1 : $t;
        $timerId = Timer::tick($t, function () use ($func, $args) {
            try {
                $func(...(array)$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $this->readEvents[(int)$stream] = $stream;
        return Event::add($stream, $func, null, \SWOOLE_EVENT_READ);
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd = (int)$stream;
        if (!isset($this->readEvents[$fd])) {
            return;
        }
        unset($this->readEvents[$fd]);
        if (!isset($this->writeEvents[$fd])) {
            return Event::del($stream);
        }
        return Event::set($stream, null, null, \SWOOLE_EVENT_READ);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $this->writeEvents[(int)$stream] = $stream;
        return Event::add($stream, null, $func, \SWOOLE_EVENT_WRITE);
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd = (int)$stream;
        if (!isset($this->writeEvents[$fd])) {
            return;
        }
        unset($this->writeEvents[$fd]);
        if (!isset($this->readEvents[$fd])) {
            return Event::del($stream);
        }
        return Event::set($stream, null, null, \SWOOLE_EVENT_WRITE);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        return Process::signal($signal, $func);
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        return Process::signal($signal, function () {
        });
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $timerId) {
            Timer::clear($timerId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        Event::wait();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function stop()
    {
        Event::exit();
        \posix_kill(posix_getpid(), SIGINT);
    }

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->eventTimer);
    }

}
