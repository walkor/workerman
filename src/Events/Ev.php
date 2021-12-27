<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author  walkor<walkor@workerman.net>
 * @link    http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */

namespace Workerman\Events;

use Workerman\Worker;
use \EvWatcher;

/**
 * Ev eventloop
 */
class Ev implements EventInterface
{
    /**
     * All listeners for read event.
     *
     * @var array
     */
    protected $_readEvents = [];

    /**
     * All listeners for write event.
     *
     * @var array
     */
    protected $_writeEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $_eventSignal = [];

    /**
     * All timer event listeners.
     *
     * @var array
     */
    protected $_eventTimer = [];

    /**
     * Timer id.
     *
     * @var int
     */
    protected static $_timerId = 1;

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $timer_id = self::$_timerId;
        $event = new \EvTimer($delay, 0, function () use ($func, $args, $timer_id) {
            unset($this->_eventTimer[$timer_id]);
            $func(...(array)$args);
        });
        $this->_eventTimer[self::$_timerId] = $event;
        return self::$_timerId++;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->_eventTimer[$timer_id])) {
            $this->_eventTimer[$timer_id]->stop();
            unset($this->_eventTimer[$timer_id]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $event = new \EvTimer($interval, $interval, function () use ($func, $args) {
            $func(...(array)$args);
        });
        $this->_eventTimer[self::$_timerId] = $event;
        return self::$_timerId++;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fd_key = (int)$stream;
        $event = new \EvIo($stream, \Ev::READ, function () use ($func, $stream) {
            $func($stream);
        });
        $this->_readEvents[$fd_key] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->_readEvents[$fd_key])) {
            $this->_readEvents[$fd_key]->stop();
            unset($this->_readEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fd_key = (int)$stream;
        $event = new \EvIo($stream, \Ev::WRITE, function () use ($func, $stream) {
            $func($stream);
        });
        $this->_readEvents[$fd_key] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->_writeEvents[$fd_key])) {
            $this->_writeEvents[$fd_key]->stop();
            unset($this->_writeEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $event = new \EvSignal($signal, function () use ($func, $signal) {
            $func($signal);
        });
        $this->_eventSignal[$signal] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        if (isset($this->_eventSignal[$signal])) {
            $this->_eventSignal[$signal]->stop();
            unset($this->_eventSignal[$signal]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->_eventTimer as $event) {
            $event->stop();
        }
        $this->_eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        \Ev::run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        \Ev::stop();
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }

}
