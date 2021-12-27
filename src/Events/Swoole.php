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
    protected $_eventTimer = [];

    /**
     * All listeners for read event.
     * @var array
     */
    protected $_readEvents = [];

    /**
     * All listeners for write event.
     * @var array
     */
    protected $_writeEvents = [];

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $t = (int)($delay * 1000);
        $t = $t < 1 ? 1 : $t;
        $timer_id = Timer::after($t, function () use ($func, $args, &$timer_id) {
            unset($this->_eventTimer[$timer_id]);
            try {
                $func(...(array)$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $this->_eventTimer[$timer_id] = $timer_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->_eventTimer[$timer_id])) {
            $res = Timer::clear($timer_id);
            unset($this->_eventTimer[$timer_id]);
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
        $timer_id = Timer::tick($t, function () use ($func, $args) {
            try {
                $func(...(array)$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $this->_eventTimer[$timer_id] = $timer_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $this->_readEvents[(int)$stream] = $stream;
        return Event::add($stream, $func, null, \SWOOLE_EVENT_READ);
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd = (int)$stream;
        if (!isset($this->_readEvents[$fd])) {
            return;
        }
        unset($this->_readEvents[$fd]);
        if (!isset($this->_writeEvents[$fd])) {
            return Event::del($stream);
        }
        return Event::set($stream, null, null, \SWOOLE_EVENT_READ);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $this->_writeEvents[(int)$stream] = $stream;
        return Event::add($stream, null, $func, \SWOOLE_EVENT_WRITE);
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd = (int)$stream;
        if (!isset($this->_writeEvents[$fd])) {
            return;
        }
        unset($this->_writeEvents[$fd]);
        if (!isset($this->_readEvents[$fd])) {
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
        foreach ($this->_eventTimer as $timer_id) {
            Timer::clear($timer_id);
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
        return \count($this->_eventTimer);
    }

}
