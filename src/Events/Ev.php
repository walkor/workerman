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
    protected $readEvents = [];

    /**
     * All listeners for write event.
     *
     * @var array
     */
    protected $writeEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array
     */
    protected $eventSignal = [];

    /**
     * All timer event listeners.
     *
     * @var array
     */
    protected $eventTimer = [];

    /**
     * Timer id.
     *
     * @var int
     */
    protected static $timerId = 1;

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args = [])
    {
        $timerId = self::$timerId;
        $event = new \EvTimer($delay, 0, function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $func(...(array)$args);
        });
        $this->eventTimer[self::$timerId] = $event;
        return self::$timerId++;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay($timerId)
    {
        if (isset($this->eventTimer[$timerId])) {
            $this->eventTimer[$timerId]->stop();
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
        $event = new \EvTimer($interval, $interval, function () use ($func, $args) {
            $func(...(array)$args);
        });
        $this->eventTimer[self::$timerId] = $event;
        return self::$timerId++;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        $fdKey = (int)$stream;
        $event = new \EvIo($stream, \Ev::READ, function () use ($func, $stream) {
            $func($stream);
        });
        $this->readEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream)
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->stop();
            unset($this->readEvents[$fdKey]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        $fdKey = (int)$stream;
        $event = new \EvIo($stream, \Ev::WRITE, function () use ($func, $stream) {
            $func($stream);
        });
        $this->readEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream)
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->stop();
            unset($this->writeEvents[$fdKey]);
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
        $this->eventSignal[$signal] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        if (isset($this->eventSignal[$signal])) {
            $this->eventSignal[$signal]->stop();
            unset($this->eventSignal[$signal]);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $event) {
            $event->stop();
        }
        $this->eventTimer = [];
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
        return \count($this->eventTimer);
    }

}
