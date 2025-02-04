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
use stdClass;
use Throwable;
use Workerman\Timer;
use Workerman\Worker;
use function class_exists;
use function explode;
use function function_exists;
use function is_resource;
use function method_exists;
use function microtime;
use function parse_url;
use function socket_import_stream;
use function socket_set_option;
use function stream_context_create;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_client;
use function stream_socket_get_name;
use function ucfirst;
use const DIRECTORY_SEPARATOR;
use const PHP_INT_MAX;
use const SO_KEEPALIVE;
use const SOL_SOCKET;
use const SOL_TCP;
use const STREAM_CLIENT_ASYNC_CONNECT;
use const TCP_NODELAY;

/**
 * AsyncTcpConnection.
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * PHP built-in protocols.
     *
     * @var array<string, string>
     */
    public const BUILD_IN_TRANSPORTS = [
        'tcp' => 'tcp',
        'udp' => 'udp',
        'unix' => 'unix',
        'ssl' => 'ssl',
        'sslv2' => 'sslv2',
        'sslv3' => 'sslv3',
        'tls' => 'tls'
    ];

    /**
     * Emitted when socket connection is successfully established.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Emitted when websocket handshake completed (Only work when protocol is ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Transport layer protocol.
     *
     * @var string
     */
    public string $transport = 'tcp';

    /**
     * Socks5 proxy.
     *
     * @var string
     */
    public string $proxySocks5 = '';

    /**
     * Http proxy.
     *
     * @var string
     */
    public string $proxyHttp = '';

    /**
     * Status.
     *
     * @var int
     */
    protected int $status = self::STATUS_INITIAL;

    /**
     * Remote host.
     *
     * @var string
     */
    protected string $remoteHost = '';

    /**
     * Remote port.
     *
     * @var int
     */
    protected int $remotePort = 80;

    /**
     * Connect start time.
     *
     * @var float
     */
    protected float $connectStartTime = 0;

    /**
     * Remote URI.
     *
     * @var string
     */
    protected string $remoteURI = '';

    /**
     * Context option.
     *
     * @var array
     */
    protected array $socketContext = [];

    /**
     * Reconnect timer.
     *
     * @var int
     */
    protected int $reconnectTimer = 0;

    /**
     * Construct.
     *
     * @param string $remoteAddress
     * @param array $socketContext
     */
    public function __construct(string $remoteAddress, array $socketContext = [])
    {
        $addressInfo = parse_url($remoteAddress);
        if (!$addressInfo) {
            [$scheme, $this->remoteAddress] = explode(':', $remoteAddress, 2);
            if ('unix' === strtolower($scheme)) {
                $this->remoteAddress = substr($remoteAddress, strpos($remoteAddress, '/') + 2);
            }
            if (!$this->remoteAddress) {
                throw new RuntimeException('Bad remoteAddress');
            }
        } else {
            $addressInfo['port'] ??= 0;
            $addressInfo['path'] ??= '/';
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
        if (PHP_INT_MAX === self::$idRecorder) {
            self::$idRecorder = 0;
        }
        // Check application layer protocol class.
        if (!isset(self::BUILD_IN_TRANSPORTS[$scheme])) {
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\' . $scheme;
            if (!class_exists($this->protocol)) {
                $this->protocol = "\\Workerman\\Protocols\\$scheme";
                if (!class_exists($this->protocol)) {
                    throw new RuntimeException("class \\Protocols\\$scheme not exist");
                }
            }
        } else {
            $this->transport = self::BUILD_IN_TRANSPORTS[$scheme];
        }

        // For statistics.
        ++self::$statistics['connection_count'];
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->socketContext = $socketContext;
        static::$connections[$this->realId] = $this;
        $this->context = new stdClass;
    }

    /**
     * Reconnect.
     *
     * @param int $after
     * @return void
     */
    public function reconnect(int $after = 0): void
    {
        $this->status = self::STATUS_INITIAL;
        static::$connections[$this->realId] = $this;
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
        }
        if ($after > 0) {
            $this->reconnectTimer = Timer::add($after, $this->connect(...), null, false);
            return;
        }
        $this->connect();
    }

    /**
     * Do connect.
     *
     * @return void
     */
    public function connect(): void
    {
        if ($this->status !== self::STATUS_INITIAL && $this->status !== self::STATUS_CLOSING &&
            $this->status !== self::STATUS_CLOSED) {
            return;
        }

        if (!$this->eventLoop) {
            $this->eventLoop = Worker::$globalEvent;
        }

        $this->status = self::STATUS_CONNECTING;
        $this->connectStartTime = microtime(true);
        set_error_handler(fn() => false);
        if ($this->transport !== 'unix') {
            if (!$this->remotePort) {
                $this->remotePort = $this->transport === 'ssl' ? 443 : 80;
                $this->remoteAddress = $this->remoteHost . ':' . $this->remotePort;
            }
            // Open socket connection asynchronously.
            if ($this->proxySocks5) {
                $this->socketContext['ssl']['peer_name'] = $this->remoteHost;
                $context = stream_context_create($this->socketContext);
                $this->socket = stream_socket_client("tcp://$this->proxySocks5", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else if ($this->proxyHttp) {
                $this->socketContext['ssl']['peer_name'] = $this->remoteHost;
                $context = stream_context_create($this->socketContext);
                $this->socket = stream_socket_client("tcp://$this->proxyHttp", $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else if ($this->socketContext) {
                $context = stream_context_create($this->socketContext);
                $this->socket = stream_socket_client("tcp://$this->remoteHost:$this->remotePort",
                    $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT, $context);
            } else {
                $this->socket = stream_socket_client("tcp://$this->remoteHost:$this->remotePort",
                    $errno, $err_str, 0, STREAM_CLIENT_ASYNC_CONNECT);
            }
        } else {
            $this->socket = stream_socket_client("$this->transport://$this->remoteAddress", $errno, $err_str, 0,
                STREAM_CLIENT_ASYNC_CONNECT);
        }
        restore_error_handler();
        // If failed attempt to emit onError callback.
        if (!$this->socket || !is_resource($this->socket)) {
            $this->emitError(static::CONNECT_FAIL, $err_str);
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
            return;
        }
        // Add socket to global event loop waiting connection is successfully established or failed.
        $this->eventLoop->onWritable($this->socket, $this->checkConnection(...));
        // For windows.
        if (DIRECTORY_SEPARATOR === '\\' && method_exists($this->eventLoop, 'onExcept')) {
            $this->eventLoop->onExcept($this->socket, $this->checkConnection(...));
        }
    }

    /**
     * Try to emit onError callback.
     *
     * @param int $code
     * @param mixed $msg
     * @return void
     */
    protected function emitError(int $code, mixed $msg): void
    {
        $this->status = self::STATUS_CLOSING;
        if ($this->onError) {
            try {
                ($this->onError)($this, $code, $msg);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * CancelReconnect.
     */
    public function cancelReconnect(): void
    {
        if ($this->reconnectTimer) {
            Timer::del($this->reconnectTimer);
            $this->reconnectTimer = 0;
        }
    }

    /**
     * Get remote address.
     *
     * @return string
     */
    public function getRemoteHost(): string
    {
        return $this->remoteHost;
    }

    /**
     * Get remote URI.
     *
     * @return string
     */
    public function getRemoteURI(): string
    {
        return $this->remoteURI;
    }

    /**
     * Check connection is successfully established or failed.
     *
     * @return void
     */
    public function checkConnection(): void
    {
        // Remove EV_EXPECT for windows.
        if (DIRECTORY_SEPARATOR === '\\' && method_exists($this->eventLoop, 'offExcept')) {
            $this->eventLoop->offExcept($this->socket);
        }
        // Remove write listener.
        $this->eventLoop->offWritable($this->socket);

        if ($this->status !== self::STATUS_CONNECTING) {
            return;
        }

        // Check socket state.
        if ($address = stream_socket_get_name($this->socket, true)) {
            // Proxy
            if ($this->proxySocks5 && $address === $this->proxySocks5) {
                fwrite($this->socket, chr(5) . chr(1) . chr(0));
                fread($this->socket, 512);
                fwrite($this->socket, chr(5) . chr(1) . chr(0) . chr(3) . chr(strlen($this->remoteHost)) . $this->remoteHost . pack("n", $this->remotePort));
                fread($this->socket, 512);
            }
            if ($this->proxyHttp && $address === $this->proxyHttp) {
                $str = "CONNECT $this->remoteHost:$this->remotePort HTTP/1.1\r\n";
                $str .= "Host: $this->remoteHost:$this->remotePort\r\n";
                $str .= "Proxy-Connection: keep-alive\r\n\r\n";
                fwrite($this->socket, $str);
                fread($this->socket, 512);
            }
            // Nonblocking.
            stream_set_blocking($this->socket, false);
            stream_set_read_buffer($this->socket, 0);
            // Try to open keepalive for tcp and disable Nagle algorithm.
            if (function_exists('socket_import_stream') && $this->transport === 'tcp') {
                $socket = socket_import_stream($this->socket);
                socket_set_option($socket, SOL_SOCKET, SO_KEEPALIVE, 1);
                socket_set_option($socket, SOL_TCP, TCP_NODELAY, 1);
                if (defined('TCP_KEEPIDLE') && defined('TCP_KEEPINTVL') && defined('TCP_KEEPCNT')) {
                    socket_set_option($socket, SOL_TCP, TCP_KEEPIDLE, static::TCP_KEEPALIVE_INTERVAL);
                    socket_set_option($socket, SOL_TCP, TCP_KEEPINTVL, static::TCP_KEEPALIVE_INTERVAL);
                    socket_set_option($socket, SOL_TCP, TCP_KEEPCNT, 1);
                }
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
                    $this->eventLoop->onWritable($this->socket, $this->baseWrite(...));
                }
            }
            // Register a listener waiting read event.
            $this->eventLoop->onReadable($this->socket, $this->baseRead(...));

            $this->status = self::STATUS_ESTABLISHED;
            $this->remoteAddress = $address;

            // Try to emit onConnect callback.
            if ($this->onConnect) {
                try {
                    ($this->onConnect)($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            // Try to emit protocol::onConnect
            if ($this->protocol && method_exists($this->protocol, 'onConnect')) {
                try {
                    $this->protocol::onConnect($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
        } else {
            // Connection failed.
            $this->emitError(static::CONNECT_FAIL, 'connect ' . $this->remoteAddress . ' fail after ' . round(microtime(true) - $this->connectStartTime, 4) . ' seconds');
            if ($this->status === self::STATUS_CLOSING) {
                $this->destroy();
            }
            if ($this->status === self::STATUS_CLOSED) {
                $this->onConnect = null;
            }
        }
    }
}
