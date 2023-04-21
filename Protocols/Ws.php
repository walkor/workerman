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

namespace Workerman\Protocols;

use Workerman\Worker;
use Workerman\Timer;
use Workerman\Connection\TcpConnection;
use Workerman\Connection\ConnectionInterface;

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
    const BINARY_TYPE_BLOB = "\x81";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        if (empty($connection->context->handshakeStep)) {
            Worker::safeEcho("recv data before handshake. Buffer:" . \bin2hex($buffer) . "\n");
            return false;
        }
        // Recv handshake response
        if ($connection->context->handshakeStep === 1) {
            return self::dealHandshake($buffer, $connection);
        }
        $recvLen = \strlen($buffer);
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

            $firstbyte = \ord($buffer[0]);
            $secondbyte = \ord($buffer[1]);
            $dataLen = $secondbyte & 127;
            $isFinFrame = $firstbyte >> 7;
            $masked = $secondbyte >> 7;

            if ($masked) {
                Worker::safeEcho("frame masked so close the connection\n");
                $connection->close();
                return 0;
            }

            $opcode = $firstbyte & 0xf;

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
                            ($connection->onWebSocketClose)($connection);
                        } catch (\Throwable $e) {
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
                if (\strlen($buffer) < 4) {
                    return 0;
                }
                $pack = \unpack('nn/ntotal_len', $buffer);
                $currentFrameLength = $pack['total_len'] + 4;
            } else if ($dataLen === 127) {
                if (\strlen($buffer) < 10) {
                    return 0;
                }
                $arr = \unpack('n/N2c', $buffer);
                $currentFrameLength = $arr['c1'] * 4294967296 + $arr['c2'] + 10;
            } else {
                $currentFrameLength = $dataLen + 2;
            }

            $totalPackageSize = \strlen($connection->context->websocketDataBuffer) + $currentFrameLength;
            if ($totalPackageSize > $connection->maxPackageSize) {
                Worker::safeEcho("error package. package_length=$totalPackageSize\n");
                $connection->close();
                return 0;
            }

            if ($isFinFrame) {
                if ($opcode === 0x9) {
                    if ($recvLen >= $currentFrameLength) {
                        $pingData = static::decode(\substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = isset($connection->websocketType) ? $connection->websocketType : static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        if (isset($connection->onWebSocketPing)) {
                            try {
                                ($connection->onWebSocketPing)($connection, $pingData);
                            } catch (\Throwable $e) {
                                Worker::stopAll(250, $e);
                            }
                        } else {
                            $connection->send($pingData);
                        }
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(\substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;

                } else if ($opcode === 0xa) {
                    if ($recvLen >= $currentFrameLength) {
                        $pongData = static::decode(\substr($buffer, 0, $currentFrameLength), $connection);
                        $connection->consumeRecvBuffer($currentFrameLength);
                        $tmpConnectionType = isset($connection->websocketType) ? $connection->websocketType : static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        // Try to emit onWebSocketPong callback.
                        if (isset($connection->onWebSocketPong)) {
                            try {
                                ($connection->onWebSocketPong)($connection, $pongData);
                            } catch (\Throwable $e) {
                                Worker::stopAll(250, $e);
                            }
                        }
                        $connection->websocketType = $tmpConnectionType;
                        if ($recvLen > $currentFrameLength) {
                            return static::input(\substr($buffer, $currentFrameLength), $connection);
                        }
                    }
                    return 0;
                }
                return $currentFrameLength;
            } else {
                $connection->context->websocketCurrentFrameLength = $currentFrameLength;
            }
        }
        // Received just a frame length data.
        if ($connection->context->websocketCurrentFrameLength === $recvLen) {
            self::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->context->websocketCurrentFrameLength < $recvLen) {
            self::decode(\substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $currentFrameLength = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return self::input(\substr($buffer, $currentFrameLength), $connection);
        } // The length of the received data is less than the length of a frame.
        else {
            return 0;
        }
    }

    /**
     * Websocket encode.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($payload, ConnectionInterface $connection)
    {
        if (empty($connection->websocketType)) {
            $connection->websocketType = self::BINARY_TYPE_BLOB;
        }
        $payload = (string)$payload;
        if (empty($connection->context->handshakeStep)) {
            static::sendHandshake($connection);
        }

        $maskKey = "\x00\x00\x00\x00";
        $length = \strlen($payload);

        if (strlen($payload) < 126) {
            $head = chr(0x80 | $length);
        } elseif ($length < 0xFFFF) {
            $head = chr(0x80 | 126) . pack("n", $length);
        } else {
            $head = chr(0x80 | 127) . pack("N", 0) . pack("N", $length);
        }

        $frame = $connection->websocketType . $head . $maskKey;
        // append payload to frame:
        $maskKey = \str_repeat($maskKey, \floor($length / 4)) . \substr($maskKey, 0, $length % 4);
        $frame .= $payload ^ $maskKey;
        if ($connection->context->handshakeStep === 1) {
            // If buffer has already full then discard the current package.
            if (\strlen($connection->context->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        ($connection->onError)($connection, WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                    } catch (\Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }
                return '';
            }
            $connection->context->tmpWebsocketData = $connection->context->tmpWebsocketData . $frame;
            // Check buffer is full.
            if ($connection->maxSendBufferSize <= \strlen($connection->context->tmpWebsocketData)) {
                if ($connection->onBufferFull) {
                    try {
                        ($connection->onBufferFull)($connection);
                    } catch (\Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }
            }
            return '';
        }
        return $frame;
    }

    /**
     * Websocket decode.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function decode($bytes, ConnectionInterface $connection)
    {
        $dataLength = \ord($bytes[1]);

        if ($dataLength === 126) {
            $decodedData = \substr($bytes, 4);
        } else if ($dataLength === 127) {
            $decodedData = \substr($bytes, 10);
        } else {
            $decodedData = \substr($bytes, 2);
        }
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decodedData;
            return $connection->context->websocketDataBuffer;
        } else {
            if ($connection->context->websocketDataBuffer !== '') {
                $decodedData = $connection->context->websocketDataBuffer . $decodedData;
                $connection->context->websocketDataBuffer = '';
            }
            return $decodedData;
        }
    }

    /**
     * Send websocket handshake data.
     *
     * @return void
     */
    public static function onConnect($connection)
    {
        static::sendHandshake($connection);
    }

    /**
     * Clean
     *
     * @param TcpConnection $connection
     */
    public static function onClose($connection)
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
     * @param TcpConnection $connection
     * @return void
     */
    public static function sendHandshake(ConnectionInterface $connection)
    {
        if (!empty($connection->context->handshakeStep)) {
            return;
        }
        // Get Host.
        $port = $connection->getRemotePort();
        $host = $port === 80 ? $connection->getRemoteHost() : $connection->getRemoteHost() . ':' . $port;
        // Handshake header.
        $connection->context->websocketSecKey = \base64_encode(random_bytes(16));
        $userHeader = $connection->headers ?? null;
        $userHeaderStr = '';
        if (!empty($userHeader)) {
            if (\is_array($userHeader)) {
                foreach ($userHeader as $k => $v) {
                    $userHeaderStr .= "$k: $v\r\n";
                }
            } else {
                $userHeaderStr .= $userHeader;
            }
            $userHeaderStr = "\r\n" . \trim($userHeaderStr);
        }
        $header = 'GET ' . $connection->getRemoteURI() . " HTTP/1.1\r\n" .
            (!\preg_match("/\nHost:/i", $userHeaderStr) ? "Host: $host\r\n" : '') .
            "Connection: Upgrade\r\n" .
            "Upgrade: websocket\r\n" .
            (isset($connection->websocketOrigin) ? "Origin: " . $connection->websocketOrigin . "\r\n" : '') .
            (isset($connection->websocketClientProtocol) ? "Sec-WebSocket-Protocol: " . $connection->websocketClientProtocol . "\r\n" : '') .
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
     * @param TcpConnection $connection
     * @return int
     */
    public static function dealHandshake($buffer, ConnectionInterface $connection)
    {
        $pos = \strpos($buffer, "\r\n\r\n");
        if ($pos) {
            //checking Sec-WebSocket-Accept
            if (\preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $buffer, $match)) {
                if ($match[1] !== \base64_encode(\sha1($connection->context->websocketSecKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
                    Worker::safeEcho("Sec-WebSocket-Accept not match. Header:\n" . \substr($buffer, 0, $pos) . "\n");
                    $connection->close();
                    return 0;
                }
            } else {
                Worker::safeEcho("Sec-WebSocket-Accept not found. Header:\n" . \substr($buffer, 0, $pos) . "\n");
                $connection->close();
                return 0;
            }

            // handshake complete

            // Get WebSocket subprotocol (if specified by server)
            if (\preg_match("/Sec-WebSocket-Protocol: *(.*?)\r\n/i", $buffer, $match)) {
                $connection->websocketServerProtocol = \trim($match[1]);
            }

            $connection->context->handshakeStep = 2;
            $handshakeResponseLength = $pos + 4;
            // Try to emit onWebSocketConnect callback.
            if (isset($connection->onWebSocketConnect)) {
                try {
                    ($connection->onWebSocketConnect)($connection, \substr($buffer, 0, $handshakeResponseLength));
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }
            // Headbeat.
            if (!empty($connection->websocketPingInterval)) {
                $connection->context->websocketPingTimer = Timer::add($connection->websocketPingInterval, function () use ($connection) {
                    if (false === $connection->send(\pack('H*', '898000000000'), true)) {
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
            if (\strlen($buffer) > $handshakeResponseLength) {
                return self::input(\substr($buffer, $handshakeResponseLength), $connection);
            }
        }
        return 0;
    }

}
