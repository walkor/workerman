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
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\ProtocolInterface;
use Workerman\Worker;

/**
 * TcpConnection.
 * @property string websocketType
 */
class TcpConnection extends ConnectionInterface implements \JsonSerializable
{
    /**
     * Read buffer size.
     *
     * @var int
     */
    const READ_BUFFER_SIZE = 87380;

    /**
     * Status initial.
     *
     * @var int
     */
    const STATUS_INITIAL = 0;

    /**
     * Status connecting.
     *
     * @var int
     */
    const STATUS_CONNECTING = 1;

    /**
     * Status connection established.
     *
     * @var int
     */
    const STATUS_ESTABLISHED = 2;

    /**
     * Status closing.
     *
     * @var int
     */
    const STATUS_CLOSING = 4;

    /**
     * Status closed.
     *
     * @var int
     */
    const STATUS_CLOSED = 8;

    /**
     * Emitted when data is received.
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callable
     */
    public $onError = null;

    /**
     * Emitted when the send buffer becomes full.
     *
     * @var callable
     */
    public $onBufferFull = null;

    /**
     * Emitted when the send buffer becomes empty.
     *
     * @var callable
     */
    public $onBufferDrain = null;

    /**
     * Application layer protocol.
     * The format is like this Workerman\\Protocols\\Http.
     *
     * @var ProtocolInterface
     */
    public $protocol = null;

    /**
     * Transport (tcp/udp/unix/ssl).
     *
     * @var string
     */
    public $transport = 'tcp';

    /**
     * Which worker belong to.
     *
     * @var Worker
     */
    public $worker = null;

    /**
     * Bytes read.
     *
     * @var int
     */
    public $bytesRead = 0;

    /**
     * Bytes written.
     *
     * @var int
     */
    public $bytesWritten = 0;

    /**
     * Connection->id.
     *
     * @var int
     */
    public $id = 0;

    /**
     * A copy of $worker->id which used to clean up the connection in worker->connections
     *
     * @var int
     */
    protected $realId = 0;

    /**
     * Sets the maximum send buffer size for the current connection.
     * OnBufferFull callback will be emited When the send buffer is full.
     *
     * @var int
     */
    public $maxSendBufferSize = 1048576;
    
    /**
     * Context.
     *
     * @var object
     */
    public $context;

    /**
     * @var array
     */
    public $headers = [];

    /**
     * @var object
     */
    public $request;

    /**
     * Default send buffer size.
     *
     * @var int
     */
    public static $defaultMaxSendBufferSize = 1048576;

    /**
     * Sets the maximum acceptable packet size for the current connection.
     *
     * @var int
     */
    public $maxPackageSize = 1048576;

    /**
     * Default maximum acceptable packet size.
     *
     * @var int
     */
    public static $defaultMaxPackageSize = 10485760;

    /**
     * Id recorder.
     *
     * @var int
     */
    protected static $idRecorder = 1;

    /**
     * Cache.
     *
     * @var bool.
     */
    protected static $enableCache = true;

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
    protected $sendBuffer = '';

    /**
     * Receive buffer.
     *
     * @var string
     */
    protected $recvBuffer = '';

    /**
     * Current package length.
     *
     * @var int
     */
    protected $currentPackageLength = 0;

    /**
     * Connection status.
     *
     * @var int
     */
    protected $status = self::STATUS_ESTABLISHED;

    /**
     * Remote address.
     *
     * @var string
     */
    protected $remoteAddress = '';

    /**
     * Is paused.
     *
     * @var bool
     */
    protected $isPaused = false;

    /**
     * SSL handshake completed or not.
     *
     * @var bool
     */
    protected $sslHandshakeCompleted = false;

    /**
     * All connection instances.
     *
     * @var array
     */
    public static $connections = [];

    /**
     * Status to string.
     *
     * @var array
     */
    public static $statusToString = [
        self::STATUS_INITIAL => 'INITIAL',
        self::STATUS_CONNECTING => 'CONNECTING',
        self::STATUS_ESTABLISHED => 'ESTABLISHED',
        self::STATUS_CLOSING => 'CLOSING',
        self::STATUS_CLOSED => 'CLOSED',
    ];

    /**
     * Construct.
     *
     * @param resource $socket
     * @param string $remoteAddress
     */
    public function __construct($eventLoop, $socket, $remoteAddress = '')
    {
        ++self::$statistics['connection_count'];
        $this->id = $this->realId = self::$idRecorder++;
        if (self::$idRecorder === \PHP_INT_MAX) {
            self::$idRecorder = 0;
        }
        $this->socket = $socket;
        \stream_set_blocking($this->socket, 0);
        // Compatible with hhvm
        if (\function_exists('stream_set_read_buffer')) {
            \stream_set_read_buffer($this->socket, 0);
        }
        $this->eventLoop = $eventLoop;
        $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
        $this->maxPackageSize = self::$defaultMaxPackageSize;
        $this->remoteAddress = $remoteAddress;
        static::$connections[$this->id] = $this;
        $this->context = new \stdClass;
    }

