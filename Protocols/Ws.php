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
use Workerman\Lib\Timer;
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
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        if (empty($connection->handshakeStep)) {
            Worker::safeEcho("recv data before handshake. Buffer:" . \bin2hex($buffer) . "\n");
            return false;
        }
        // Recv handshake response
        if ($connection->handshakeStep === 1) {
            return self::dealHandshake($buffer, $connection);
        }
        $recv_len = \strlen($buffer);
        if ($recv_len < 2) {
            return 0;
        }
        // Buffer websocket frame data.
        if ($connection->websocketCurrentFrameLength) {
            // We need more frame data.
            if ($connection->websocketCurrentFrameLength > $recv_len) {
                // Return 0, because it is not clear the full packet length, waiting for the frame of fin=1.
                return 0;
            }
        } else {

            $firstbyte    = \ord($buffer[0]);
            $secondbyte   = \ord($buffer[1]);
            $data_len     = $secondbyte & 127;
            $is_fin_frame = $firstbyte >> 7;
            $masked       = $secondbyte >> 7;

            if ($masked) {
                Worker::safeEcho("frame masked so close the connection\n");
                $connection->close();
                return 0;
            }

            $opcode       = $firstbyte & 0xf;

            switch ($opcode) {
                case 0x0:
                    break;
                // Blob type.
                case 0x1:
                    break;
                // Arraybuffer type.
                case 0x2:
                    break;
                // Close package.
                case 0x8:
                    // Try to emit onWebSocketClose callback.
                    if (isset($connection->onWebSocketClose)) {
                        try {
                            \call_user_func($connection->onWebSocketClose, $connection);
                        } catch (\Exception $e) {
                            Worker::stopAll(250, $e);
                        } catch (\Error $e) {
                            Worker::stopAll(250, $e);
                        }
                    } // Close connection.
                    else {
                        $connection->close();
                    }
                    return 0;
                // Ping package.
                case 0x9:
                    break;
                // Pong package.
                case 0xa:
                    break;
                // Wrong opcode.
                default :
                    Worker::safeEcho("error opcode $opcode and close websocket connection. Buffer:" . $buffer . "\n");
                    $connection->close();
                    return 0;
            }
            // Calculate packet length.
            if ($data_len === 126) {
                if (\strlen($buffer) < 4) {
                    return 0;
                }
                $pack = \unpack('nn/ntotal_len', $buffer);
                $current_frame_length = $pack['total_len'] + 4;
            } else if ($data_len === 127) {
                if (\strlen($buffer) < 10) {
                    return 0;
                }
                $arr = \unpack('n/N2c', $buffer);
                $current_frame_length = $arr['c1']*4294967296 + $arr['c2'] + 10;
            } else {
                $current_frame_length = $data_len + 2;
            }

            $total_package_size = \strlen($connection->websocketDataBuffer) + $current_frame_length;
            if ($total_package_size > $connection->maxPackageSize) {
                Worker::safeEcho("error package. package_length=$total_package_size\n");
                $connection->close();
                return 0;
            }

            if ($is_fin_frame) {
                if ($opcode === 0x9) {
                    if ($recv_len >= $current_frame_length) {
                        $ping_data = static::decode(\substr($buffer, 0, $current_frame_length), $connection);
                        $connection->consumeRecvBuffer($current_frame_length);
                        $tmp_connection_type = isset($connection->websocketType) ? $connection->websocketType : static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        if (isset($connection->onWebSocketPing)) {
                            try {
                                \call_user_func($connection->onWebSocketPing, $connection, $ping_data);
                            } catch (\Exception $e) {
                                Worker::stopAll(250, $e);
                            } catch (\Error $e) {
                                Worker::stopAll(250, $e);
                            }
                        } else {
                            $connection->send($ping_data);
                        }
                        $connection->websocketType = $tmp_connection_type;
                        if ($recv_len > $current_frame_length) {
                            return static::input(\substr($buffer, $current_frame_length), $connection);
                        }
                    }
                    return 0;

                } else if ($opcode === 0xa) {
                    if ($recv_len >= $current_frame_length) {
                        $pong_data = static::decode(\substr($buffer, 0, $current_frame_length), $connection);
                        $connection->consumeRecvBuffer($current_frame_length);
                        $tmp_connection_type = isset($connection->websocketType) ? $connection->websocketType : static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        // Try to emit onWebSocketPong callback.
                        if (isset($connection->onWebSocketPong)) {
                            try {
                                \call_user_func($connection->onWebSocketPong, $connection, $pong_data);
                            } catch (\Exception $e) {
                                Worker::stopAll(250, $e);
                            } catch (\Error $e) {
                                Worker::stopAll(250, $e);
                            }
                        }
                        $connection->websocketType = $tmp_connection_type;
                        if ($recv_len > $current_frame_length) {
                            return static::input(\substr($buffer, $current_frame_length), $connection);
                        }
                    }
                    return 0;
                }
                return $current_frame_length;
            } else {
                $connection->websocketCurrentFrameLength = $current_frame_length;
            }
        }
        // Received just a frame length data.
        if ($connection->websocketCurrentFrameLength === $recv_len) {
            self::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $connection->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->websocketCurrentFrameLength < $recv_len) {
            self::decode(\substr($buffer, 0, $connection->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $current_frame_length                    = $connection->websocketCurrentFrameLength;
            $connection->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return self::input(\substr($buffer, $current_frame_length), $connection);
        } // The length of the received data is less than the length of a frame.
        else {
            return 0;
        }
    }

    /**
     * Websocket encode.
     *
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($payload, ConnectionInterface $connection)
    {
        if (empty($connection->websocketType)) {
            $connection->websocketType = self::BINARY_TYPE_BLOB;
        }
        $payload = (string)$payload;
        if (empty($connection->handshakeStep)) {
            static::sendHandshake($connection);
        }
        $mask = 1;
        $mask_key = "\x00\x00\x00\x00";

        $pack = '';
        $length = $length_flag = \strlen($payload);
        if (65535 < $length) {
            $pack   = \pack('NN', ($length & 0xFFFFFFFF00000000) >> 32, $length & 0x00000000FFFFFFFF);
            $length_flag = 127;
        } else if (125 < $length) {
            $pack   = \pack('n*', $length);
            $length_flag = 126;
        }

        $head = ($mask << 7) | $length_flag;
        $head = $connection->websocketType . \chr($head) . $pack;

        $frame = $head . $mask_key;
        // append payload to frame:
        $mask_key = \str_repeat($mask_key, \floor($length / 4)) . \substr($mask_key, 0, $length % 4);
        $frame .= $payload ^ $mask_key;
        if ($connection->handshakeStep === 1) {
            // If buffer has already full then discard the current package.
            if (\strlen($connection->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        \call_user_func($connection->onError, $connection, \WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                    } catch (\Exception $e) {
                        Worker::stopAll(250, $e);
                    } catch (\Error $e) {
                        Worker::stopAll(250, $e);
                    }
                }
                return '';
            }
            $connection->tmpWebsocketData = $connection->tmpWebsocketData . $frame;
            // Check buffer is full.
            if ($connection->maxSendBufferSize <= \strlen($connection->tmpWebsocketData)) {
                if ($connection->onBufferFull) {
                    try {
                        \call_user_func($connection->onBufferFull, $connection);
                    } catch (\Exception $e) {
                        Worker::stopAll(250, $e);
                    } catch (\Error $e) {
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
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function decode($bytes, ConnectionInterface $connection)
    {
        $data_length = \ord($bytes[1]);

        if ($data_length === 126) {
            $decoded_data = \substr($bytes, 4);
        } else if ($data_length === 127) {
            $decoded_data = \substr($bytes, 10);
        } else {
            $decoded_data = \substr($bytes, 2);
        }
        if ($connection->websocketCurrentFrameLength) {
            $connection->websocketDataBuffer .= $decoded_data;
            return $connection->websocketDataBuffer;
        } else {
            if ($connection->websocketDataBuffer !== '') {
                $decoded_data                    = $connection->websocketDataBuffer . $decoded_data;
                $connection->websocketDataBuffer = '';
            }
            return $decoded_data;
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
        $connection->handshakeStep               = null;
        $connection->websocketCurrentFrameLength = 0;
        $connection->tmpWebsocketData            = '';
        $connection->websocketDataBuffer         = '';
        if (!empty($connection->websocketPingTimer)) {
            Timer::del($connection->websocketPingTimer);
            $connection->websocketPingTimer = null;
        }
    }

    /**
     * Send websocket handshake.
     *
     * @param TcpConnection $connection
     * @return void
     */
    public static function sendHandshake(TcpConnection $connection)
    {
        if (!empty($connection->handshakeStep)) {
            return;
        }
        // Get Host.
        $port = $connection->getRemotePort();
        $host = $port === 80 ? $connection->getRemoteHost() : $connection->getRemoteHost() . ':' . $port;
        // Handshake header.
        $connection->websocketSecKey = \base64_encode(\md5(\mt_rand(), true));
        $user_header = isset($connection->headers) ? $connection->headers :
            (isset($connection->wsHttpHeader) ? $connection->wsHttpHeader : null);
        $user_header_str = '';
        if (!empty($user_header)) {
            if (\is_array($user_header)){
                foreach($user_header as $k=>$v){
                    $user_header_str .= "$k: $v\r\n";
                }
            } else {
                $user_header_str .= $user_header;
            }
            $user_header_str = "\r\n".\trim($user_header_str);
        }
        $header = 'GET ' . $connection->getRemoteURI() . " HTTP/1.1\r\n".
        (!\preg_match("/\nHost:/i", $user_header_str) ? "Host: $host\r\n" : '').
        "Connection: Upgrade\r\n".
        "Upgrade: websocket\r\n".
        (isset($connection->websocketOrigin) ? "Origin: ".$connection->websocketOrigin."\r\n":'').
        (isset($connection->WSClientProtocol)?"Sec-WebSocket-Protocol: ".$connection->WSClientProtocol."\r\n":'').
        "Sec-WebSocket-Version: 13\r\n".
        "Sec-WebSocket-Key: " . $connection->websocketSecKey . $user_header_str . "\r\n\r\n";
        $connection->send($header, true);
        $connection->handshakeStep               = 1;
        $connection->websocketCurrentFrameLength = 0;
        $connection->websocketDataBuffer         = '';
        $connection->tmpWebsocketData            = '';
    }

    /**
     * Websocket handshake.
     *
     * @param string                              $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function dealHandshake($buffer, TcpConnection $connection)
    {
        $pos = \strpos($buffer, "\r\n\r\n");
        if ($pos) {
            //checking Sec-WebSocket-Accept
            if (\preg_match("/Sec-WebSocket-Accept: *(.*?)\r\n/i", $buffer, $match)) {
                if ($match[1] !== \base64_encode(\sha1($connection->websocketSecKey . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true))) {
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
                $connection->WSServerProtocol = \trim($match[1]);
            }

            $connection->handshakeStep = 2;
            $handshake_response_length = $pos + 4;
            // Try to emit onWebSocketConnect callback.
            if (isset($connection->onWebSocketConnect)) {
                try {
                    \call_user_func($connection->onWebSocketConnect, $connection, \substr($buffer, 0, $handshake_response_length));
                } catch (\Exception $e) {
                    Worker::stopAll(250, $e);
                } catch (\Error $e) {
                    Worker::stopAll(250, $e);
                }
            }
            // Headbeat.
            if (!empty($connection->websocketPingInterval)) {
                $connection->websocketPingTimer = Timer::add($connection->websocketPingInterval, function() use ($connection){
                    if (false === $connection->send(\pack('H*', '898000000000'), true)) {
                        Timer::del($connection->websocketPingTimer);
                        $connection->websocketPingTimer = null;
                    }
                });
            }

            $connection->consumeRecvBuffer($handshake_response_length);
            if (!empty($connection->tmpWebsocketData)) {
                $connection->send($connection->tmpWebsocketData, true);
                $connection->tmpWebsocketData = '';
            }
            if (\strlen($buffer) > $handshake_response_length) {
                return self::input(\substr($buffer, $handshake_response_length), $connection);
            }
        }
        return 0;
    }

    public static function WSSetProtocol($connection, $params) {
	$connection->WSClientProtocol = $params[0];
    }

    public static function WSGetServerProtocol($connection) {
	return (\property_exists($connection, 'WSServerProtocol') ? $connection->WSServerProtocol : null);
    }

}
