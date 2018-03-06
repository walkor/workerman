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

use Workerman\Events\EventInterface;
use Workerman\Lib\Timer;
use Workerman\Worker;
use Exception;

/**
 * AsyncTcpConnection.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * Emitted when socket connection is successfully established.
     *
     * @var callback
     */
    public $onConnect = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Status.
     *
     * @var int
     */
    protected $_status = self::STATUS_INITIAL;

    /**
     * Remote host.
     *
     * @var string
     */
    protected $_remoteHost = '';

    /**
     * Remote port.
     *
     * @var int
     */
    protected $_remotePort = 80;

    /**
     * Connect start time.
     *
     * @var string
     */
    protected $_connectStartTime = 0;

    /**
     * Remote URI.
     *
     * @var string
     */
    protected $_remoteURI = '';

    /**
     * Context option.
     *
     * @var resource
     */
    protected $_contextOption = null;

    /**
     * Reconnect timer.
     *
     * @var int
     */
    protected $_reconnectTimer = null;


    /**
     * PHP built-in protocols.
     *
     * @var array
     */
    protected static $_builtinTransports = array(
        'tcp'   => 'tcp',
        'udp'   => 'udp',
        'unix'  => 'unix',
        'ssl'   => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls'   => 'tls'
    );

    /**
     * Construct.
     *
     * @param string $remote_address
     * @param array $context_option
     * @throws Exception
     */
    public function __construct($remote_address, $context_option = null)
    {
        $address_info = parse_url($remote_address);
        if (!$address_info) {
            list($scheme, $this->_remoteAddress) = explode(':', $remote_address, 2);
            if (!$this->_remoteAddress) {
                echo new \Exception('bad remote_address');
            }
        } else {
            if (!isset($address_info['port'])) {
                $address_info['port'] = 80;
            }
            if (!isset($address_info['path'])) {
                $address_info['path'] = '/';
            }
            if (!isset($address_info['query'])) {
                $address_info['query'] = '';
            } else {
                $address_info['query'] = '?' . $address_info['query'];
            }
            $this->_remoteAddress = "{$address_info['host']}:{$address_info['port']}";
            $this->_remoteHost    = $address_info['host'];
            $this->_remotePort    = $address_info['port'];
            $this->_remoteURI     = "{$address_info['path']}{$address_info['query']}";
            $scheme               = isset($address_info['scheme']) ? $address_info['scheme'] : 'tcp';
        }

        $this->id = $this->_id = self::$_idRecorder++;
        if(PHP_INT_MAX === self::$_idRecorder){
            self::$_idRecorder = 0;
        }
        // Check application layer protocol class.
        if (!isset(self::$_builtinTransports[$scheme])) {
            $scheme         = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\Workerman\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::$_builtinTransports[$scheme];
        }

        // For statistics.
        self::$statistics['connection_count']++;
        $this->maxSendBufferSize        = self::$defaultMaxSendBufferSize;
        $this->_contextOption           = $context_option;
        static::$connections[$this->id] = $this;
    }

    /**
     * Do connect.
     *
     * @return void 
     */
    public function connect()
    {
        if ($this->_status !== self::STATUS_INITIAL && $this->_status !== self::STATUS_CLOSING &&
             $this->_status !== self::STATUS_CLOSED) {
            return;
        }
        $this->_status           = self::STATUS_CONNECTING;
        $this->_connectStartTime = microtime(true);
        if ($this->transport !== 'unix') {
            // Open socket connection asynchronously.
            if ($this->_contextOption) {
                $context = stream_context_create($this->_contextOption);
                $this->_socket = stream_socket_client("{$this->transport}://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else {
                $this->_socket = stream_socket_client("{$this->transport}://{$this->_remoteHost}:{$this->_remotePort}",
                    $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
            }
        } else {
            $this->_socket = stream_socket_client("{$this->transport}://{$this->_remoteAddress}", $errno, $errstr, 0,
                STREAM_CLIENT_ASYNC_CONNECT);
        }
        // If failed attempt to emit onError callback.
        if (!$this->_socket) {
            $this->emitError(WORKERMAN_CONNECT_FAIL, $errstr);
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Add socket to global event loop waiting connection is successfully established or faild. 
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
        // For windows.
        if(DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_EXCEPT, array($this, 'checkConnection'));
        }
    }

    /**
     * Reconnect.
     *
     * @param int $after
     * @return void
     */
    public function reConnect($after = 0) {
        $this->_status = self::STATUS_INITIAL;
        if ($this->_reconnectTimer) {
            Timer::del($this->_reconnectTimer);
        }
        if ($after > 0) {
            $this->_reconnectTimer = Timer::add($after, array($this, 'connect'), null, false);
            return;
        }
        $this->connect();
    }

    /**
     * Get remote address.
     *
     * @return string 
     */
    public function getRemoteHost()
    {
        return $this->_remoteHost;
    }

    /**
     * Get remote URI.
     *
     * @return string
     */
    public function getRemoteURI()
    {
        return $this->_remoteURI;
    }

    /**
     * Try to emit onError callback.
     *
     * @param int    $code
     * @param string $msg
     * @return void
     */
    protected function emitError($code, $msg)
    {
        $this->_status = self::STATUS_CLOSING;
        if ($this->onError) {
            try {
                call_user_func($this->onError, $this, $code, $msg);
            } catch (\Exception $e) {
                Worker::log($e);
                exit(250);
            } catch (\Error $e) {
                Worker::log($e);
                exit(250);
            }
        }
    }

    /**
     * Check connection is successfully established or faild.
     *
     * @param resource $socket
     * @return void
     */
    public function checkConnection($socket)
    {
        // Remove EV_EXPECT for windows.
        if(DIRECTORY_SEPARATOR === '\\') {
            Worker::$globalEvent->del($socket, EventInterface::EV_EXCEPT);
        }
        // Check socket state.
        if ($address = stream_socket_get_name($socket, true)) {
            // Remove write listener.
            Worker::$globalEvent->del($socket, EventInterface::EV_WRITE);
            // Nonblocking.
            stream_set_blocking($socket, 0);
            // Compatible with hhvm
            if (function_exists('stream_set_read_buffer')) {
                stream_set_read_buffer($socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $raw_socket = socket_import_stream($socket);
                socket_set_option($raw_socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($raw_socket, SOL_TCP, TCP_NODELAY, 1);
            }
            // Register a listener waiting read event.
            Worker::$globalEvent->add($socket, EventInterface::EV_READ, array($this, 'baseRead'));
            // There are some data waiting to send.
            if ($this->_sendBuffer) {
                Worker::$globalEvent->add($socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            $this->_status                = self::STATUS_ESTABLISHED;
            $this->_remoteAddress         = $address;
            $this->_sslHandshakeCompleted = true;

            // Try to emit onConnect callback.
            if ($this->onConnect) {
                try {
                    call_user_func($this->onConnect, $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
            // Try to emit protocol::onConnect
            if (method_exists($this->protocol, 'onConnect')) {
                try {
                    call_user_func(array($this->protocol, 'onConnect'), $this);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(WORKERMAN_CONNECT_FAIL, 'connect ' . $this->_remoteAddress . ' fail after ' . round(microtime(true) - $this->_connectStartTime, 4) . ' seconds');
            if ($this->_status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->_status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