    /**
     * Get status.
     *
     * @param bool $rawOutput
     *
     * @return int|string
     */
    public function getStatus($rawOutput = true)
    {
        if ($rawOutput) {
            return $this->status;
        }
        return self::$statusToString[$this->status];
    }

    /**
     * Sends data on the connection.
     *
     * @param mixed $sendBuffer
     * @param bool $raw
     * @return bool|void
     */
    public function send($sendBuffer, $raw = false)
    {
        if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
            return false;
        }

        // Try to call protocol::encode($sendBuffer) before sending.
        if (false === $raw && $this->protocol !== null) {
            $sendBuffer = $this->protocol::encode($sendBuffer, $this);
            if ($sendBuffer === '') {
                return;
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
            return;
        }

        // Attempt to send data directly.
        if ($this->sendBuffer === '') {
            if ($this->transport === 'ssl') {
                $this->eventLoop->onWritable($this->socket, [$this, 'baseWrite']);
                $this->sendBuffer = $sendBuffer;
                $this->checkBufferWillFull();
                return;
            }
            $len = 0;
            try {
                $len = @\fwrite($this->socket, $sendBuffer);
            } catch (\Throwable $e) {
                Worker::log($e);
            }
            // send successful.
            if ($len === \strlen($sendBuffer)) {
                $this->bytesWritten += $len;
                return true;
            }
            // Send only part of the data.
            if ($len > 0) {
                $this->sendBuffer = \substr($sendBuffer, $len);
                $this->bytesWritten += $len;
            } else {
                // Connection closed?
                if (!\is_resource($this->socket) || \feof($this->socket)) {
                    ++self::$statistics['send_fail'];
                    if ($this->onError) {
                        try {
                            ($this->onError)($this, static::SEND_FAIL, 'client closed');
                        } catch (\Throwable $e) {
                            $this->error($e);
                        }
                    }
                    $this->destroy();
                    return false;
                }
                $this->sendBuffer = $sendBuffer;
            }
            $this->eventLoop->onWritable($this->socket, [$this, 'baseWrite']);
            // Check if the send buffer will be full.
            $this->checkBufferWillFull();
            return;
        }

        if ($this->bufferIsFull()) {
            ++self::$statistics['send_fail'];
            return false;
        }

        $this->sendBuffer .= $sendBuffer;
        // Check if the send buffer is full.
        $this->checkBufferWillFull();
    }

