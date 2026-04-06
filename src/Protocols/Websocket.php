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

            if (!$masked) {
                Worker::safeEcho("frame not masked so close the connection\n");
                $connection->close();
                return 0;
            }

            $opcode = $firstByte & 0xf;
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
        $isFinFrame = (bool)($firstByte >> 7);
        $rsv1 = 64 === ($firstByte & 64);

        [$masks, $data] = match(true) {
             $len === 126 => [substr($buffer,  4, 4), substr($buffer, 8)],
             $len === 127 => [substr($buffer, 10, 4), substr($buffer, 14)],
             default      => [substr($buffer,  2, 4), substr($buffer, 6)],
        };

        $dataLength = strlen($data);
        $masks = str_repeat($masks, (int)floor($dataLength / 4)) . substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decoded;
            if ($rsv1) {
                return static::inflate($connection, $connection->context->websocketDataBuffer, $isFinFrame);
            }
            return $connection->context->websocketDataBuffer;
        }
        if ($connection->context->websocketDataBuffer !== '') {
            $decoded = $connection->context->websocketDataBuffer . $decoded;
            $connection->context->websocketDataBuffer = '';
        }
        if ($rsv1) {
            return static::inflate($connection, $decoded, $isFinFrame);
        }
        return $decoded;
    }

    protected static function inflate(TcpConnection $connection, string $buffer, bool $isFinFrame): false|string
    {
        $connection->context->inflator ??= inflate_init(...self::ZLIB_INIT_OPTIONS);
        
        if ($isFinFrame) {
            $buffer .= "\x00\x00\xff\xff";
        }
        $result = inflate_add($connection->context->inflator, $buffer);
        // Guard against decompression bomb: check inflated size against maxPackageSize.
        if ($result !== false && strlen($result) > $connection->maxPackageSize) {
            Worker::safeEcho("WebSocket inflate data exceeds maxPackageSize limit\n");
            $connection->close();
            return false;
        }
        return $result;
    }

    protected static function deflate(TcpConnection $connection, string $buffer): false|string
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
        if (!$headerEndPos) {
            return 0;
        }
        $headerLength = $headerEndPos + 4;

        // Check WebSocket version - RFC 6455 Section 4.4
        if (preg_match("/Sec-WebSocket-Version: *(.*?)\r\n/i", $buffer, $match)) {
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
        if (preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
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

        // Websocket data buffer.
        $connection->context->websocketDataBuffer = '';
        // Current websocket frame length.
        $connection->context->websocketCurrentFrameLength = 0;
        // Current websocket frame data.
        $connection->context->websocketCurrentFrameBuffer = '';
        // Consume handshake data.
        $connection->consumeRecvBuffer($headerLength);
        // Request from buffer
        $request = new Request($buffer);

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
