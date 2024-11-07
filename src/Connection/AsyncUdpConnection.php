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

use Exception;
use RuntimeException;
use Throwable;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Worker;
use function class_exists;
use function explode;
use function fclose;
use function stream_context_create;
use function stream_set_blocking;
use function stream_socket_client;
use function stream_socket_recvfrom;
use function stream_socket_sendto;
use function strlen;
use function substr;
use function ucfirst;
use const STREAM_CLIENT_CONNECT;

/**
 * AsyncUdpConnection.
 */
class AsyncUdpConnection extends UdpConnection
{
    /**
     * Emitted when socket connection is successfully established.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Emitted when socket connection closed.
     *
     * @var ?callable
     */
    public $onClose = null;

    /**
     * Connected or not.
     *
     * @var bool
     */
    protected bool $connected = false;

    /**
     * Context option.
     *
     * @var array
     */
    protected array $contextOption = [];

    /**
     * Construct.
     *
     * @param string $remoteAddress
     * @throws Throwable
     */
    public function __construct($remoteAddress, $contextOption = [])
    {
        // Get the application layer communication protocol and listening address.
        [$scheme, $address] = explode(':', $remoteAddress, 2);
        // Check application layer protocol class.
        if ($scheme !== 'udp') {
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\Workerman\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new RuntimeException("class \\Protocols\\$scheme not exist");
                }
            }
        }

        $this->remoteAddress = substr($address, 2);
        $this->contextOption = $contextOption;
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return void
     */
    public function baseRead($socket): void
    {
        $recvBuffer = stream_socket_recvfrom($socket, static::MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
        if (false === $recvBuffer || empty($remoteAddress)) {
            return;
        }

        if ($this->onMessage) {
            if ($this->protocol) {
                $recvBuffer = $this->protocol::decode($recvBuffer, $this);
            }
            ++ConnectionInterface::$statistics['total_request'];
            try {
                ($this->onMessage)($this, $recvBuffer);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close(mixed $data = null, bool $raw = false): void
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop->offReadable($this->socket);
        fclose($this->socket);
        $this->connected = false;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
        $this->onConnect = $this->onMessage = $this->onClose = $this->eventLoop = $this->errorHandler = null;
    }

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return bool|null
     */
    public function send(mixed $sendBuffer, bool $raw = false): bool|null
    {
        if (false === $raw && $this->protocol) {
            $sendBuffer = $this->protocol::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return null;
            }
        }
        if ($this->connected === false) {
            $this->connect();
        }
        return strlen($sendBuffer) === stream_socket_sendto($this->socket, $sendBuffer);
    }

    /**
     * Connect.
     *
     * @return void
     */
    public function connect(): void
    {
        if ($this->connected === true) {
            return;
        }
        if (!$this->eventLoop) {
            $this->eventLoop = Worker::$globalEvent;
        }
        if ($this->contextOption) {
            $context = stream_context_create($this->contextOption);
            $this->socket = stream_socket_client("udp://$this->remoteAddress", $errno, $errmsg,
                30, STREAM_CLIENT_CONNECT, $context);
        } else {
            $this->socket = stream_socket_client("udp://$this->remoteAddress", $errno, $errmsg);
        }

        if (!$this->socket) {
            Worker::safeEcho((string)(new Exception($errmsg)));
            $this->eventLoop = null;
            return;
        }

        stream_set_blocking($this->socket, false);
        if ($this->onMessage) {
            $this->eventLoop->onReadable($this->socket, $this->baseRead(...));
        }
        $this->connected = true;
        // Try to emit onConnect callback.
        if ($this->onConnect) {
            try {
                ($this->onConnect)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }
}
