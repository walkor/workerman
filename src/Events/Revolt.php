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
        foreach ($this->eventSignal as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->driver->stop();
        if (\function_exists('pcntl_signal')) {
            \pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $args = (array)$args;
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $func(...$args);
        };
        $cbId = $this->driver->delay($delay, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $args = (array)$args;
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args, $timerId) {
            $func(...$args);
        };
        $cbId = $this->driver->repeat($interval, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->driver->cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
        }

        $this->readEvents[$fdKey] = $this->driver->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->driver->cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->driver->cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
        }
        $this->writeEvents[$fdKey] = $this->driver->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->driver->cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $fdKey = (int)$signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->driver->cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
        }
        $this->eventSignal[$fdKey] = $this->driver->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fdKey = (int)$signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->driver->cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay($timerId)
    {
        if (isset($this->eventTimer[$timerId])) {
            $this->driver->cancel($this->eventTimer[$timerId]);
            unset($this->eventTimer[$timerId]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offRepeat($timerId)
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $cbId) {
            $this->driver->cancel($cbId);
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
