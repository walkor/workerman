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
    protected $eventBase = null;

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
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected $eventTimer = [];

    /**
     * Timer id.
     * @var int
     */
    protected $timerId = 0;

    /**
     * Event class name.
     * @var string
     */
    protected $eventClassName = '';

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
        $this->eventClassName = $class_name;
        if (\class_exists('\\\\EventBase', false)) {
            $class_name = '\\\\EventBase';
        } else {
            $class_name = '\EventBase';
        }
        $this->eventBase = new $class_name();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $class_name = $this->eventClassName;
        $timer_id = $this->timerId++;
        $event = new $class_name($this->eventBase, -1, $class_name::TIMEOUT, function () use ($func, $args, $timer_id) {
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
        $this->eventTimer[$timer_id] = $event;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->eventTimer[$timer_id])) {
            $this->eventTimer[$timer_id]->del();
            unset($this->eventTimer[$timer_id]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $class_name = $this->eventClassName;
        $timer_id = $this->timerId++;
        $event = new $class_name($this->eventBase, -1, $class_name::TIMEOUT | $class_name::PERSIST, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        if (!$event || !$event->addTimer($interval)) {
            return false;
        }
        $this->eventTimer[$timer_id] = $event;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $class_name = $this->eventClassName;
        $fd_key = (int)$stream;
        $event = new $this->eventClassName($this->eventBase, $stream, $class_name::READ | $class_name::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->writeEvents[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->readEvents[$fd_key])) {
            $this->readEvents[$fd_key]->del();
            unset($this->readEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $class_name = $this->eventClassName;
        $fd_key = (int)$stream;
        $event = new $this->eventClassName($this->eventBase, $stream, $class_name::WRITE | $class_name::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->writeEvents[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fd_key = (int)$stream;
        if (isset($this->writeEvents[$fd_key])) {
            $this->writeEvents[$fd_key]->del();
            unset($this->writeEvents[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $class_name = $this->eventClassName;
        $fd_key = (int)$signal;
        $event = $class_name::signal($this->eventBase, $signal, $func);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->eventSignal[$fd_key] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fd_key = (int)$signal;
        if (isset($this->eventSignal[$fd_key])) {
            $this->eventSignal[$fd_key]->del();
            unset($this->eventSignal[$fd_key]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $event) {
            $event->del();
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        $this->eventBase->loop();
    }

    /**
     * {@inheritdoc}
     */
    public function stop()
    {
        $this->eventBase->exit();
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount()
    {
        return \count($this->eventTimer);
    }
}
