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

declare(strict_types=1);

namespace Workerman\Events;

/**
 * Ev eventloop
 */
final class Ev implements EventInterface
{
    /**
     * All listeners for read event.
     *
     * @var array<int, \EvIo>
     */
    private array $readEvents = [];

    /**
     * All listeners for write event.
     *
     * @var array<int, \EvIo>
     */
    private array $writeEvents = [];

    /**
     * Event listeners of signal.
     *
     * @var array<int, \EvSignal>
     */
    private array $eventSignal = [];

    /**
     * All timer event listeners.
     *
     * @var array<int, \EvTimer>
     */
    private array $eventTimer = [];

    /**
     * @var ?callable
     */
    private $errorHandler = null;

    /**
     * Timer id.
     *
     * @var int
     */
    private static int $timerId = 1;

    /**
     * {@inheritdoc}
     */
    public function delay(float $delay, callable $func, array $args = []): int
    {
        $timerId = self::$timerId;
        $event = new \EvTimer($delay, 0, function () use ($func, $args, $timerId) {
            unset($this->eventTimer[$timerId]);
            $this->safeCall($func, $args);
        });
        $this->eventTimer[self::$timerId] = $event;
        return self::$timerId++;
    }

    /**
     * {@inheritdoc}
     */
    public function offDelay(int $timerId): bool
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
    public function offRepeat(int $timerId): bool
    {
        return $this->offDelay($timerId);
    }

    /**
     * {@inheritdoc}
     */
    public function repeat(float $interval, callable $func, array $args = []): int
    {
        $event = new \EvTimer($interval, $interval, fn () => $this->safeCall($func, $args));
        $this->eventTimer[self::$timerId] = $event;
        return self::$timerId++;
    }

    /**
     * {@inheritdoc}
     */
    public function onReadable($stream, callable $func): void
    {
        $fdKey = (int)$stream;
        $event = new \EvIo($stream, \Ev::READ, fn () => $this->safeCall($func, [$stream]));
        $this->readEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offReadable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->readEvents[$fdKey])) {
            $this->readEvents[$fdKey]->stop();
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
        $event = new \EvIo($stream, \Ev::WRITE, fn () => $this->safeCall($func, [$stream]));
        $this->writeEvents[$fdKey] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offWritable($stream): bool
    {
        $fdKey = (int)$stream;
        if (isset($this->writeEvents[$fdKey])) {
            $this->writeEvents[$fdKey]->stop();
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
        $event = new \EvSignal($signal, fn () => $this->safeCall($func, [$signal]));
        $this->eventSignal[$signal] = $event;
    }

    /**
     * {@inheritdoc}
     */
    public function offSignal(int $signal): bool
    {
        if (isset($this->eventSignal[$signal])) {
            $this->eventSignal[$signal]->stop();
            unset($this->eventSignal[$signal]);
            return true;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     */
    public function deleteAllTimer(): void
    {
        foreach ($this->eventTimer as $event) {
            $event->stop();
        }
        $this->eventTimer = [];
    }

    /**
     * {@inheritdoc}
     */
    public function run(): void
    {
        \Ev::run();
    }

    /**
     * {@inheritdoc}
     */
    public function stop(): void
    {
        \Ev::stop();
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
        $this->errorHandler = $errorHandler;
    }

    /**
     * @param callable $func
     * @param array $args
     * @return void
     */
    private function safeCall(callable $func, array $args = []): void
    {
        try {
            $func(...$args);
        } catch (\Throwable $e) {
            if ($this->errorHandler === null) {
                echo $e;
            } else {
                ($this->errorHandler)($e);
            }
        }
    }
}
