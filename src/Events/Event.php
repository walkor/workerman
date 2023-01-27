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
            $className = '\\\\Event';
        } else {
            $className = '\Event';
        }
        $this->eventClassName = $className;
        if (\class_exists('\\\\EventBase', false)) {
            $className = '\\\\EventBase';
        } else {
            $className = '\EventBase';
        }
        $this->eventBase = new $className();
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args = [])
    {
        $className = $this->eventClassName;
        $timerId = $this->timerId++;
        $event = new $className($this->eventBase, -1, $className::TIMEOUT, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        if (!$event || !$event->addTimer($delay)) {
            return false;
        }
        $this->eventTimer[$timerId] = $event;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay($timerId)
    {
        if (isset($this->eventTimer[$timerId])) {
            $this->eventTimer[$timerId]->del();
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
    public function repeat(float $interval, $func, $args = [])
    {
        $className = $this->eventClassName;
        $timerId = $this->timerId++;
        $event = new $className($this->eventBase, -1, $className::TIMEOUT | $className::PERSIST, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        if (!$event || !$event->addTimer($interval)) {
            return false;
        }
        $this->eventTimer[$timerId] = $event;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $className = $this->eventClassName;
        $fdKey = (int)$stream;
        $event = new $this->eventClassName($this->eventBase, $stream, $className::READ | $className::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->readEvents[$fdKey] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->del();
            unset($this->readEvents[$fdKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $className = $this->eventClassName;
        $fdKey = (int)$stream;
        $event = new $this->eventClassName($this->eventBase, $stream, $className::WRITE | $className::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->writeEvents[$fdKey] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->del();
            unset($this->writeEvents[$fdKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        $className = $this->eventClassName;
        $fdKey = (int)$signal;
        $event = $className::signal($this->eventBase, $signal, $func);
        if (!$event || !$event->add()) {
            return false;
        }
        $this->eventSignal[$fdKey] = $event;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        $fdKey = (int)$signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->eventSignal[$fdKey]->del();
            unset($this->eventSignal[$fdKey]);
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