    /**
     * Get remote IP.
     *
     * @return string
     */
    public function getRemoteIp()
    {
        $pos = \strrpos($this->remoteAddress, ':');
        if ($pos) {
            return (string)\substr($this->remoteAddress, 0, $pos);
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
        if ($this->remoteAddress) {
            return (int)\substr(\strrchr($this->remoteAddress, ':'), 1);
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
        return $this->remoteAddress;
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
        if (!\is_resource($this->socket)) {
            return '';
        }
        return (string)@\stream_socket_get_name($this->socket, false);
    }

    /**
     * Get send buffer queue size.
     *
     * @return integer
     */
    public function getSendBufferQueueSize()
    {
        return \strlen($this->sendBuffer);
    }

    /**
     * Get recv buffer queue size.
     *
     * @return integer
     */
    public function getRecvBufferQueueSize()
    {
        return \strlen($this->recvBuffer);
    }

    /**
     * Is ipv4.
     *
     * return bool.
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
     * return bool.
     */
    public function isIpV6()
    {
        if ($this->transport === 'unix') {
            return false;
        }
        return \strpos($this->getRemoteIp(), ':') !== false;
    }

    /**
     * Pauses the reading of data. That is onMessage will not be emitted. Useful to throttle back an upload.
     *
     * @return void
     */
    public function pauseRecv()
    {
        $this->eventLoop->offReadable($this->socket);
        $this->isPaused = true;
    }

    /**
     * Resumes reading after a call to pauseRecv.
     *
     * @return void
     */
    public function resumeRecv()
    {
        if ($this->isPaused === true) {
            $this->eventLoop->onReadable($this->socket, [$this, 'baseRead']);
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
    public function baseRead($socket, $checkEof = true)
    {
        static $requests = [];
        // SSL handshake.
        if ($this->transport === 'ssl' && $this->sslHandshakeCompleted !== true) {
            if ($this->doSslHandshake($socket)) {
                $this->sslHandshakeCompleted = true;
                if ($this->sendBuffer) {
                    $this->eventLoop->onWritable($socket, [$this, 'baseWrite']);
                }
            } else {
                return;
            }
        }

        $buffer = '';
        try {
            $buffer = @\fread($socket, self::READ_BUFFER_SIZE);
        } catch (\Throwable $e) {
        }

        // Check connection closed.
        if ($buffer === '' || $buffer === false) {
            if ($checkEof && (\feof($socket) || !\is_resource($socket) || $buffer === false)) {
                $this->destroy();
                return;
            }
        } else {
            $this->bytesRead += \strlen($buffer);
            if ($this->recvBuffer === '') {
                if (static::$enableCache && !isset($buffer[512]) && isset($requests[$buffer])) {
                    ++self::$statistics['total_request'];
                    $request = $requests[$buffer];
                    if ($request instanceof Request) {
                        $request = clone $request;
                        $requests[$buffer] = $request;
                        $request->connection = $this;
                        $this->request = $request;
                        $request->properties = [];
                    }
                    try {
                        ($this->onMessage)($this, $request);
                    } catch (\Throwable $e) {
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
                    if ($this->currentPackageLength > \strlen($this->recvBuffer)) {
                        break;
                    }
                } else {
                    // Get current package length.
                    try {
                        $this->currentPackageLength = $this->protocol::input($this->recvBuffer, $this);
                    } catch (\Throwable $e) {
                    }
                    // The packet length is unknown.
                    if ($this->currentPackageLength === 0) {
                        break;
                    } elseif ($this->currentPackageLength > 0 && $this->currentPackageLength <= $this->maxPackageSize) {
                        // Data is not enough for a package.
                        if ($this->currentPackageLength > \strlen($this->recvBuffer)) {
                            break;
                        }
                    } // Wrong package.
                    else {
                        Worker::safeEcho('Error package. package_length=' . \var_export($this->currentPackageLength, true));
                        $this->destroy();
                        return;
                    }
                }

                // The data is enough for a packet.
                ++self::$statistics['total_request'];
                // The current packet length is equal to the length of the buffer.
                if ($one = \strlen($this->recvBuffer) === $this->currentPackageLength) {
                    $oneRequestBuffer = $this->recvBuffer;
                    $this->recvBuffer = '';
                } else {
                    // Get a full package from the buffer.
                    $oneRequestBuffer = \substr($this->recvBuffer, 0, $this->currentPackageLength);
                    // Remove the current package from the receive buffer.
                    $this->recvBuffer = \substr($this->recvBuffer, $this->currentPackageLength);
                }
                // Reset the current packet length to 0.
                $this->currentPackageLength = 0;
                try {
                    // Decode request buffer before Emitting onMessage callback.
                    $request = $this->protocol::decode($oneRequestBuffer, $this);
                    if (static::$enableCache && (!\is_object($request) || $request instanceof Request) && $one && !isset($oneRequestBuffer[512])) {
                        $requests[$oneRequestBuffer] = $request;
                        if (\count($requests) > 512) {
                            unset($requests[\key($requests)]);
                        }
                    }
                    ($this->onMessage)($this, $request);
                } catch (\Throwable $e) {
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
        } catch (\Throwable $e) {
            $this->error($e);
        }
        // Clean receive buffer.
        $this->recvBuffer = '';
    }

    /**
     * Base write handler.
     *
     * @return void|bool
     */
    public function baseWrite()
    {
        $len = 0;
        try {
            if ($this->transport === 'ssl') {
                $len = @\fwrite($this->socket, $this->sendBuffer, 8192);
            } else {
                $len = @\fwrite($this->socket, $this->sendBuffer);
            }
        } catch (\Throwable $e) {}
        if ($len === \strlen($this->sendBuffer)) {
            $this->bytesWritten += $len;
            $this->eventLoop->offWritable($this->socket);
            $this->sendBuffer = '';
            // Try to emit onBufferDrain callback when the send buffer becomes empty.
            if ($this->onBufferDrain) {
                try {
                    ($this->onBufferDrain)($this);
                } catch (\Throwable $e) {
                    $this->error($e);
                }
            }
            if ($this->status === self::STATUS_CLOSING) {
                if ($this->context->streamSending) {
                    return true;
                }
                $this->destroy();
            }
            return true;
        }
        if ($len > 0) {
            $this->bytesWritten += $len;
            $this->sendBuffer = \substr($this->sendBuffer, $len);
        } else {
            ++self::$statistics['send_fail'];
            $this->destroy();
        }
    }

    /**
     * SSL handshake.
     *
     * @param resource $socket
     * @return bool
     */
    public function doSslHandshake($socket)
    {
        if (\feof($socket)) {
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
            $type = \STREAM_CRYPTO_METHOD_SSLv2_CLIENT | \STREAM_CRYPTO_METHOD_SSLv23_CLIENT;
        } else {
            $type = \STREAM_CRYPTO_METHOD_SSLv2_SERVER | \STREAM_CRYPTO_METHOD_SSLv23_SERVER;
        }

        // Hidden error.
        \set_error_handler(function ($errno, $errstr, $file) {
            if (!Worker::$daemonize) {
                Worker::safeEcho("SSL handshake error: $errstr \n");
            }
        });
        $ret = \stream_socket_enable_crypto($socket, true, $type);
        \restore_error_handler();
        // Negotiation has failed.
        if (false === $ret) {
            $this->destroy();
            return false;
        } elseif (0 === $ret) {
            // There isn't enough data and should try again.
            return 0;
        }
        if (isset($this->onSslHandshake)) {
            try {
                ($this->onSslHandshake)($this);
            } catch (\Throwable $e) {
                $this->error($e);
            }
        }
        return true;
    }

    /**
     * This method pulls all the data out of a readable stream, and writes it to the supplied destination.
     *
     * @param self $dest
     * @return void
     */
    public function pipe(self $dest)
    {
        $source = $this;
        $this->onMessage = function ($source, $data) use ($dest) {
            $dest->send($data);
        };
        $this->onClose = function ($source) use ($dest) {
            $dest->close();
        };
        $dest->onBufferFull = function ($dest) use ($source) {
            $source->pauseRecv();
        };
        $dest->onBufferDrain = function ($dest) use ($source) {
            $source->resumeRecv();
        };
    }

    /**
     * Remove $length of data from receive buffer.
     *
     * @param int $length
     * @return void
     */
    public function consumeRecvBuffer($length)
    {
        $this->recvBuffer = \substr($this->recvBuffer, $length);
    }

    /**
     * Close connection.
     *
     * @param mixed $data
     * @param bool $raw
     * @return void
     */
    public function close($data = null, $raw = false)
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
     * Get the real socket.
     *
     * @return resource
     */
    public function getSocket()
    {
        return $this->socket;
    }

    /**
     * Check whether the send buffer will be full.
     *
     * @return void
     */
    protected function checkBufferWillFull()
    {
        if ($this->maxSendBufferSize <= \strlen($this->sendBuffer)) {
            if ($this->onBufferFull) {
                try {
                    ($this->onBufferFull)($this);
                } catch (\Throwable $e) {
                    $this->error($e);
                }
            }
        }
    }

    /**
     * Whether send buffer is full.
     *
     * @return bool
     */
    protected function bufferIsFull()
    {
        // Buffer has been marked as full but still has data to send then the packet is discarded.
        if ($this->maxSendBufferSize <= \strlen($this->sendBuffer)) {
            if ($this->onError) {
                try {
                    ($this->onError)($this, static::SEND_FAIL, 'send buffer full and drop package');
                } catch (\Throwable $e) {
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
    public function bufferIsEmpty()
    {
        return empty($this->sendBuffer);
    }

    /**
     * Destroy connection.
     *
     * @return void
     * @throws \Throwable
     */
    public function destroy()
    {
        // Avoid repeated calls.
        if ($this->status === self::STATUS_CLOSED) {
            return;
        }
        // Remove event listener.
        $this->eventLoop->offReadable($this->socket);
        $this->eventLoop->offWritable($this->socket);

        // Close socket.
        try {
            @\fclose($this->socket);
        } catch (\Throwable $e) {
        }

        $this->status = self::STATUS_CLOSED;
        // Try to emit onClose callback.
        if ($this->onClose) {
            try {
                ($this->onClose)($this);
            } catch (\Throwable $e) {
                $this->error($e);
            }
        }
        // Try to emit protocol::onClose
        if ($this->protocol && \method_exists($this->protocol, 'onClose')) {
            try {
                ([$this->protocol, 'onClose'])($this);
            } catch (\Throwable $e) {
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
            unset(static::$connections[$this->realId]);
        }
    }

    /**
     * Enable or disable Cache.
     *
     * @param mixed $value
     */
    public static function enableCache($value)
    {
        static::$enableCache = (bool)$value;
    }
    
    /**
     * Get the json_encode information.
     *
     * @return mixed
     */
    #[\ReturnTypeWillChange]
    public function jsonSerialize()
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
     * Destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        static $mod;
        self::$statistics['connection_count']--;
        if (Worker::getGracefulStop()) {
            if (!isset($mod)) {
                $mod = \ceil((self::$statistics['connection_count'] + 1) / 3);
            }

            if (0 === self::$statistics['connection_count'] % $mod) {
                Worker::log('worker[' . \posix_getpid() . '] remains ' . self::$statistics['connection_count'] . ' connection(s)');
            }

            if (0 === self::$statistics['connection_count']) {
                Worker::stopAll();
            }
        }
    }
}
