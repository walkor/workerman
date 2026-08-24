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

namespace Workerman\Protocols;

use Throwable;
use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;
use function base64_encode;
use function chr;
use function deflate_add;
use function deflate_init;
use function floor;
use function inflate_add;
use function inflate_init;
use function is_scalar;
use function ord;
use function pack;
use function preg_match;
use function sha1;
use function str_repeat;
use function stripos;
use function strlen;
use function strpos;
use function substr;
use function unpack;
use const ZLIB_DEFAULT_STRATEGY;
use const ZLIB_ENCODING_RAW;

/**
 * WebSocket protocol.
 */
class Websocket
{
    /**
     * Websocket blob type.
     *
     * @var string
     */
    public const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket blob type.
     *
     * @var string
     */
    const BINARY_TYPE_BLOB_DEFLATE = "\xc1";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    const BINARY_TYPE_ARRAYBUFFER_DEFLATE = "\xc2";

    private const ZLIB_INIT_OPTIONS = [
        ZLIB_ENCODING_RAW,
            [
                'level'    => -1,
                'memory'   => 8,
                'window'   => 15,
                'strategy' => ZLIB_DEFAULT_STRATEGY
            ]
    ];

    /**
     * Inflating in chunks of this size keeps the maxPackageSize check running as the output grows,
     * instead of only after a whole frame has been expanded.
     */
    private const INFLATE_CHUNK_SIZE = 1024;

    /**
     * Max size of the handshake headers. maxPackageSize only applies to frames, so the header block
     * gets its own limit, mirroring Http::MAX_HEADER_LENGTH.
     */
    private const MAX_HANDSHAKE_LENGTH = 16384;

    private const HTTP_431 = "HTTP/1.1 431 Request Header Fields Too Large\r\nConnection: close\r\n\r\n";

    /**
     * Reset the per-connection frame parser state to what a freshly handshaken connection looks like.
     * tmpWebsocketData is deliberately left alone: it may already hold frames the application queued
     * before the handshake completed, and dealHandshake() flushes those once the response is sent.
     */
    public static function initContext(TcpConnection $connection): void
    {
        // Payload of the fragments received so far for the current message.
        $connection->context->websocketDataBuffer = '';
        // Length of the frame currently being received, 0 when no fragment is pending.
        $connection->context->websocketCurrentFrameLength = 0;
        // Whether the message being received is permessage-deflate compressed.
        $connection->context->websocketCompressed = false;
        // Whether permessage-deflate was accepted in the handshake response.
        $connection->context->websocketPermessageDeflate = false;
        // Whether a fragmented message is currently being received.
        $connection->context->websocketFragmented = false;
    }

