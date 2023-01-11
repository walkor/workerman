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

use Workerman\Worker;

/**
 * libevent eventloop
 */
class Event implements EventInterface
{
    /**
     * Event base.
     * @var object
     */
    protected $_eventBase = null;

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
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected $_eventTimer = [];

    /**
     * Timer id.
     * @var int
     */
    protected $_timerId = 0;

    /**
     * Event class name.
     * @var string
     */
    protected $_eventClassName = '';

    /**
     * Construct.
     * @return void
     */
    public function __construct()
    {
        if (\class_exists('\\\\Event', false)) {
            $class_name = '\\\\Event';
        } else {
            $class_name = '\Event';
        }
        $this->_eventClassName = $class_name;
        if (\class_exists('\\\\EventBase', false)) {
            $class_name = '\\\\EventBase';
        } else {
            $class_name = '\EventBase';
        }
        $this->_eventBase = new $class_name();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $class_name = $this->_eventClassName;
        $timer_id = $this->_timerId++;
        $event = new $class_name($this->_eventBase, -1, $class_name::TIMEOUT, function () use ($func, $args, $timer_id) {
            try {
                $this->deleteTimer($timer_id);
                $func(...$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        if (!$event || !$event->addTimer($delay)) {
            return false;
        }
        $this->_eventTimer[$timer_id] = $event;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->_eventTimer[$timer_id])) {
            $this->_eventTimer[$timer_id]->del();
            unset($this->_eventTimer[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $class_name = $this->_eventClassName;
        $timer_id = $this->_timerId++;
        $event = new $class_name($this->_eventBase, -1, $class_name::TIMEOUT | $class_name::PERSIST, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        if (!$event || !$event->addTimer($interval)) {
            return false;
        }
        $this->_eventTimer[$timer_id] = $event;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $class_name = $this->_eventClassName;
        $fd_key = (int)$stream;
        $event = new $this->_eventClassName($this->_eventBase, $stream, $class_name::READ | $class_name::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->_writeEvents[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->_readEvents[$fd_key])) {
            $this->_readEvents[$fd_key]->del();
            unset($this->_readEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $class_name = $this->_eventClassName;
        $fd_key = (int)$stream;
        $event = new $this->_eventClassName($this->_eventBase, $stream, $class_name::WRITE | $class_name::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->_writeEvents[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->_writeEvents[$fd_key])) {
            $this->_writeEvents[$fd_key]->del();
            unset($this->_writeEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $class_name = $this->_eventClassName;
        $fd_key = (int)$signal;
        $event = $class_name::signal($this->_eventBase, $signal, $func);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->_eventSignal[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fd_key = (int)$signal;
        if (isset($this->_eventSignal[$fd_key])) {
            $this->_eventSignal[$fd_key]->del();
            unset($this->_eventSignal[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->_eventTimer as $event) {
            $event->del();
        }
        $this->_eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->_eventBase->loop();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->_eventBase->exit();
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }
}
