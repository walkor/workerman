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
use RuntimeException;
use stdClass;
use Throwable;
use Workerman\Events\Ev;
use Workerman\Events\Event;
use Workerman\Events\EventInterface;
use Workerman\Events\Select;
use Workerman\Protocols\Http;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Worker;

use function ceil;
use function count;
use function fclose;
use function feof;
use function fread;
use function function_exists;
use function fwrite;
use function is_object;
use function is_resource;
use function key;
use function method_exists;
use function posix_getpid;
use function restore_error_handler;
use function set_error_handler;
use function stream_set_blocking;
use function stream_set_read_buffer;
use function stream_socket_enable_crypto;
use function stream_socket_get_name;
use function strlen;
use function strrchr;
use function strrpos;
use function substr;
use function var_export;

use const PHP_INT_MAX;
use const STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
use const STREAM_CRYPTO_METHOD_SSLv23_SERVER;
use const STREAM_CRYPTO_METHOD_SSLv2_CLIENT;
use const STREAM_CRYPTO_METHOD_SSLv2_SERVER;

/**
 * TcpConnection.
 * @property string $websocketType
 * @property string|null $websocketClientProtocol
 * @property string|null $websocketOrigin
 */
class TcpConnection extends ConnectionInterface implements JsonSerializable
{
    /**
     * Read buffer size.
     *
     * @var int
     */
    public const READ_BUFFER_SIZE = 87380;

    /**
     * Status initial.
     *
     * @var int
     */
    public const STATUS_INITIAL = 0;

    /**
     * Status connecting.
     *
     * @var int
     */
    public const STATUS_CONNECTING = 1;

    /**
     * Status connection established.
     *
     * @var int
     */
    public const STATUS_ESTABLISHED = 2;

    /**
     * Status closing.
     *
     * @var int
     */
    public const STATUS_CLOSING = 4;

    /**
     * Status closed.
     *
     * @var int
     */
    public const STATUS_CLOSED = 8;

    /**
     * Maximum string length for cache
     *
     * @var int
     */
    public const MAX_CACHE_STRING_LENGTH = 2048;

    /**
     * Maximum cache size.
     *
     * @var int
     */
    public const MAX_CACHE_SIZE = 512;

    /**
     * Tcp keepalive interval.
     */
    public const TCP_KEEPALIVE_INTERVAL = 55;

    /**
     * Emitted when socket connection is successfully established.
     *
     * @var ?callable
     */
    public $onConnect = null;

    /**
     * Emitted before websocket handshake (Only called when protocol is ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnect = null;

    /**
     * Emitted after websocket handshake (Only called when protocol is ws).
     *
     * @var ?callable
     */
    public $onWebSocketConnected = null;

    /**
     * Emitted when websocket connection is closed (Only called when protocol is ws).
     *
     * @var ?callable
     */
    public $onWebSocketClose = null;

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
     * Emitted when the send buffer becomes full.
     *
     * @var ?callable
     */
    public $onBufferFull = null;

    /**
     * Emitted when send buffer becomes empty.
     *
     * @var ?callable
     */
    public $onBufferDrain = null;

    /**
     * Transport (tcp/udp/unix/ssl).
     *
     * @var string
     */
    public string $transport = 'tcp';

    /**
     * Which worker belong to.
     *
     * @var ?Worker
     */
    public ?Worker $worker = null;

    /**
     * Bytes read.
     *
     * @var int
     */
    public int $bytesRead = 0;

    /**
     * Bytes written.
     *
     * @var int
     */
    public int $bytesWritten = 0;

    /**
     * Connection->id.
     *
     * @var int
     */
    public int $id = 0;

    /**
     * A copy of $worker->id which used to clean up the connection in worker->connections
     *
     * @var int
     */
    protected int $realId = 0;

    /**
     * Sets the maximum send buffer size for the current connection.
     * OnBufferFull callback will be emitted When send buffer is full.
     *
     * @var int
     */
    public int $maxSendBufferSize = 1048576;

    /**
     * Context.
     *
     * @var ?stdClass
     */
    public ?stdClass $context = null;

    /**
     * @var array
     */
    public array $headers = [];

    /**
     * @var ?Request
     */
    public ?Request $request = null;

    /**
     * Is safe.
     *
     * @var bool
     */
    protected bool $isSafe = true;

