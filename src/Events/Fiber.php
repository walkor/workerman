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

declare(strict_types=1);

namespace Workerman\Events;

use Fiber as BaseFiber;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver;
use function count;
use function function_exists;
use function pcntl_signal;

/**
 * Revolt eventloop
 */
final class Fiber implements EventInterface
{
    /**
     * @var Driver
     */
    private Driver $driver;

    /**
     * All listeners for read event.
     *
     * @var array<int, string>
     */
    private array $readEvents = [];

    /**
     * All listeners for write event.
     *
     * @var array<int, string>
     */
    private array $writeEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array<int, string>
     */
    private array $eventSignal = [];

    /**
     * Event listeners of timer.
     *
     * @var array<int, string>
     */
    private array $eventTimer = [];

    /**
     * Timer id.
     *
     * @var int
     */
    private int $timerId = 1;

    /**
     * Construct.
     */
    public function __construct()
    {
        $this->driver = EventLoop::getDriver();
    }

    /**
     * Get driver.
     *
     * @return Driver
     */
    public function driver(): Driver
    {
        return $this->driver;
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        $this->driver->run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        foreach ($this->eventSignal as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->driver->stop();
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGINT, SIG_IGN);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $closure = function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $this->safeCall($func, ...$args);
        };
        $cbId = $this->driver->delay($delay, $closure);
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $timerId = $this->timerId++;
        $cbId = $this->driver->repeat($interval, fn() => $this->safeCall($func, ...$args));
        $this->eventTimer[$timerId] = $cbId;
        return $timerId;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->driver->cancel($this->readEvents[$fdKey]);
        }

        $this->readEvents[$fdKey] = $this->driver->onReadable($stream, fn() => $this->safeCall($func, $stream));
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->driver->cancel($this->readEvents[$fdKey]);
            unset($this->readEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onWritable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->driver->cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
        }
        $this->writeEvents[$fdKey] = $this->driver->onWritable($stream, fn() => $this->safeCall($func, $stream));
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->driver->cancel($this->writeEvents[$fdKey]);
            unset($this->writeEvents[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function onSignal(int $signal, callable $func): void
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->driver->cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
        }
        $this->eventSignal[$fdKey] = $this->driver->onSignal($signal, fn() => $this->safeCall($func, $signal));
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        $fdKey = $signal;
        if (isset($this->eventSignal[$fdKey])) {
            $this->driver->cancel($this->eventSignal[$fdKey]);
            unset($this->eventSignal[$fdKey]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
    {
        if (isset($this->eventTimer[$timerId])) {
            $this->driver->cancel($this->eventTimer[$timerId]);
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
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $cbId) {
            $this->driver->cancel($cbId);
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function getTimerCount(): int
    {
        return count($this->eventTimer);
    }

    /**
     * {@inheritdoc}
     */
    public function setErrorHandler(callable $errorHandler): void
    {
        $this->driver->setErrorHandler($errorHandler);
    }

    /**
     * @param callable $func
     * @param ...$args
     * @return void
     * @throws \Throwable
     */
    protected function safeCall(callable $func, ...$args): void
    {
        (new BaseFiber(fn() => $func(...$args)))->start();
    }
}
