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
use Workerman\Connection\AsyncTcpConnection;
use Workerman\Connection\ConnectionInterface;
use Workerman\Protocols\Http\Response;
use Workerman\Timer;
use Workerman\Worker;
use function base64_encode;
use function bin2hex;
use function explode;
use function floor;
use function ord;
use function pack;
use function preg_match;
use function sha1;
use function str_repeat;
use function strlen;
use function strpos;
use function substr;
use function trim;
use function unpack;

/**
 * Websocket protocol for client.
 */
class Ws
{
    /**
     * Websocket blob type.
     *
     * @var string
     */
    public const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    public const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param AsyncTcpConnection $connection
     * @return int
     */
    public static function input(string $buffer, AsyncTcpConnection $connection): int
    {
        if (empty($connection->context->handshakeStep)) {
            Worker::safeEcho("recv data before handshake. Buffer:" . bin2hex($buffer) . "\n");
            return -1;
        }
        // Recv handshake response
        if ($connection->context->handshakeStep === 1) {
            return self::dealHandshake($buffer, $connection);
        }
        $recvLen = strlen($buffer);
        if ($recvLen < 2) {
            return 0;
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

            if ($masked) {
                Worker::safeEcho("frame masked so close the connection\n");
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
                    if (isset($connection->onWebSocketClose)) {
                        try {
                            ($connection->onWebSocketClose)($connection, self::decode($buffer, $connection));
                        } catch (Throwable $e) {
                            Worker::stopAll(250, $e);
                        }
                    } // Close connection.
                    else {
                        $connection->close();
                    }
                    return 0;
                // Wrong opcode.
                default :
                    Worker::safeEcho("error opcode $opcode and close websocket connection. Buffer:" . $buffer . "\n");
                    $connection->close();
                    return 0;
            }
            // Calculate packet length.
            if ($dataLen === 126) {
                if (strlen($buffer) < 4) {
                    return 0;
                }
                $pack = unpack('nn/ntotal_len', $buffer);
                $currentFrameLength = $pack['total_len'] + 4;
            } else if ($dataLen === 127) {
                if (strlen($buffer) < 10) {
                    return 0;
                }
                $arr = unpack('n/N2c', $buffer);
                $currentFrameLength = $arr['c1'] * 4294967296 + $arr['c2'] + 10;
            } else {
                $currentFrameLength = $dataLen + 2;
            }

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
                        if (isset($connection->onWebSocketPing)) {
                            try {
                                ($connection->onWebSocketPing)($connection, $pingData);
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
                        if (isset($connection->onWebSocketPong)) {
                            try {
                                ($connection->onWebSocketPong)($connection, $pongData);
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
            self::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->context->websocketCurrentFrameLength < $recvLen) {
            self::decode(substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return self::input(substr($buffer, $currentFrameLength), $connection);
        } // The length of the received data is less than the length of a frame.
        else {
            return 0;
        }
    }

    /**
     * Websocket encode.
     *
     * @param string $payload
     * @param AsyncTcpConnection $connection
     * @return string
     * @throws Throwable
     */
    public static function encode(string $payload, AsyncTcpConnection $connection): string
    {
        if (empty($connection->websocketType)) {
            $connection->websocketType = self::BINARY_TYPE_BLOB;
        }
        $connection->websocketOrigin = $connection->websocketOrigin ?? null;
        $connection->websocketClientProtocol = $connection->websocketClientProtocol ?? null;
        if (empty($connection->context->handshakeStep)) {
            static::sendHandshake($connection);
        }

        $maskKey = "\x00\x00\x00\x00";
        $length = strlen($payload);

        if (strlen($payload) < 126) {
            $head = chr(0x80 | $length);
        } elseif ($length < 0xFFFF) {
            $head = chr(0x80 | 126) . pack("n", $length);
        } else {
            $head = chr(0x80 | 127) . pack("N", 0) . pack("N", $length);
        }

        $frame = $connection->websocketType . $head . $maskKey;
        // append payload to frame:
        $maskKey = str_repeat($maskKey, (int)floor($length / 4)) . substr($maskKey, 0, $length % 4);
        $frame .= $payload ^ $maskKey;
        if ($connection->context->handshakeStep === 1) {
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
            $connection->context->tmpWebsocketData .= $frame;
            // Check buffer is full.
            if ($connection->onBufferFull && $connection->maxSendBufferSize <= strlen($connection->context->tmpWebsocketData)) {
                try {
                    ($connection->onBufferFull)($connection);
                } catch (Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
            return '';
        }
        return $frame;
    }

    /**
     * Websocket decode.
     *
     * @param string $bytes
     * @param AsyncTcpConnection $connection
     * @return string
     */
    public static function decode(string $bytes, AsyncTcpConnection $connection): string
    {
        $dataLength = ord($bytes[1]);

        if ($dataLength === 126) {
            $decodedData = substr($bytes, 4);
        } else if ($dataLength === 127) {
            $decodedData = substr($bytes, 10);
        } else {
            $decodedData = substr($bytes, 2);
        }
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decodedData;
            return $connection->context->websocketDataBuffer;
        }

        if ($connection->context->websocketDataBuffer !== '') {
            $decodedData = $connection->context->websocketDataBuffer . $decodedData;
            $connection->context->websocketDataBuffer = '';
        }
        return $decodedData;
    }

    /**
     * Send websocket handshake data.
     *
     * @param AsyncTcpConnection $connection
     * @return void
     * @throws Throwable
     */
    public static function onConnect(AsyncTcpConnection $connection): void
    {
        $connection->websocketOrigin = $connection->websocketOrigin ?? null;
        $connection->websocketClientProtocol = $connection->websocketClientProtocol ?? null;
        static::sendHandshake($connection);
    }

    /**
     * Clean
     *
     * @param AsyncTcpConnection $connection
     */
    public static function onClose(AsyncTcpConnection $connection): void
    {
        $connection->context->handshakeStep = null;
        $connection->context->websocketCurrentFrameLength = 0;
        $connection->context->tmpWebsocketData = '';
        $connection->context->websocketDataBuffer = '';
        if (!empty($connection->context->websocketPingTimer)) {
            Timer::del($connection->context->websocketPingTimer);
            $connection->context->websocketPingTimer = null;
        }
    }

    /**
     * Send websocket handshake.
     *
     * @param AsyncTcpConnection $connection
     * @return void
     * @throws Throwable
     */
    public static function sendHandshake(AsyncTcpConnection $connection): void
    {
        if (!empty($connection->context->handshakeStep)) {
            return;
        }
        // Get Host.
        $port = $connection->getRemotePort();
        $host = $port === 80 || $port === 443 ? $connection->getRemoteHost() : $connection->getRemoteHost() . ':' . $port;
        // Handshake header.
        $connection->context->websocketSecKey = base64_encode(random_bytes(16));
        $userHeader = $connection->headers ?? null;
        $userHeaderStr = '';
        if (!empty($userHeader)) {
            foreach ($userHeader as $k => $v) {
                $userHeaderStr .= "$k: $v\r\n";
            }
            $userHeaderStr = "\r\n" . trim($userHeaderStr);
        }
        $header = 'GET ' . $connection->getRemoteURI() . " HTTP/1.1\r\n" .
            (!preg_match("/\nHost:/i", $userHeaderStr) ? "Host: $host\r\n" : '') .
            "Connection: Upgrade\r\n" .
            "Upgrade: websocket\r\n" .
            (($connection->websocketOrigin ?? null) ? "Origin: " . $connection->websocketOrigin . "\r\n" : '') .
            (($connection->websocketClientProtocol ?? null) ? "Sec-WebSocket-Protocol: " . $connection->websocketClientProtocol . "\r\n" : '') .
            "Sec-WebSocket-Version: 13\r\n" .
            "Sec-WebSocket-Key: " . $connection->context->websocketSecKey . $userHeaderStr . "\r\n\r\n";
        $connection->send($header, true);
        $connection->context->handshakeStep = 1;
        $connection->context->websocketCurrentFrameLength = 0;
        $connection->context->websocketDataBuffer = '';
        $connection->context->tmpWebsocketData = '';
    }

    /**
     * Websocket handshake.
     *
     * @param string $buffer
     * @param AsyncTcpConnection $connection
     * @return bool|int
     */
    public static function dealHandshake(string $buffer, AsyncTcpConnection $connection): bool|int
    {
        $pos = strpos($buffer, "\r\n\r\n");
        if ($pos) {
            //checking Sec-WebSocket-Accept
            if (preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $buffer, $match)) {
                if ($match[1] !== base64_encode(sha1($connection->context->websocketSecKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
                    Worker::safeEcho("Sec-WebSocket-Accept not match. Header:\n" . substr($buffer, 0, $pos) . "\n");
                    $connection->close();
                    return 0;
                }
            } else {
                Worker::safeEcho("Sec-WebSocket-Accept not found. Header:\n" . substr($buffer, 0, $pos) . "\n");
                $connection->close();
                return 0;
            }

            // handshake complete
            $connection->context->handshakeStep = 2;
            $handshakeResponseLength = $pos + 4;
            $buffer = substr($buffer, 0, $handshakeResponseLength);
            $response = static::parseResponse($buffer);
            // Try to emit onWebSocketConnect callback.
            if (isset($connection->onWebSocketConnect)) {
                try {
                    ($connection->onWebSocketConnect)($connection, $response);
                } catch (Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
            // Headbeat.
            if (!empty($connection->websocketPingInterval)) {
                $connection->context->websocketPingTimer = Timer::add($connection->websocketPingInterval, function () use ($connection) {
                    if (false === $connection->send(pack('H*', '898000000000'), true)) {
                        Timer::del($connection->context->websocketPingTimer);
                        $connection->context->websocketPingTimer = null;
                    }
                });
            }

            $connection->consumeRecvBuffer($handshakeResponseLength);
            if (!empty($connection->context->tmpWebsocketData)) {
                $connection->send($connection->context->tmpWebsocketData, true);
                $connection->context->tmpWebsocketData = '';
            }
            if (strlen($buffer) > $handshakeResponseLength) {
                return self::input(substr($buffer, $handshakeResponseLength), $connection);
            }
        }
        return 0;
    }

    /**
     * Parse response.
     *
     * @param string $buffer
     * @return Response
     */
    protected static function parseResponse(string $buffer): Response
    {
        [$http_header, ] = explode("\r\n\r\n", $buffer, 2);
        $header_data = explode("\r\n", $http_header);
        [$protocol, $status, $phrase] = explode(' ', $header_data[0], 3);
        $protocolVersion = substr($protocol, 5);
        unset($header_data[0]);
        $headers = [];
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value) = explode(':', $content, 2);
            $value = trim($value);
            $headers[$key] = $value;
        }
        return (new Response())->withStatus((int)$status, $phrase)->withHeaders($headers)->withProtocolVersion($protocolVersion);
    }
}