    /**
     * Default send buffer size.
     *
     * @var int
     */
    public static int $defaultMaxSendBufferSize = 1048576;

    /**
     * Sets the maximum acceptable packet size for the current connection.
     *
     * @var int
     */
    public int $maxPackageSize = 1048576;

    /**
     * Default maximum acceptable packet size.
     *
     * @var int
     */
    public static int $defaultMaxPackageSize = 10485760;

    /**
     * Id recorder.
     *
     * @var int
     */
    protected static int $idRecorder = 1;

    /**
     * Socket
     *
     * @var resource
     */
    protected $socket = null;

    /**
     * Send buffer.
     *
     * @var string
     */
    protected string $sendBuffer = '';

    /**
     * Receive buffer.
     *
     * @var string
     */
    protected string $recvBuffer = '';

    /**
     * Current package length.
     *
     * @var int
     */
    protected int $currentPackageLength = 0;

    /**
     * Connection status.
     *
     * @var int
     */
    protected int $status = self::STATUS_ESTABLISHED;

    /**
     * Remote address.
     *
     * @var string
     */
    protected string $remoteAddress = '';

    /**
     * Is paused.
     *
     * @var bool
     */
    protected bool $isPaused = false;

    /**
     * SSL handshake completed or not.
     *
     * @var bool
     */
    protected bool|int $sslHandshakeCompleted = false;

    /**
     * All connection instances.
     *
     * @var array
     */
    public static array $connections = [];

    /**
     * Status to string.
     *
     * @var array
     */
    public const STATUS_TO_STRING = [
        self::STATUS_INITIAL => 'INITIAL',
        self::STATUS_CONNECTING => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING => 'CLOSING',
        self::STATUS_CLOSED => 'CLOSED',
    ];

    /**
     * Construct.
     *
     * @param EventInterface $eventLoop
     * @param resource $socket
     * @param string $remoteAddress
     */
    public function __construct(EventInterface $eventLoop, $socket, string $remoteAddress = '')
    {
        ++self::$statistics['connection_count'];
        $this->id = $this->realId = self::$idRecorder++;
        if (self::$idRecorder === PHP_INT_MAX) {
            self::$idRecorder = 0;
        }
        $this->socket = $socket;
        stream_set_blocking($this->socket, false);
        stream_set_read_buffer($this->socket, 0);
        $this->eventLoop = $eventLoop;
        $this->eventLoop->onReadable($this->socket, $this->baseRead(...));
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->remoteAddress = $remoteAddress;
        static::$connections[$this->id] = $this;
        $this->context = new stdClass();
    }

