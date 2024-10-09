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

namespace Workerman\Connection;

use Throwable;
use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use AllowDynamicProperties;

/**
 * ConnectionInterface.
 */
#[AllowDynamicProperties]
abstract class ConnectionInterface
{
    /**
     * Connect failed.
     *
     * @var int
     */
    public const CONNECT_FAIL = 1;

    /**
     * Send failed.
     *
     * @var int
     */
    public const SEND_FAIL = 2;

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
     * @var ?class-string
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
    public ?EventInterface $eventLoop = null;

    /**
     * @var ?callable
     */
    public $errorHandler = null;

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return bool|null
     */
    abstract public function send(mixed $sendBuffer, bool $raw = false): bool|null;

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
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    abstract public function close(mixed $data = null, bool $raw = false): void;

    /**
     * Is ipv4.
     *
     * return bool.
     */
    abstract public function isIpV4(): bool;

    /**
     * Is ipv6.
     *
     * return bool.
     */
    abstract public function isIpV6(): bool;

    /**
     * @param Throwable $exception
     * @return void
     */
    public function error(Throwable $exception): void
    {
        if (!$this->errorHandler) {
            Worker::stopAll(250, $exception);
            return;
        }
        try {
            ($this->errorHandler)($exception);
        } catch (Throwable $exception) {
            Worker::stopAll(250, $exception);
            return;
        }
    }
}