    public static function input(string $buffer, TcpConnection $connection): int
    {
        // Receive length.
        $recvLen = strlen($buffer);
        // We need more data.
        if ($recvLen < 6) {
            return 0;
        }

        // Has not yet completed the handshake.
        if (empty($connection->context->websocketHandshake)) {
            return static::dealHandshake($buffer, $connection);
        }

        // Buffer websocket frame data.
        if ($connection->context->websocketCurrentFrameLength) {
            // We need more frame data.
            if ($connection->context->websocketCurrentFrameLength > $recvLen) {
                // Return 0, because it is not clear the full packet length, waiting for the frame of fin=1.
                return 0;
            }
        } else {
            $firstByte = ord($buffer[0]);
            $secondByte = ord($buffer[1]);
            $dataLen = $secondByte & 127;
            $isFinFrame = $firstByte >> 7;
            $masked = $secondByte >> 7;
            $opcode = $firstByte & 0xf;

            if (!$masked) {
                Worker::safeEcho("frame not masked so close the connection\n");
                $connection->close();
                return 0;
            }

            // RFC 6455 section 5.2: rsv bits must be zero unless a negotiated extension defines them.
            // permessage-deflate is the only extension supported here and it only defines rsv1 on data frames.
            $rsv = $firstByte & 0x70;
            if ($rsv !== 0 && ($rsv !== 0x40 || $opcode >= 0x8 || !$connection->context->websocketPermessageDeflate)) {
                Worker::safeEcho("frame sets rsv bits without a negotiated extension so close the connection\n");
                $connection->close();
                return 0;
            }

            // RFC 6455 section 5.5: control frames must not be fragmented and carry at most 125 bytes.
            if ($opcode >= 0x8 && ($dataLen > 125 || !$isFinFrame)) {
                Worker::safeEcho("invalid control frame so close the connection\n");
                $connection->close();
                return 0;
            }

            // RFC 6455 section 5.4: a continuation frame needs a message in progress, and a new data
            // frame must not interrupt one.
            if ($opcode < 0x8 && ($opcode === 0x0) !== $connection->context->websocketFragmented) {
                Worker::safeEcho("unexpected message fragmentation so close the connection\n");
                $connection->close();
                return 0;
            }

            switch ($opcode) {
                case 0x0:
                    // Blob type.
                case 0x1:
                    // Arraybuffer type.
                case 0x2:
                    // Ping package.
                case 0x9:
                    // Pong package.
                case 0xa:
                    break;
                // Close package.
                case 0x8:
                    // A masked control frame is always $dataLen + 6 bytes.
                    if ($recvLen < $dataLen + 6) {
                        return 0;
                    }
                    // The frame is handled here, so take it out of the receive buffer.
                    $connection->consumeRecvBuffer($dataLen + 6);
                    // Try to emit onWebSocketClose callback.
                    $closeCb = $connection->onWebSocketClose ?? $connection->worker->onWebSocketClose ?? false;
                    if ($closeCb) {
                        try {
                            $closeCb($connection);
                        } catch (Throwable $e) {
                            Worker::stopAll(250, $e);
                        }
                    } // Close connection.
                    else {
                        $connection->close("\x88\x02\x03\xe8", true);
                    }
                    return 0;
                // Wrong opcode.
                default :
                    Worker::safeEcho("error opcode $opcode and close websocket connection. Buffer:" . bin2hex($buffer) . "\n");
                    $connection->close();
                    return 0;
            }

            // Calculate packet length.
            $headLen = 6;
            if ($dataLen === 126) {
                $headLen = 8;
                if ($headLen > $recvLen) {
                    return 0;
                }
                $dataLen = unpack('nn/ntotal_len', $buffer)['total_len'];
            } else {
                if ($dataLen === 127) {
                    $headLen = 14;
                    if ($headLen > $recvLen) {
                        return 0;
                    }
                    $arr = unpack('n/N2c', $buffer);
                    $dataLen = $arr['c1'] * 4294967296 + $arr['c2'];
                }
            }
            $currentFrameLength = $headLen + $dataLen;

            $totalPackageSize = strlen($connection->context->websocketDataBuffer) + $currentFrameLength;
            if ($totalPackageSize > $connection->maxPackageSize) {
                Worker::safeEcho("error package. package_length=$totalPackageSize\n");
                $connection->close();
                return 0;
            }

            // Past every early return, so the frame is accepted and the message state can advance.
            if ($opcode < 0x8) {
                $connection->context->websocketFragmented = !$isFinFrame;
            }

            if ($isFinFrame) {
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        $pingData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        $pingCb = $connection->onWebSocketPing ?? $connection->worker->onWebSocketPing ?? false;
                        if ($pingCb) {
                            try {
                                $pingCb($connection, $pingData);
                            } catch (Throwable $e) {
                                Worker::stopAll(250, $e);
                            }
                        } else {
                            $connection->send($pingData);
                        }
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }

                if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        $pongData = static::decode(substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        // Try to emit onWebSocketPong callback.
                        $pongCb = $connection->onWebSocketPong ?? $connection->worker->onWebSocketPong ?? false;
                        if ($pongCb) {
                            try {
                                $pongCb($connection, $pongData);
                            } catch (Throwable $e) {
                                Worker::stopAll(250, $e);
                            }
                        }
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }
                return $currentFrameLength;
            }

            $connection->context->websocketCurrentFrameLength = $currentFrameLength;
        }

        // Received just a frame length data.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            static::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        }

        // The length of the received data is greater than the length of a frame.
        if ($connection->context->websocketCurrentFrameLength < $recvLen) {
            static::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return static::input(substr($buffer, $currentFrameLength), $connection);
        }