    /**
     * Get status.
     *
     * @param bool $rawOutput
     *
     * @return int|string
     */
    public function getStatus(bool $rawOutput = true): int|string
    {
        if ($rawOutput) {
            return $this->status;
        }
        return self::STATUS_TO_STRING[$this->status];
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
        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode($sendBuffer) before sending.
        if (false === $raw && $this->protocol !== null) {
            try {
                $sendBuffer = $this->protocol::encode($sendBuffer, $this);
            } catch(Throwable $e) {
                $this->error($e);
            }
            if ($sendBuffer === '') {
                return null;
            }
        }

        if ($this->status !== self::STATUS_ESTABLISHED ||
            ($this->transport === 'ssl' && $this->sslHandshakeCompleted !== true)
        ) {
            if ($this->sendBuffer && $this->bufferIsFull()) {
                ++self::$statistics['send_fail'];
                return false;
            }
            $this->sendBuffer .= $sendBuffer;
            $this->checkBufferWillFull();
            return null;
        }

        // Attempt to send data directly.
        if ($this->sendBuffer === '') {
            if ($this->transport === 'ssl') {
                $this->eventLoop->onWritable($this->socket, $this->baseWrite(...));
                $this->sendBuffer = $sendBuffer;
                $this->checkBufferWillFull();
                return null;
            }
            $len = 0;
            try {
                $len = @fwrite($this->socket, $sendBuffer);
            } catch (Throwable $e) {
                Worker::log($e);
            }
            // send successful.
            if ($len === strlen($sendBuffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->sendBuffer = substr($sendBuffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed?
                if (!is_resource($this->socket) || feof($this->socket)) {
                    ++self::$statistics['send_fail'];
                    if ($this->onError) {
                        try {
                            ($this->onError)($this, static::SEND_FAIL, 'client closed');
                        } catch (Throwable $e) {
                            $this->error($e);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->sendBuffer = $sendBuffer;
            }
            $this->eventLoop->onWritable($this->socket, $this->baseWrite(...));
            // Check if send buffer will be full.
            $this->checkBufferWillFull();
            return null;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->sendBuffer .= $sendBuffer;
        // Check if send buffer is full.
        $this->checkBufferWillFull();
        return null;
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
            return substr($this->remoteAddress, 0, $pos);
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
        if (!is_resource($this->socket)) {
            return '';
        }
        return (string)@stream_socket_get_name($this->socket, false);
    }

    /**
     * Get send buffer queue size.
     *
     * @return integer
     */
    public function getSendBufferQueueSize(): int
    {
        return strlen($this->sendBuffer);
    }

    /**
     * Get receive buffer queue size.
     *
     * @return integer
     */
    public function getRecvBufferQueueSize(): int
    {
        return strlen($this->recvBuffer);
    }

    /**
     * Pauses the reading of data. That is onMessage will not be emitted. Useful to throttle back an upload.
     *
     * @return void
     */
    public function pauseRecv(): void
    {
        if($this->eventLoop !== null){
            $this->eventLoop->offReadable($this->socket);
        }
        $this->isPaused = true;
    }

    /**
     * Resumes reading after a call to pauseRecv.
     *
     * @return void
     */
    public function resumeRecv(): void
    {
        if ($this->isPaused === true) {
            $this->eventLoop->onReadable($this->socket, $this->baseRead(...));
            $this->isPaused = false;
            $this->baseRead($this->socket, false);
        }
    }


    /**
     * Base read handler.
     *
     * @param resource $socket
     * @param bool $checkEof
     * @return void
     */
    public function baseRead($socket, bool $checkEof = true): void
    {
        static $requests = [];
        // SSL handshake.
        if ($this->transport === 'ssl' && $this->sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->sslHandshakeCompleted = true;
                if ($this->sendBuffer) {
                    $this->eventLoop->onWritable($socket, $this->baseWrite(...));
                }
            } else {
                return;
            }
        }

        $buffer = '';
        try {
            $buffer = @fread($socket, self::READ_BUFFER_SIZE);
        } catch (Throwable) {
            // do nothing
        }

        // Check connection closed.
        if ($buffer === '' || $buffer === false) {
            if ($checkEof && (!is_resource($socket) || feof($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += strlen($buffer);
            if ($this->recvBuffer === '') {
                if (!isset($buffer[static::MAX_CACHE_STRING_LENGTH]) && isset($requests[$buffer])) {
                    ++self::$statistics['total_request'];
                    if ($this->protocol === Http::class) {
                        $request = clone $requests[$buffer];
                        $request->destroy();
                        $request->connection = $this;
                        $this->request = $request;
                        try {
                            ($this->onMessage)($this, $request);
                        } catch (Throwable $e) {
                            $this->error($e);
                        }
                        return;
                    }
                    $request = $requests[$buffer];
                    try {
                        ($this->onMessage)($this, $request);
                    } catch (Throwable $e) {
                        $this->error($e);
                    }
                    return;
                }
                $this->recvBuffer = $buffer;
            } else {
                $this->recvBuffer .= $buffer;
            }
        }

        // If the application layer protocol has been set up.
        if ($this->protocol !== null) {
            while ($this->recvBuffer !== '' && !$this->isPaused) {
                // The current packet length is known.
                if ($this->currentPackageLength) {
                    // Data is not enough for a package.
                    if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    try {
                        $this->currentPackageLength = $this->protocol::input($this->recvBuffer, $this);
                    } catch (Throwable $e) {
                        $this->currentPackageLength = -1;
                        Worker::safeEcho((string)$e);
                    }
                    // The packet length is unknown.
                    if ($this->currentPackageLength === 0) {
                        break;
                    } elseif ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->maxPackageSize) {
                        // Data is not enough for a package.
                        if ($this->currentPackageLength > strlen($this->recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        Worker::safeEcho((string)(new RuntimeException("Protocol $this->protocol Error package. package_length=" . var_export($this->currentPackageLength, true))));
                        $this->destroy();
                        return;
                    }
                }

                // The data is enough for a packet.
                ++self::$statistics['total_request'];
                // The current packet length is equal to the length of the buffer.
                if ($one = (strlen($this->recvBuffer) === $this->currentPackageLength)) {
                    $oneRequestBuffer = $this->recvBuffer;
                    $this->recvBuffer = '';
                } else {
                    // Get a full package from the buffer.
                    $oneRequestBuffer = substr($this->recvBuffer, 0, $this->currentPackageLength);
                    // Remove the current package from receive buffer.
                    $this->recvBuffer = substr($this->recvBuffer, $this->currentPackageLength);
                }
                // Reset the current packet length to 0.
                $this->currentPackageLength = 0;
                try {
                    // Decode request buffer before Emitting onMessage callback.
                    $request = $this->protocol::decode($oneRequestBuffer, $this);
                    if ((!is_object($request) || $request instanceof Request) && $one && !isset($oneRequestBuffer[static::MAX_CACHE_STRING_LENGTH])) {
                        ($this->onMessage)($this, $request);
                        if ($request instanceof Request) {
                            $requests[$oneRequestBuffer] = clone $request;
                            $requests[$oneRequestBuffer]->destroy();
                        } else {
                            $requests[$oneRequestBuffer] = $request;
                        }
                        if (count($requests) > static::MAX_CACHE_SIZE) {
                            unset($requests[key($requests)]);
                        }
                        return;
                    }
                    ($this->onMessage)($this, $request);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            return;
        }

        if ($this->recvBuffer === '' || $this->isPaused) {
            return;
        }

        // Applications protocol is not set.
        ++self::$statistics['total_request'];
        try {
            ($this->onMessage)($this, $this->recvBuffer);
        } catch (Throwable $e) {
            $this->error($e);
        }
        // Clean receive buffer.
        $this->recvBuffer = '';
    }

    /**
     * Base write handler.
     *
     * @return void
     */
    public function baseWrite(): void
    {
        $len = 0;
        try {
            if ($this->transport === 'ssl') {
                $len = @fwrite($this->socket, $this->sendBuffer, 8192);
            } else {
                $len = @fwrite($this->socket, $this->sendBuffer);
            }
        } catch (Throwable) {
        }
        if ($len === strlen($this->sendBuffer)) {
            $this->bytesWritten += $len;
            $this->eventLoop->offWritable($this->socket);
            $this->sendBuffer = '';
            // Try to emit onBufferDrain callback when send buffer becomes empty.
            if ($this->onBufferDrain) {
                try {
                    ($this->onBufferDrain)($this);
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            if ($this->status === self::STATUS_CLOSING) {
                if (!empty($this->context->streamSending)) {
                    return;
                }
                $this->destroy();
            }
            return;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->sendBuffer = substr($this->sendBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * SSL handshake.
     *
     * @param resource $socket
     * @return bool|int
     */
    public function doSslHandshake($socket): bool|int
    {
        if (!is_resource($socket) || feof($socket)) {
            $this->destroy();
            return false;
        }
        $async = $this instanceof AsyncTcpConnection;

        /**
         *  We disabled ssl3 because https://blog.qualys.com/ssllabs/2014/10/15/ssl-3-is-dead-killed-by-the-poodle-attack.
         *  You can enable ssl3 by the codes below.
         */
        /*if($async){
            $type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT | STREAM_CRYPTO_METHOD_SSLv3_CLIENT;
        }else{
            $type = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER | STREAM_CRYPTO_METHOD_SSLv3_SERVER;
        }*/

        if ($async) {
            $type = STREAM_CRYPTO_METHOD_SSLv2_CLIENT | STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        } else {
            $type = STREAM_CRYPTO_METHOD_SSLv2_SERVER | STREAM_CRYPTO_METHOD_SSLv23_SERVER;
        }

        // Hidden error.
        set_error_handler(static function (int $code, string $msg): bool {
            if (!Worker::$daemonize) {
                Worker::safeEcho(sprintf("SSL handshake error: %s\n", $msg));
            }
            return true;
        });
        $ret = stream_socket_enable_crypto($socket, true, $type);
        restore_error_handler();
        // Negotiation has failed.
        if (false === $ret) {
            $this->destroy();
            return false;
        }
        if (0 === $ret) {
            // There isn't enough data and should try again.
            return 0;
        }
        return true;
    }

    /**
     * This method pulls all the data out of a readable stream, and writes it to the supplied destination.
     *
     * @param self $dest
     * @return void
     */
    public function pipe(self $dest): void
    {
        $this->onMessage = fn ($source, $data) => $dest->send($data);
        $this->onClose = fn () => $dest->close();
        $dest->onBufferFull = fn () => $this->pauseRecv();
        $dest->onBufferDrain = fn() => $this->resumeRecv();
    }

    /**
     * Remove $length of data from receive buffer.
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer(int $length): void
    {
        $this->recvBuffer = substr($this->recvBuffer, $length);
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
        if ($this->status === self::STATUS_CONNECTING) {
            $this->destroy();
            return;
        }

        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return;
        }

        if ($data !== null) {
            $this->send($data, $raw);
        }

        $this->status = self::STATUS_CLOSING;

        if ($this->sendBuffer === '') {
            $this->destroy();
        } else {
            $this->pauseRecv();
        }
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
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Check whether send buffer will be full.
     *
     * @return void
     */
    protected function checkBufferWillFull(): void
    {
        if ($this->onBufferFull && $this->maxSendBufferSize <= strlen($this->sendBuffer)) {
            try {
                ($this->onBufferFull)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
    }

    /**
     * Whether send buffer is full.
     *
     * @return bool
     */
    protected function bufferIsFull(): bool
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->maxSendBufferSize <= strlen($this->sendBuffer)) {
            if ($this->onError) {
                try {
                    ($this->onError)($this, static::SEND_FAIL, 'send buffer full and drop package');
                } catch (Throwable $e) {
                    $this->error($e);
                }
            }
            return true;
        }
        return false;
    }

    /**
     * Whether send buffer is Empty.
     *
     * @return bool
     */
    public function bufferIsEmpty(): bool
    {
        return empty($this->sendBuffer);
    }

    /**
     * Destroy connection.
     *
     * @return void
     */
    public function destroy(): void
    {
        // Avoid repeated calls.
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        if($this->eventLoop !== null){
            $this->eventLoop->offReadable($this->socket);
            $this->eventLoop->offWritable($this->socket);
            if (DIRECTORY_SEPARATOR === '\\' && method_exists($this->eventLoop, 'offExcept')) {
                $this->eventLoop->offExcept($this->socket);
            }
        }

        // Close socket.
        try {
            @fclose($this->socket);
        } catch (Throwable) {
        }

        $this->status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                ($this->onClose)($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
        // Try to emit protocol::onClose
        if ($this->protocol && method_exists($this->protocol, 'onClose')) {
            try {
                $this->protocol::onClose($this);
            } catch (Throwable $e) {
                $this->error($e);
            }
        }
        $this->sendBuffer = $this->recvBuffer = '';
        $this->currentPackageLength = 0;
        $this->isPaused = $this->sslHandshakeCompleted = false;
        if ($this->status === self::STATUS_CLOSED) {
            // Cleaning up the callback to avoid memory leaks.
            $this->onMessage = $this->onClose = $this->onError = $this->onBufferFull = $this->onBufferDrain = $this->eventLoop = $this->errorHandler = null;
            // Remove from worker->connections.
            if ($this->worker) {
                unset($this->worker->connections[$this->realId]);
            }
            $this->worker = null;
            unset(static::$connections[$this->realId]);
        }
    }

    /**
     * Get the json_encode information.
     *
     * @return array
     */
    public function jsonSerialize(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->getStatus(),
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

    /**
     * __wakeup.
     *
     * @return void
     */
    public function __wakeup()
    {
        $this->isSafe = false;
    }

    /**
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        if (!$this->isSafe) {
            return;
        }
        self::$statistics['connection_count']--;
        if (Worker::getGracefulStop()) {
            $mod ??= ceil((self::$statistics['connection_count'] + 1) / 3);

            if (0 === self::$statistics['connection_count'] % $mod) {
                $pid = function_exists('posix_getpid') ? posix_getpid() : 0;
                Worker::log('worker[' . $pid . '] remains ' . self::$statistics['connection_count'] . ' connection(s)');
            }

            if (0 === self::$statistics['connection_count']) {
                Worker::stopAll();
            }
        }
    }
}
