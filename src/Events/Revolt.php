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

use Revolt\EventLoop\Driver;
use Revolt\EventLoop;

/**
 * Revolt eventloop
 */
class Revolt implements EventInterface
{
    /**
     * @var Driver
     */
    protected $driver = null;

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
     * Event listeners of signal.
     * @var array
     */
    protected $eventSignal = [];

    /**
     * Event listeners of timer.
     * @var array
     */
    protected $eventTimer = [];

    /**
     * Timer id.
     * @var int
     */
    protected $timerId = 1;

    /**
     * Construct.
     */
    public function __construct()
    {
        $this->driver = EventLoop::getDriver();
    }

    /**
     * {@inheritdoc}
     */
    public function driver()
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->driver->run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        foreach ($this->eventSignal as $cb_id) {
            $this->driver->cancel($cb_id);
        }
        $this->driver->stop();
        pcntl_signal(SIGINT, SIG_IGN);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $args = (array)$args;
        $timer_id = $this->timerId++;
        $closure = function () use ($func, $args, $timer_id) {
            unset($this->eventTimer[$timer_id]);
            $func(...$args);
        };
        $cb_id = $this->driver->delay($delay, $closure);
        $this->eventTimer[$timer_id] = $cb_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $args = (array)$args;
        $timer_id = $this->timerId++;
        $closure = function () use ($func, $args, $timer_id) {
            $func(...$args);
        };
        $cb_id = $this->driver->repeat($interval, $closure);
        $this->eventTimer[$timer_id] = $cb_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fd_key = (int)$stream;
        if (isset($this->readEvents[$fd_key])) {
            $this->driver->cancel($this->readEvents[$fd_key]);
            unset($this->readEvents[$fd_key]);
        }

        $this->readEvents[$fd_key] = $this->driver->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->readEvents[$fd_key])) {
            $this->driver->cancel($this->readEvents[$fd_key]);
            unset($this->readEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fd_key = (int)$stream;
        if (isset($this->writeEvents[$fd_key])) {
            $this->driver->cancel($this->writeEvents[$fd_key]);
            unset($this->writeEvents[$fd_key]);
        }
        $this->writeEvents[$fd_key] = $this->driver->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->writeEvents[$fd_key])) {
            $this->driver->cancel($this->writeEvents[$fd_key]);
            unset($this->writeEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $fd_key = (int)$signal;
        if (isset($this->eventSignal[$fd_key])) {
            $this->driver->cancel($this->eventSignal[$fd_key]);
            unset($this->eventSignal[$fd_key]);
        }
        $this->eventSignal[$fd_key] = $this->driver->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fd_key = (int)$signal;
        if (isset($this->eventSignal[$fd_key])) {
            $this->driver->cancel($this->eventSignal[$fd_key]);
            unset($this->eventSignal[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->eventTimer[$timer_id])) {
            $this->driver->cancel($this->eventTimer[$timer_id]);
            unset($this->eventTimer[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $cb_id) {
            $this->driver->cancel($cb_id);
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->eventTimer);
    }
}