        // The length of the received data is less than the length of a frame.
        return 0;
    }

    public static function encode(mixed $buffer, TcpConnection $connection): string
    {
        if (!is_scalar($buffer)) {
            $buffer = json_encode($buffer, JSON_UNESCAPED_UNICODE);
        }

        $connection->websocketType ??= static::BINARY_TYPE_BLOB;

        if (ord($connection->websocketType) & 64) {
            $buffer = static::deflate($connection, $buffer);
        }

        $firstByte = $connection->websocketType;
        $len = strlen($buffer);

        $encodeBuffer = match(true) {
            $len <= 125   => $firstByte . chr($len) . $buffer,
            $len <= 65535 => $firstByte . chr(126) . pack("n", $len) . $buffer,
            default       => $firstByte . chr(127) . pack("xxxxN", $len) . $buffer,
        };

        // Handshake not completed so temporary buffer websocket data waiting for send.
        if (empty($connection->context->websocketHandshake)) {
            if (empty($connection->context->tmpWebsocketData)) {
                $connection->context->tmpWebsocketData = '';
            }
            // If buffer has already full then discard the current package.
            if (strlen($connection->context->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'send buffer full and drop package');
                    } catch (Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }
                return '';
            }
            $connection->context->tmpWebsocketData .= $encodeBuffer;
            // Check buffer is full.
            if ($connection->onBufferFull && $connection->maxSendBufferSize <= strlen($connection->context->tmpWebsocketData)) {
                try {
                    ($connection->onBufferFull)($connection);
                } catch (Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
            // Return empty string.
            return '';
        }

        return $encodeBuffer;
    }

    public static function decode(string $buffer, TcpConnection $connection): string
    {
        $firstByte = ord($buffer[0]);
        $secondByte = ord($buffer[1]);
        $len = $secondByte & 127;
        $opcode = $firstByte & 0xf;

        [$masks, $data] = match(true) {
             $len === 126 => [substr($buffer,  4, 4), substr($buffer, 8)],
             $len === 127 => [substr($buffer, 10, 4), substr($buffer, 14)],
             default      => [substr($buffer,  2, 4), substr($buffer, 6)],
        };

        $dataLength = strlen($data);
        $masks = str_repeat($masks, (int)floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;

        // Control frames may be interleaved with a fragmented message, they carry no message data.
        if ($opcode >= 0x8) {
            return $decoded;
        }

        // RFC 7692 section 6: rsv1 on the first frame marks the whole message as compressed.
        if ($opcode !== 0x0) {
            $connection->context->websocketCompressed = 64 === ($firstByte & 64);
        }

        // More fragments are coming, buffer the payload until the fin frame arrives.
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decoded;
            return '';
        }

        if ($connection->context->websocketDataBuffer !== '') {
            $decoded = $connection->context->websocketDataBuffer . $decoded;
            $connection->context->websocketDataBuffer = '';
        }

        if ($connection->context->websocketCompressed) {
            return static::inflate($connection, $decoded);
        }

        return $decoded;
    }

    protected static function inflate(TcpConnection $connection, string $buffer): string
    {
        $connection->context->inflator ??= inflate_init(...self::ZLIB_INIT_OPTIONS);
        $buffer .= "\x00\x00\xff\xff";

        $result = '';
        $bufferLength = strlen($buffer);
        for ($offset = 0; $offset < $bufferLength; $offset += self::INFLATE_CHUNK_SIZE) {
            // Silenced because a decode failure is an expected outcome and is handled below.
            $chunk = @inflate_add(
                $connection->context->inflator,
                substr($buffer, $offset, self::INFLATE_CHUNK_SIZE)
            );
            if ($chunk === false) {
                Worker::safeEcho("websocket inflate failed so close the connection\n");
                $connection->close();
                return '';
            }
            if (strlen($chunk) > $connection->maxPackageSize - strlen($result)) {
                Worker::safeEcho("websocket inflate data exceeds maxPackageSize limit so close the connection\n");
                $connection->close();
                return '';
            }
            $result .= $chunk;
        }

        return $result;
    }

    protected static function deflate(TcpConnection $connection, string $buffer): string
    {
        $connection->context->deflator ??= deflate_init(...self::ZLIB_INIT_OPTIONS);

        return substr(deflate_add($connection->context->deflator, $buffer), 0, -4);
    }

    /**
     * Websocket handshake.
     *
     */
    public static function dealHandshake(string $buffer, TcpConnection $connection): int
    {
        $HTTP_400 = "HTTP/1.1 400 Bad Request\r\n\r\n<div style=\"text-align:center\"><h1>400 Bad Request</h1><hr>workerman</div>";
        
        // HTTP protocol.
        if (!str_starts_with($buffer, 'GET')) {
            // Bad websocket handshake request.
            $connection->close($HTTP_400, true);
            return 0;
        }

        // Find \r\n\r\n.
        $headerEndPos = strpos($buffer, "\r\n\r\n");
        if ($headerEndPos === false) {
            if (strlen($buffer) >= self::MAX_HANDSHAKE_LENGTH) {
                $connection->close(self::HTTP_431, true);
            }
            return 0;
        }
        if ($headerEndPos >= self::MAX_HANDSHAKE_LENGTH) {
            $connection->close(self::HTTP_431, true);
            return 0;
        }
        $headerLength = $headerEndPos + 4;
        // Parse the header block only, anything after it is already websocket frame data.
        $header = substr($buffer, 0, $headerLength);

        // Check WebSocket version - RFC 6455 Section 4.4
        if (preg_match("/Sec-WebSocket-Version: *(.*?)\r\n/i", $header, $match)) {
            if($match[1] !== '13') {
                $_426 = "HTTP/1.1 426 Upgrade Required\r\n"
                    . "Connection: Upgrade\r\n"
                    . "Upgrade: WebSocket\r\n"
                    . "Sec-WebSocket-Version: 13\r\n\r\n";
                
                $connection->close($_426, true);
                return 0;
             }
        } else {
            $connection->close(
                "HTTP/1.1 400 Bad Request\r\nSec-WebSocket-Version: 13\r\n\r\n", true);
            return 0;
        }
        
        // Get Sec-WebSocket-Key.
        if (preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $header, $match)) {
            $SecWebSocketKey = $match[1];
        } else {
            $connection->close($HTTP_400, true);
            return 0;
        }
        // Calculation websocket key.
        $newKey = base64_encode(sha1($SecWebSocketKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
        // Handshake response data.
        $handshakeMessage = "HTTP/1.1 101 Switching Protocol\r\n"
            . "Upgrade: websocket\r\n"
            . "Sec-WebSocket-Version: 13\r\n"
            . "Connection: Upgrade\r\n"
            . "Sec-WebSocket-Accept: $newKey\r\n";

        static::initContext($connection);
        // Consume handshake data.
        $connection->consumeRecvBuffer($headerLength);
        // Request from buffer
        $request = new Request($header);

        // Try to emit onWebSocketConnect callback.
        $onWebsocketConnect = $connection->onWebSocketConnect ?? $connection->worker->onWebSocketConnect ?? false;
        if ($onWebsocketConnect) {
            try {
                $onWebsocketConnect($connection, $request);
            } catch (Throwable $e) {
                Worker::stopAll(250, $e);
            }
        }

        // blob or arraybuffer
        $connection->websocketType ??= static::BINARY_TYPE_BLOB;

        if ($connection->headers) {
            foreach ($connection->headers as $header) {
                if (strpbrk($header, "\r\n") !== false) {
                    continue;
                }
                // Accepting the extension is what enables rsv1 compressed frames on this connection.
                if (stripos($header, 'Sec-WebSocket-Extensions:') === 0 && stripos($header, 'permessage-deflate') !== false) {
                    $connection->context->websocketPermessageDeflate = true;
                }
                $handshakeMessage .= "$header\r\n";
            }
        }
        $handshakeMessage .= "\r\n";
        // Send handshake response.
        $connection->send($handshakeMessage, true);
        // Mark handshake complete.
        $connection->context->websocketHandshake = true;

        // Try to emit onWebSocketConnected callback.
        $onWebsocketConnected = $connection->onWebSocketConnected ?? $connection->worker->onWebSocketConnected ?? false;
        if ($onWebsocketConnected) {
            try {
                $onWebsocketConnected($connection, $request);
            } catch (Throwable $e) {
                Worker::stopAll(250, $e);
            }
        }

        // There are data waiting to be sent.
        if (!empty($connection->context->tmpWebsocketData)) {
            $connection->send($connection->context->tmpWebsocketData, true);
            $connection->context->tmpWebsocketData = '';
        }
        if (strlen($buffer) > $headerLength) {
            return static::input(substr($buffer, $headerLength), $connection);
        }
        return 0;
    }
}
