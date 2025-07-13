<?php

declare(strict_types=1);

namespace Workerman\Events;

use RuntimeException;
use Workerman\Coroutine\Coroutine\Swow as Coroutine;
use Swow\Signal;
use Swow\SignalException;
use function Swow\Sync\waitAll;

final class Swow implements EventInterface
{
    /**
     * All listeners for read timer.
     *
     * @var array<int, int>
     */
    private array $eventTimer = [];

    /**
     * All listeners for read event.
     *
     * @var array<int, Coroutine>
     */
    private array $readEvents = [];

    /**
     * All listeners for write event.
     *
     * @var array<int, Coroutine>
     */
    private array $writeEvents = [];

    /**
     * All listeners for signal.
     *
     * @var array<int, Coroutine>
     */
    private array $signalListener = [];

    /**
     * @var ?callable
     */
    private $errorHandler = null;

    /**
     * Get timer count.
     *
     * @return integer
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $t = (int)($delay * 1000);
        $t = max($t, 1);
        $coroutine = Coroutine::run(function () use ($t, $func, $args): void {
            msleep($t);
            unset($this->eventTimer[Coroutine::getCurrent()->getId()]);
            $this->safeCall($func, $args);
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $t = (int)($interval * 1000);
        $t = max($t, 1);
        $coroutine = Coroutine::run(function () use ($t, $func, $args): void {
            // @phpstan-ignore-next-line While loop condition is always true.
            while (true) {
                msleep($t);
                $this->safeCall($func, $args);
            }
        });
        $timerId = $coroutine->getId();
        $this->eventTimer[$timerId] = $timerId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
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
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $timerId) {
            $this->offDelay($timerId);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (isset($this->readEvents[$fd])) {
            $this->offReadable($stream);
        }
        Coroutine::run(function () use ($stream, $func, $fd): void {
            try {
                $this->readEvents[$fd] = Coroutine::getCurrent();
                while (true) {
                    if (!is_resource($stream)) {
                        $this->offReadable($stream);
                        break;
                    }
                    // Under Windows, setting a timeout is necessary; otherwise, the accept cannot be listened to.
                    // Setting it to 1000ms will result in a 1-second delay for the first accept under Windows.
                    if (!isset($this->readEvents[$fd]) || $this->readEvents[$fd] !== Coroutine::getCurrent()) {
                        break;
                    }
                    $rEvent = stream_poll_one($stream, STREAM_POLLIN | STREAM_POLLHUP, 1000);
                    if ($rEvent !== STREAM_POLLNONE) {
                        $this->safeCall($func, [$stream]);
                    }
                    if ($rEvent !== STREAM_POLLIN && $rEvent !== STREAM_POLLNONE) {
                        $this->offReadable($stream);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offReadable($stream);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        // 在当前协程执行 $coroutine->kill() 会导致不可预知问题，所以没有使用$coroutine->kill()
        $fd = (int)$stream;
        if (isset($this->readEvents[$fd])) {
            unset($this->readEvents[$fd]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fd = (int)$stream;
        if (isset($this->writeEvents[$fd])) {
            $this->offWritable($stream);
        }
        Coroutine::run(function () use ($stream, $func, $fd): void {
            try {
                $this->writeEvents[$fd] = Coroutine::getCurrent();
                while (true) {
                    if (!is_resource($stream)) {
                        $this->offWritable($stream);
                        break;
                    }
                    if (!isset($this->writeEvents[$fd]) || $this->writeEvents[$fd] !== Coroutine::getCurrent()) {
                        break;
                    }
                    $rEvent = stream_poll_one($stream, STREAM_POLLOUT | STREAM_POLLHUP, 1000);
                    if ($rEvent !== STREAM_POLLNONE) {
                        $this->safeCall($func, [$stream]);
                    }
                    if ($rEvent !== STREAM_POLLOUT && $rEvent !== STREAM_POLLNONE) {
                        $this->offWritable($stream);
                        break;
                    }
                }
            } catch (RuntimeException) {
                $this->offWritable($stream);
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fd = (int)$stream;
        if (isset($this->writeEvents[$fd])) {
            unset($this->writeEvents[$fd]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        Coroutine::run(function () use ($signal, $func): void {
            $this->signalListener[$signal] = Coroutine::getCurrent();
            while (1) {
                try {
                    Signal::wait($signal);
                    if (!isset($this->signalListener[$signal]) ||
                        $this->signalListener[$signal] !== Coroutine::getCurrent()) {
                        break;
                    }
                    $this->safeCall($func, [$signal]);
                } catch (SignalException) {
                    // do nothing
                }
            }
        });
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (!isset($this->signalListener[$signal])) {
            return false;
        }
        unset($this->signalListener[$signal]);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        waitAll();
    }

    /**
     * Destroy loop.
     *
     * @return void
     */
    public function stop(): void
    {
        Coroutine::killAll();
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->errorHandler = $errorHandler;
    }

    /**
     * @param callable $func
     * @param array $args
     * @return void
     */
    private function safeCall(callable $func, array $args = []): void
    {
        Coroutine::run(function () use ($func, $args): void {
            try {
                $func(...$args);
            } catch (\Throwable $e) {
                if ($this->errorHandler === null) {
                    echo $e;
                } else {
                    ($this->errorHandler)($e);
                }
            }
        });
    }

}
