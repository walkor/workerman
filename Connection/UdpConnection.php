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

/**
 * UdpConnection.
 */
class UdpConnection extends ConnectionInterface
{
    /**
     * Application layer protocol.
     * The format is like this Workerman\\Protocols\\Http.
     *
     * @var \Workerman\Protocols\ProtocolInterface
     */
    public $protocol = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public $transport = 'udp';

    /**
     * Udp socket.
     *
     * @var resource
     */
    protected $_socket = null;

    /**
     * Remote address.
     *
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * Construct.
     *
     * @param resource $socket
     * @param string   $remote_address
     */
    public function __construct($socket, $remote_address)
    {
        $this->_socket        = $socket;
        $this->_remoteAddress = $remote_address;
    }

    /**
     * Sends data on the connection.
     *
     * @param string $send_buffer
     * @param bool   $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if (false === $raw && $this->protocol) {
            $parser      = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if ($send_buffer === '') {
                return;
            }
        }
        return \strlen($send_buffer) === \stream_socket_sendto($this->_socket, $send_buffer, 0, $this->isIpV6() ? '[' . $this->getRemoteIp() . ']:' . $this->getRemotePort() : $this->_remoteAddress);
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = \strrpos($this->_remoteAddress, ':');
        if ($pos) {
            return \trim(\substr($this->_remoteAddress, 0, $pos), '[]');
        }
        return '';
    }

    /**
     * Get remote port.
     *
     * @return int
     */
    public function getRemotePort()
    {
        if ($this->_remoteAddress) {
            return (int)\substr(\strrchr($this->_remoteAddress, ':'), 1);
        }
        return 0;
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteAddress()
    {
        return $this->_remoteAddress;
    }

    /**
     * Get local IP.
     *
     * @return string
     */
    public function getLocalIp()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return '';
        }
        return \substr($address, 0, $pos);
    }

    /**
     * Get local port.
     *
     * @return int
     */
    public function getLocalPort()
    {
        $address = $this->getLocalAddress();
        $pos = \strrpos($address, ':');
        if (!$pos) {
            return 0;
        }
        return (int)\substr(\strrchr($address, ':'), 1);
    }

    /**
     * Get local address.
     *
     * @return string
     */
    public function getLocalAddress()
    {
        return (string)@\stream_socket_get_name($this->_socket, false);
    }

    /**
     * Is ipv4.
     *
     * @return bool.
     */
    public function isIpV4()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') === false;
    }

    /**
     * Is ipv6.
     *
     * @return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool  $raw
     * @return bool
     */
    public function close($data = null, $raw = false)
    {
        if ($data !== null) {
            $this->send($data, $raw);
        }
        return true;
    }
    
    /**
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }
}
