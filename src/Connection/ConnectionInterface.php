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

namespace Workerman\Connection;

use Throwable;
use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Worker;

/**
 * ConnectionInterface.
 */
#[\AllowDynamicProperties]
abstract class ConnectionInterface
{
    /**
     * Connect failed.
     *
     * @var int
     */
    const CONNECT_FAIL = 1;

    /**
     * Send failed.
     *
     * @var int
     */
    const SEND_FAIL = 2;

    /**
     * Statistics for status command.
     *
     * @var array
     */
    public static array $statistics = [
        'connection_count' => 0,
        'total_request' => 0,
        'throw_exception' => 0,
        'send_fail' => 0,
    ];

    /**
     * Application layer protocol.
     * The format is like this Workerman\\Protocols\\Http.
     *
     * @var ?string
     */
    public ?string $protocol = null;

    /**
     * Emitted when data is received.
     *
     * @var ?callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var ?callable
     */
    public $onError = null;

    /**
     * @var ?EventInterface
     */
    public ?EventInterface $eventLoop;

    /**
     * @var ?callable
     */
    public $errorHandler = null;

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return void|boolean
     */
    abstract public function send(mixed $sendBuffer, bool $raw = false);

    /**
     * Get remote IP.
     *
     * @return string
     */
    abstract public function getRemoteIp(): string;

    /**
     * Get remote port.
     *
     * @return int
     */
    abstract public function getRemotePort(): int;

    /**
     * Get remote address.
     *
     * @return string
     */
    abstract public function getRemoteAddress(): string;

    /**
     * Get local IP.
     *
     * @return string
     */
    abstract public function getLocalIp(): string;

    /**
     * Get local port.
     *
     * @return int
     */
    abstract public function getLocalPort(): int;

    /**
     * Get local address.
     *
     * @return string
     */
    abstract public function getLocalAddress(): string;

    /**
     * Close connection.
     *
     * @param mixed|null $data
     * @return void
     */
    abstract public function close(mixed $data = null, bool $raw = false);

    /**
     * Is ipv4.
     *
     * return bool.
     */
    public function isIpV4(): bool
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return !str_contains($this->getRemoteIp(), ':');
    }

    /**
     * Is ipv6.
     *
     * return bool.
     */
    public function isIpV6(): bool
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return str_contains($this->getRemoteIp(), ':');
    }

    /**
     * @param Throwable $exception
     * @return void
     * @throws Throwable
     */
    public function error(Throwable $exception)
    {
        if (!$this->errorHandler) {
            Worker::stopAll(250, $exception);
            return;
        }
        try {
            ($this->errorHandler)($exception);
        } catch (Throwable $exception) {
            if ($this->eventLoop instanceof Event) {
                echo $exception;
                return;
            }
            throw $exception;
        }
    }

}
