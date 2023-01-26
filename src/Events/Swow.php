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
    protected $eventTimer = [];

    /**
     * All listeners for read event.
     * @var array<Coroutine>
     */
    protected $readEvents = [];

    /**
     * All listeners for write event.
     * @var array<Coroutine>
     */
    protected $writeEvents = [];

    /**
     * All listeners for signal.
     * @var array<Coroutine>
     */
    protected $signalListener = [];

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount()
    {
        return \count($this->eventTimer);
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
            unset($this->eventTimer[Coroutine::getCurrent()->getId()]);
            try {
                $func(...(array) $args);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
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
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteTimer($timerId)
    {
        if (isset($this->eventTimer[$timerId])) {
            try {
                (Coroutine::getAll()[$timerId])->kill();
                return true;
            } finally {
                unset($this->eventTimer[$timerId]);
            }
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer()
    {
        foreach ($this->eventTimer as $timerId) {
            $this->deleteTimer($timerId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, $func)
    {
        if (isset($this->readEvents[(int) $stream])) {
            $this->offReadable($stream);
        }
        $this->readEvents[(int) $stream] = Coroutine::run(function () use ($stream, $func): void {
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
        if (!isset($this->readEvents[$fd])) {
            return;
        }
        if (!$bySelf) {
            $coroutine = $this->readEvents[$fd];
            if (!$coroutine->isExecuting()) {
                return;
            }
            $coroutine->kill();
        }
        unset($this->readEvents[$fd]);
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, $func)
    {
        if (isset($this->writeEvents[(int) $stream])) {
            $this->offWritable($stream);
        }
        $this->writeEvents[(int) $stream] = Coroutine::run(function () use ($stream, $func): void {
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
        if (!isset($this->writeEvents[$fd])) {
            return;
        }
        if (!$bySelf) {
            $coroutine = $this->writeEvents[$fd];
            if (!$coroutine->isExecuting()) {
                return;
            }
            $coroutine->kill();
        }
        unset($this->writeEvents[$fd]);
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal($signal, $func)
    {
        if (isset($this->signalListener[$signal])) {
            return false;
        }
        $coroutine = Coroutine::run(static function () use ($signal, $func): void {
            while (1) {
                try {
                    Signal::wait($signal);
                    $func($signal);
                } catch (SignalException) {}
            }
        });
        $this->signalListener[$signal] = $coroutine;
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal($signal)
    {
        if (!isset($this->signalListener[$signal])) {
            return false;
        }
        $this->signalListener[$signal]->kill();
        unset($this->signalListener[$signal]);
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

    public function clearAllTimer()
    {
        $this->deleteAllTimer();
    }

    public function loop()
    {
        waitAll();
    }
}