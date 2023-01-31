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

use Throwable;
use EventBase;

/**
 * libevent eventloop
 */
class Event implements EventInterface
{
    /**
     * Event base.
     * @var EventBase
     */
    protected $eventBase;

    /**
     * All listeners for read event.
     * @var array
     */
    protected array $readEvents = [];

    /**
     * All listeners for write event.
     * @var array
     */
    protected array $writeEvents = [];

    /**
     * Event listeners of signal.
     * @var array
     */
    protected array $eventSignal = [];

    /**
     * All timer event listeners.
     * [func, args, event, flag, time_interval]
     * @var array
     */
    protected array $eventTimer = [];

    /**
     * Timer id.
     * @var int
     */
    protected int $timerId = 0;

    /**
     * Event class name.
     * @var string
     */
    protected string $eventClassName = '';

    /**
     * @var ?callable
     */
    protected $errorHandler = null;

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
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $className = $this->eventClassName;
        $timerId = $this->timerId++;
        $event = new $className($this->eventBase, -1, $className::TIMEOUT, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                $this->error($e);
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
    public function offDelay(int $timerId): bool
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
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $className = $this->eventClassName;
        $timerId = $this->timerId++;
        $event = new $className($this->eventBase, -1, $className::TIMEOUT | $className::PERSIST, function () use ($func, $args) {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                $this->error($e);
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
    public function onReadable($stream, callable $func)
    {
        $className = $this->eventClassName;
        $fdKey = (int)$stream;
        $event = new $this->eventClassName($this->eventBase, $stream, $className::READ | $className::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return;
        }
        $this->readEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->del();
            unset($this->readEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func)
    {
        $className = $this->eventClassName;
        $fdKey = (int)$stream;
        $event = new $this->eventClassName($this->eventBase, $stream, $className::WRITE | $className::PERSIST, $func, $stream);
        if (!$event || !$event->add()) {
            return;
        }
        $this->writeEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->del();
            unset($this->writeEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func)
    {
        $className = $this->eventClassName;
        $fdKey = $signal;
        $event = $className::signal($this->eventBase, $signal, $func);
        if (!$event || !$event->add()) {
            return;
        }
        $this->eventSignal[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->eventSignal[$fdKey]->del();
            unset($this->eventSignal[$fdKey]);
            return true;
        }
        return false;
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
    public function getTimerCount(): int
    {
        return \count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler($errorHandler)
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * {@inheritdoc}
     */
    public function getErrorHandler(): ?callable
    {
        return $this->errorHandler;
    }

    /**
     * @param Throwable $e
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $e)
    {
        try {
            if (!$this->errorHandler) {
                throw new $e;
            }
            ($this->errorHandler)($e);
        } catch (\Throwable $e) {
            // Cannot trigger an exception in the Event callback, otherwise it will cause an infinite loop
            echo $e;
        }
    }
}
