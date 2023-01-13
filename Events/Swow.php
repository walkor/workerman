<?php

namespace Workerman\Events;

use RuntimeException;
use Swow\Coroutine;
use Swow\Signal;
use Swow\SignalException;
use Workerman\Worker;
use function getmypid;
use function max;
use function msleep;
use function stream_poll_one;
use function Swow\Sync\waitAll;
use const STREAM_POLLHUP;
use const STREAM_POLLIN;
use const STREAM_POLLNONE;
use const STREAM_POLLOUT;

class Swow implements EventInterface
{
    /**
     * All listeners for read timer
     * @var array
     */
    protected $_eventTimer = [];

    /**
     * All listeners for read event.
     * @var array<Coroutine>
     */
    protected $_readEvents = [];

    /**
     * All listeners for write event.
     * @var array<Coroutine>
     */
    protected $_writeEvents = [];

    /**
     * All listeners for signal.
     * @var array<Coroutine>
     */
    protected $_signalListener = [];

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->_eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, $func, $args)
    {
        $t = (int) ($delay * 1000);
        $t = max($t, 1);
        $coroutine = Coroutine::run(function () use ($t, $func, $args): void {
            msleep($t);
            unset($this->_eventTimer[Coroutine::getCurrent()->getId()]);
            try {
                $func(...(array) $args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $timer_id = $coroutine->getId();
        $this->_eventTimer[$timer_id] = $timer_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, $func, $args)
    {
        $t = (int) ($interval * 1000);
        $t = max($t, 1);
        $coroutine = Coroutine::run(static function () use ($t, $func, $args): void {
            while (true) {
                msleep($t);
                try {
                    $func(...(array) $args);
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
        });
        $timer_id = $coroutine->getId();
        $this->_eventTimer[$timer_id] = $timer_id;
        return $timer_id;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timer_id)
    {
        if (isset($this->_eventTimer[$timer_id])) {
            try {
                (Coroutine::getAll()[$timer_id])->kill();
                return true;
            } finally {
                unset($this->_eventTimer[$timer_id]);
            }
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->_eventTimer as $timer_id) {
            $this->deleteTimer($timer_id);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        if (isset($this->_readEvents[(int) $stream])) {
            $this->offReadable($stream);
        }
        $this->_readEvents[(int) $stream] = Coroutine::run(function () use ($stream, $func): void {
            try {
                while (true) {
                    $rEvent = stream_poll_one($stream, STREAM_POLLIN | STREAM_POLLHUP);
                    if ($rEvent !== STREAM_POLLNONE) {
                        $func($stream);
                    }
                    if ($rEvent !== STREAM_POLLIN) {
                        $this->offReadable($stream, bySelf: true);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offReadable($stream, bySelf: true);
            }
        });
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream, bool $bySelf = false)
    {
        $fd = (int) $stream;
        if (!isset($this->_readEvents[$fd])) {
            return;
        }
        if (!$bySelf) {
            $coroutine = $this->_readEvents[$fd];
            if (!$coroutine->isExecuting()) {
                return;
            }
            $coroutine->kill();
        }
        unset($this->_readEvents[$fd]);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        if (isset($this->_writeEvents[(int) $stream])) {
            $this->offWritable($stream);
        }
        $this->_writeEvents[(int) $stream] = Coroutine::run(function () use ($stream, $func): void {
            try {
                while (true) {
                    $rEvent = stream_poll_one($stream, STREAM_POLLOUT | STREAM_POLLHUP);
                    if ($rEvent !== STREAM_POLLNONE) {
                        $func($stream);
                    }
                    if ($rEvent !== STREAM_POLLOUT) {
                        $this->offWritable($stream, bySelf: true);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offWritable($stream, bySelf: true);
            }
        });
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream, bool $bySelf = false)
    {
        $fd = (int) $stream;
        if (!isset($this->_writeEvents[$fd])) {
            return;
        }
        if (!$bySelf) {
            $coroutine = $this->_writeEvents[$fd];
            if (!$coroutine->isExecuting()) {
                return;
            }
            $coroutine->kill();
        }
        unset($this->_writeEvents[$fd]);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        if (isset($this->_signalListener[$signal])) {
            return false;
        }
        $coroutine = Coroutine::run(static function () use ($signal, $func): void {
            try {
                Signal::wait($signal);
                $func($signal);
            } catch (SignalException) {
            }
        });
        $this->_signalListener[$signal] = $coroutine;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        if (!isset($this->_signalListener[$signal])) {
            return false;
        }
        $this->_signalListener[$signal]->kill();
        unset($this->_signalListener[$signal]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function run()
    {
        waitAll();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function stop()
    {
        Coroutine::getMain()->kill();
        Signal::kill(getmypid(), Signal::INT);
    }

    public function destroy()
    {
        $this->stop();
    }

    public function add($fd, $flag, $func, $args = [])
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                return $this->onSignal($fd, $func);
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                $method = self::EV_TIMER === $flag ? 'tick' : 'after';
                if ($method === 'tick') {
                    return $this->repeat($fd, $func, $args);
                } else {
                    return $this->delay($fd, $func, $args);
                }
            case self::EV_READ:
                return $this->onReadable($fd, $func);
            case self::EV_WRITE:
                return $this->onWritable($fd, $func);
        }
    }

    public function del($fd, $flag)
    {
        switch ($flag) {
            case self::EV_SIGNAL:
                return $this->offSignal($fd);
            case self::EV_TIMER:
            case self::EV_TIMER_ONCE:
                return $this->deleteTimer($fd);
            case self::EV_READ:
            case self::EV_WRITE:
                if ($flag === self::EV_READ) {
                    $this->offReadable($fd);
                } else {
                    $this->offWritable($fd);
                }
        }
    }

    public function clearAllTimer()
    {
        $this->deleteAllTimer();
    }

    public function loop()
    {
        waitAll();
    }
}