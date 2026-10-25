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

use JsonSerializable;
use Workerman\Protocols\ProtocolInterface;
use function stream_socket_get_name;
use function stream_socket_sendto;
use function strlen;
use function strrchr;
use function strrpos;
use function substr;
use function trim;

/**
 * UdpConnection.
 */
class UdpConnection extends ConnectionInterface implements JsonSerializable
{
    /**
     * Max udp package size.
     *
     * @var int
     */
    public const MAX_UDP_PACKAGE_SIZE = 65535;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public string $transport = 'udp';

    /**
     * Whether the socket is connected (created via stream_socket_client).
     * On BSD/macOS, sendto() on a connected UDP socket with a destination address
     * returns EISCONN(-1). We must omit the address for connected sockets.
     */
    protected bool $connected = false;

    /**
     * @param resource|null $socket
     */
    public function __construct(
        /** @var resource|null */ protected $socket,
        protected string $remoteAddress = '')
    {
        if (is_resource($socket) && stream_socket_get_name($socket, true) !== false) {
            $this->connected = true;
        }
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
        if ($this->connected) {
            return strlen($sendBuffer) === stream_socket_sendto($this->socket, $sendBuffer);
        }
        return strlen($sendBuffer) === stream_socket_sendto($this->socket, $sendBuffer, 0, $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->remoteAddress);
    }

    /**
     * Send a udp packet to the given ip / port using this connection's socket
     * as the source. Unlike send(), which replies to the remote address of the
     * incoming packet, sendTo() lets the caller pick an arbitrary destination.
     *
     * This method only exists on UdpConnection. TcpConnection has no such API
     * because a tcp connection is bound to a single peer.
     *
     * It can be used anywhere, including inside onMessage, onWorkerStart,
     * timers and channel callbacks. To send proactively from onWorkerStart,
     * obtain a reusable sender via Worker::getUdpSender():
     *
     *     $sender = $worker->getUdpSender();
     *     Timer::add(1, function () use ($sender) {
     *         $sender->sendTo('192.168.1.7', 5060, "ipv4 heartbeat");
     *         $sender->sendTo('fe80::1', 5060, "ipv6 heartbeat");
     *     });
     *
     * @param string $ip    Target ip, ipv4 or ipv6 (with or without brackets).
     * @param int    $port  Target port, 1-65535.
     * @param mixed  $data  Payload. Passed through protocol::encode() when
     *                      $raw is false and a protocol is set.
     * @param bool   $raw   Skip protocol encoding when true.
     * @return bool         True when the whole payload was handed to the kernel.
     */
    public function sendTo(string $ip, int $port, mixed $data, bool $raw = false): bool
    {
        if (!is_resource($this->socket)) {
            return false;
        }
        if ($ip === '' || $port < 1 || $port > 65535) {
            return false;
        }
        if (false === $raw && $this->protocol) {
            $data = $this->protocol::encode($data, $this);
            if ($data === '') {
                return true;
            }
        }
        if (!is_string($data)) {
            $data = (string)$data;
        }
        // Add brackets for bare IPv6, keep already bracketed form intact.
        if (strpos($ip, ':') !== false && $ip[0] !== '[') {
            $address = "[{$ip}]:{$port}";
        } else {
            $address = "{$ip}:{$port}";
        }
        set_error_handler(static fn (): bool => true);
        $sent = stream_socket_sendto($this->socket, $data, 0, $address);
        restore_error_handler();
        return strlen($data) === $sent;
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp(): string
    {
        $pos = strrpos($this->remoteAddress, ':');
        if ($pos) {
            return trim(substr($this->remoteAddress, 0, $pos), '[]');
        }
        return '';
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort(): int
    {
        if ($this->remoteAddress) {
            return (int)substr(strrchr($this->remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteAddress(): string
    {
        return $this->remoteAddress;
    }

    /**
     * Get local IP.
     *
     * @return string
     */
    public function getLocalIp(): string
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return substr($address, 0, $pos);
    }

    /**
     * Get local port.
     *
     * @return int
     */
    public function getLocalPort(): int
    {
        $address = $this->getLocalAddress();
        $pos = strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)substr(strrchr($address, ':'), 1);
    }

    /**
     * Get local address.
     *
     * @return string
     */
    public function getLocalAddress(): string
    {
        return is_resource($this->socket) ? (string)@stream_socket_get_name($this->socket, false) : '';
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
        if ($this->eventLoop) {
            $this->eventLoop->offReadable($this->socket);
        }
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->eventLoop = $this->errorHandler = null;
    }

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
     * Get the real socket.
     *
     * @return resource
     */
    /**
     * @return resource|null
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Get the json_encode information.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'transport' => $this->transport,
            'getRemoteIp' => $this->getRemoteIp(),
            'remotePort' => $this->getRemotePort(),
            'getRemoteAddress' => $this->getRemoteAddress(),
            'getLocalIp' => $this->getLocalIp(),
            'getLocalPort' => $this->getLocalPort(),
            'getLocalAddress' => $this->getLocalAddress(),
            'isIpV4' => $this->isIpV4(),
            'isIpV6' => $this->isIpV6(),
        ];
    }
}
