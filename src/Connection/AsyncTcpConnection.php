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
use Workerman\Events\Select;
use Workerman\Timer;
use Workerman\Worker;
use \Exception;

/**
 * AsyncTcpConnection.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * Emitted when socket connection is successfully established.
     *
     * @var callable|null
     */
    public $onConnect = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Socks5 proxy.
     *
     * @var string
     */
    public $proxySocks5 = '';

    /**
     * Http proxy.
     *
     * @var string
     */
    public $proxyHttp = '';

    /**
     * Status.
     *
     * @var int
     */
    protected $status = self::STATUS_INITIAL;

    /**
     * Remote host.
     *
     * @var string
     */
    protected $remoteHost = '';

    /**
     * Remote port.
     *
     * @var int
     */
    protected $remotePort = 80;

    /**
     * Connect start time.
     *
     * @var float
     */
    protected $connectStartTime = 0;

    /**
     * Remote URI.
     *
     * @var string
     */
    protected $remoteURI = '';

    /**
     * Context option.
     *
     * @var array
     */
    protected $contextOption = null;

    /**
     * Reconnect timer.
     *
     * @var int
     */
    protected $reconnectTimer = null;


    /**
     * PHP built-in protocols.
     *
     * @var array<string,string>
     */
    const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls' => 'tls'
    ];

    /**
     * Construct.
     *
     * @param string $remoteAddress
     * @param array $contextOption
     * @throws Exception
     */
    public function __construct($remoteAddress, array $contextOption = [])
    {
        $addressInfo = \parse_url($remoteAddress);
        if (!$addressInfo) {
            list($scheme, $this->remoteAddress) = \explode(':', $remoteAddress, 2);
            if ('unix' === strtolower($scheme)) {
                $this->remoteAddress = substr($remoteAddress, strpos($remoteAddress, '/') + 2);
            }
            if (!$this->remoteAddress) {
                Worker::safeEcho(new \Exception('bad remote_address'));
            }
        } else {
            if (!isset($addressInfo['port'])) {
                $addressInfo['port'] = 0;
            }
            if (!isset($addressInfo['path'])) {
                $addressInfo['path'] = '/';
            }
            if (!isset($addressInfo['query'])) {
                $addressInfo['query'] = '';
            } else {
                $addressInfo['query'] = '?' . $addressInfo['query'];
            }
            $this->remoteHost = $addressInfo['host'];
            $this->remotePort = $addressInfo['port'];
            $this->remoteURI = "{$addressInfo['path']}{$addressInfo['query']}";
            $scheme = $addressInfo['scheme'] ?? 'tcp';
            $this->remoteAddress = 'unix' === strtolower($scheme)
                ? substr($remoteAddress, strpos($remoteAddress, '/') + 2)
                : $this->remoteHost . ':' . $this->remotePort;
        }

        $this->id = $this->realId = self::$idRecorder++;
        if (\PHP_INT_MAX === self::$idRecorder) {
            self::$idRecorder = 0;
        }
        // Check application layer protocol class.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = \ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!\class_exists($this->protocol)) {
                $this->protocol = "\\Workerman\\Protocols\\$scheme";
                if (!\class_exists($this->protocol)) {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::BUILD_IN_TRANSPORTS[$scheme];
        }

        // For statistics.
        ++self::$statistics['connection_count'];
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->contextOption = $contextOption;
        static::$connections[$this->realId] = $this;
        $this->context = new \stdClass;
    }

    /**
     * Do connect.
     *
     * @return void
     */
    public function connect()
    {
        if ($this->status !== self::STATUS_INITIAL && $this->status !== self::STATUS_CLOSING &&
            $this->status !== self::STATUS_CLOSED) {
            return;
        }

        $this->status = self::STATUS_CONNECTING;
        $this->connectStartTime = \microtime(true);
        if ($this->transport !== 'unix') {
            if (!$this->remotePort) {
                $this->remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->remoteAddress = $this->remoteHost . ':' . $this->remotePort;
            }
            // Open socket connection asynchronously.
            if ($this->proxySocks5){
                $this->contextOption['ssl']['peer_name'] = $this->remoteHost;
                $context = \stream_context_create($this->contextOption);
                $this->socket = \stream_socket_client("tcp://{$this->proxySocks5}", $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT, $context);
                fwrite($this->socket,chr(5) . chr(1) . chr(0));
                fread($this->socket, 512);
                fwrite($this->socket,chr(5) . chr(1) . chr(0) . chr(3) . chr(strlen($this->remoteHost)) . $this->remoteHost .  pack("n", $this->remotePort));
                fread($this->socket, 512);
            }else if($this->proxyHttp){
                $this->contextOption['ssl']['peer_name'] = $this->remoteHost;
                $context = \stream_context_create($this->contextOption);
                $this->socket = \stream_socket_client("tcp://{$this->proxyHttp}", $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT, $context);
                $str = "CONNECT {$this->remoteHost}:{$this->remotePort} HTTP/1.1\n";
                $str .= "Host: {$this->remoteHost}:{$this->remotePort}\n";
                $str .= "Proxy-Connection: keep-alive\n";
                fwrite($this->socket,$str);
                fread($this->socket, 512);
            } else if ($this->contextOption) {
                $context = \stream_context_create($this->contextOption);
                $this->socket = \stream_socket_client("tcp://{$this->remoteHost}:{$this->remotePort}",
                    $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else {
                $this->socket = \stream_socket_client("tcp://{$this->remoteHost}:{$this->remotePort}",
                    $errno, $errstr, 0, \STREAM_CLIENT_ASYNC_CONNECT);
            }
        } else {
            $this->socket = \stream_socket_client("{$this->transport}://{$this->remoteAddress}", $errno, $errstr, 0,
                \STREAM_CLIENT_ASYNC_CONNECT);
        }
        // If failed attempt to emit onError callback.
        if (!$this->socket || !\is_resource($this->socket)) {
            $this->emitError(static::CONNECT_FAIL, $errstr);
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Add socket to global event loop waiting connection is successfully established or faild.
        Worker::$globalEvent->onWritable($this->socket, [$this, 'checkConnection']);
        // For windows.
        if (\DIRECTORY_SEPARATOR === '\\' && Worker::$eventLoopClass === Select::class) {
            Worker::$globalEvent->onExcept($this->socket, [$this, 'checkConnection']);
        }
    }

    /**
     * Reconnect.
     *
     * @param int $after
     * @return void
     */
    public function reconnect($after = 0)
    {
        $this->status = self::STATUS_INITIAL;
        static::$connections[$this->realId] = $this;
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
        }
        if ($after > 0) {
            $this->reconnectTimer = Timer::add($after, [$this, 'connect'], null, false);
            return;
        }
        $this->connect();
    }

    /**
     * CancelReconnect.
     */
    public function cancelReconnect()
    {
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
        }
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteHost()
    {
        return $this->remoteHost;
    }

    /**
     * Get remote URI.
     *
     * @return string
     */
    public function getRemoteURI()
    {
        return $this->remoteURI;
    }

    /**
     * Try to emit onError callback.
     *
     * @param int $code
     * @param string $msg
     * @return void
     */
    protected function emitError($code, $msg)
    {
        $this->status = self::STATUS_CLOSING;
        if ($this->onError) {
            try {
                ($this->onError)($this, $code, $msg);
            } catch (\Throwable $e) {
                Worker::stopAll(250, $e);
            }
        }
    }

    /**
     * Check connection is successfully established or faild.
     *
     * @param resource $socket
     * @return void
     */
    public function checkConnection()
    {
        // Remove EV_EXPECT for windows.
        if (\DIRECTORY_SEPARATOR === '\\' && Worker::$eventLoopClass === Select::class) {
            Worker::$globalEvent->offExcept($this->socket);
        }
        // Remove write listener.
        Worker::$globalEvent->offWritable($this->socket);

        if ($this->status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = \stream_socket_get_name($this->socket, true)) {
            // Nonblocking.
            \stream_set_blocking($this->socket, false);
            // Compatible with hhvm
            if (\function_exists('stream_set_read_buffer')) {
                \stream_set_read_buffer($this->socket, 0);
            }
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (\function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $rawSocket = \socket_import_stream($this->socket);
                \socket_set_option($rawSocket, \SOL_SOCKET, \SO_KEEPALIVE, 1);
                \socket_set_option($rawSocket, \SOL_TCP, \TCP_NODELAY, 1);
            }
            // SSL handshake.
            if ($this->transport === 'ssl') {
                $this->sslHandshakeCompleted = $this->doSslHandshake($this->socket);
                if ($this->sslHandshakeCompleted === false) {
                    return;
                }
            } else {
                // There are some data waiting to send.
                if ($this->sendBuffer) {
                    Worker::$globalEvent->onWritable($this->socket, [$this, 'baseWrite']);
                }
            }
            // Register a listener waiting read event.
            Worker::$globalEvent->onReadable($this->socket, [$this, 'baseRead']);

            $this->status = self::STATUS_ESTABLISHED;
            $this->remoteAddress = $address;

            // Try to emit onConnect callback.
            if ($this->onConnect) {
                try {
                    ($this->onConnect)($this);
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
            // Try to emit protocol::onConnect
            if ($this->protocol && \method_exists($this->protocol, 'onConnect')) {
                try {
                    [$this->protocol, 'onConnect']($this);
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(static::CONNECT_FAIL, 'connect ' . $this->remoteAddress . ' fail after ' . round(\microtime(true) - $this->connectStartTime, 4) . ' seconds');
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }

    }
}
