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
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * AsyncUdpConnection.
 */
class AsyncUdpConnection extends UdpConnection
{
    /**
     * Emitted when socket connection is successfully established.
     *
     * @var callable
     */
    public $onConnect = null;

    /**
     * Emitted when socket connection closed.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Connected or not.
     *
     * @var bool
     */
    protected $connected = false;

    /**
     * Context option.
     *
     * @var array
     */
    protected $contextOption = null;

    /**
     * Construct.
     *
     * @param string $remoteAddress
     * @throws Exception
     */
    public function __construct($remoteAddress, $contextOption = null)
    {
        // Get the application layer communication protocol and listening address.
        list($scheme, $address) = \explode(':', $remoteAddress, 2);
        // Check application layer protocol class.
        if ($scheme !== 'udp') {
            $scheme = \ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "\\Workerman\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        }

        $this->remoteAddress = \substr($address, 2);
        $this->contextOption = $contextOption;
    }

    /**
     * For udp package.
     *
     * @param resource $socket
     * @return bool
     */
    public function baseRead($socket)
    {
        $recvBuffer = \stream_socket_recvfrom($socket, Worker::MAX_UDP_PACKAGE_SIZE, 0, $remoteAddress);
        if (false === $recvBuffer || empty($remoteAddress)) {
            return false;
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
        return true;
    }

    /**
     * Sends data on the connection.
     *
     * @param string $sendBuffer
     * @param bool $raw
     * @return void|boolean
     */
    public function send($sendBuffer, $raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser = $this->protocol;
            $sendBuffer = $parser::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return;
            }
        }
        if ($this->connected === false) {
            $this->connect();
        }
        return \strlen($sendBuffer) === \stream_socket_sendto($this->socket, $sendBuffer, 0);
    }


    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool $raw
     *
     * @return bool
     * @throws Throwable
     */
    public function close($data = null, $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        $this->eventLoop->offReadable($this->socket);
        \fclose($this->socket);
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
        return true;
    }

    /**
     * Connect.
     *
     * @return void
     */
    public function connect()
    {
        if ($this->connected === true) {
            return;
        }
        if (!$this->eventLoop) {
            $this->eventLoop = Worker::$globalEvent;
        }
        if ($this->contextOption) {
            $context = \stream_context_create($this->contextOption);
            $this->socket = \stream_socket_client("udp://{$this->remoteAddress}", $errno, $errmsg,
                30, \STREAM_CLIENT_CONNECT, $context);
        } else {
            $this->socket = \stream_socket_client("udp://{$this->remoteAddress}", $errno, $errmsg);
        }

        if (!$this->socket) {
            Worker::safeEcho(new \Exception($errmsg));
            return;
        }

        \stream_set_blocking($this->socket, false);

        if ($this->onMessage) {
            $this->eventLoop->onWritable($this->socket, [$this, 'baseRead']);
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
