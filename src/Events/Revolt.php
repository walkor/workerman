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
    protected $_driver = null;

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
     * Event listeners of signal.
     * @var array
     */
    protected $_eventSignal = [];

    /**
     * Event listeners of timer.
     * @var array
     */
    protected $_eventTimer = [];

    /**
     * Timer id.
     * @var int
     */
    protected $_timerId = 1;

    /**
     * Construct.
     */
    public function __construct()
    {
        $this->_driver = EventLoop::getDriver();
    }

    /**
     * {@inheritdoc}
     */
    public function driver()
    {
        return $this->_driver;
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->_driver->run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        foreach ($this->_eventSignal as $cb_id) {
            $this->_driver->cancel($cb_id);
        }
        $this->_driver->stop();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $args = (array)$args;
        $timer_id = $this->_timerId++;
        $closure = function () use ($func, $args, $timer_id) {
            unset($this->_eventTimer[$timer_id]);
            $func(...$args);
        };
        $cb_id = $this->_driver->delay($delay, $closure);
        $this->_eventTimer[$timer_id] = $cb_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $args = (array)$args;
        $timer_id = $this->_timerId++;
        $closure = function () use ($func, $args, $timer_id) {
            $func(...$args);
        };
        $cb_id = $this->_driver->repeat($interval, $closure);
        $this->_eventTimer[$timer_id] = $cb_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fd_key = (int)$stream;
        if (isset($this->_readEvents[$fd_key])) {
            $this->_driver->cancel($this->_readEvents[$fd_key]);
            unset($this->_readEvents[$fd_key]);
        }

        $this->_readEvents[$fd_key] = $this->_driver->onReadable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->_readEvents[$fd_key])) {
            $this->_driver->cancel($this->_readEvents[$fd_key]);
            unset($this->_readEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fd_key = (int)$stream;
        if (isset($this->_writeEvents[$fd_key])) {
            $this->_driver->cancel($this->_writeEvents[$fd_key]);
            unset($this->_writeEvents[$fd_key]);
        }
        $this->_writeEvents[$fd_key] = $this->_driver->onWritable($stream, function () use ($stream, $func) {
            $func($stream);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->_writeEvents[$fd_key])) {
            $this->_driver->cancel($this->_writeEvents[$fd_key]);
            unset($this->_writeEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $fd_key = (int)$signal;
        if (isset($this->_eventSignal[$fd_key])) {
            $this->_driver->cancel($this->_eventSignal[$fd_key]);
            unset($this->_eventSignal[$fd_key]);
        }
        $this->_eventSignal[$fd_key] = $this->_driver->onSignal($signal, function () use ($signal, $func) {
            $func($signal);
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fd_key = (int)$signal;
        if (isset($this->_eventSignal[$fd_key])) {
            $this->_driver->cancel($this->_eventSignal[$fd_key]);
            unset($this->_eventSignal[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->_eventTimer[$timer_id])) {
            $this->_driver->cancel($this->_eventTimer[$timer_id]);
            unset($this->_eventTimer[$timer_id]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->_eventTimer as $cb_id) {
            $this->_driver->cancel($cb_id);
        }
        $this->_eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }
}
