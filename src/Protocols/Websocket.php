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

use Workerman\Connection\ConnectionInterface;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Worker;

/**
 * WebSocket protocol.
 */
class Websocket implements \Workerman\Protocols\ProtocolInterface
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
        // Receive length.
        $recv_len = \strlen($buffer);
        // We need more data.
        if ($recv_len < 6) {
            return 0;
        }

        // Has not yet completed the handshake.
        if (empty($connection->context->websocketHandshake)) {
            return static::dealHandshake($buffer, $connection);
        }

        // Buffer websocket frame data.
        if ($connection->context->websocketCurrentFrameLength) {
            // We need more frame data.
            if ($connection->context->websocketCurrentFrameLength > $recv_len) {
                // Return 0, because it is not clear the full packet length, waiting for the frame of fin=1.
                return 0;
            }
        } else {
            $firstbyte = \ord($buffer[0]);
            $secondbyte = \ord($buffer[1]);
            $data_len = $secondbyte & 127;
            $is_fin_frame = $firstbyte >> 7;
            $masked = $secondbyte >> 7;

            if (!$masked) {
                Worker::safeEcho("frame not masked so close the connection\n");
                $connection->close();
                return 0;
            }

            $opcode = $firstbyte & 0xf;
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
                    $close_cb = $connection->onWebSocketClose ?? $connection->worker->onWebSocketClose ?? false;
                    if ($close_cb) {
                        try {
                            $close_cb($connection);
                        } catch (\Throwable $e) {
                            Worker::stopAll(250, $e);
                        }
                    } // Close connection.
                    else {
                        $connection->close("\x88\x02\x03\xe8", true);
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
                    Worker::safeEcho("error opcode $opcode and close websocket connection. Buffer:" . bin2hex($buffer) . "\n");
                    $connection->close();
                    return 0;
            }

            // Calculate packet length.
            $head_len = 6;
            if ($data_len === 126) {
                $head_len = 8;
                if ($head_len > $recv_len) {
                    return 0;
                }
                $pack = \unpack('nn/ntotal_len', $buffer);
                $data_len = $pack['total_len'];
            } else {
                if ($data_len === 127) {
                    $head_len = 14;
                    if ($head_len > $recv_len) {
                        return 0;
                    }
                    $arr = \unpack('n/N2c', $buffer);
                    $data_len = $arr['c1'] * 4294967296 + $arr['c2'];
                }
            }
            $current_frame_length = $head_len + $data_len;

            $total_package_size = \strlen($connection->context->websocketDataBuffer) + $current_frame_length;
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
                        $tmp_connection_type = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        $ping_cb = $connection->onWebSocketPing ?? $connection->worker->onWebSocketPing ?? false;
                        if ($ping_cb) {
                            try {
                                $ping_cb($connection, $ping_data);
                            } catch (\Throwable $e) {
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
                        $tmp_connection_type = $connection->websocketType ?? static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        // Try to emit onWebSocketPong callback.
                        $pong_cb = $connection->onWebSocketPong ?? $connection->worker->onWebSocketPong ?? false;
                        if ($pong_cb) {
                            try {
                                $pong_cb($connection, $pong_data);
                            } catch (\Throwable $e) {
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
                $connection->context->websocketCurrentFrameLength = $current_frame_length;
            }
        }

        // Received just a frame length data.
        if ($connection->context->websocketCurrentFrameLength === $recv_len) {
            static::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $connection->context->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->context->websocketCurrentFrameLength < $recv_len) {
            static::decode(\substr($buffer, 0, $connection->context->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->context->websocketCurrentFrameLength);
            $current_frame_length = $connection->context->websocketCurrentFrameLength;
            $connection->context->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return static::input(\substr($buffer, $current_frame_length), $connection);
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
    public static function encode($buffer, ConnectionInterface $connection)
    {
        if (!is_scalar($buffer)) {
            throw new \Exception("You can't send(" . \gettype($buffer) . ") to client, you need to convert it to a string. ");
        }
        $len = \strlen($buffer);
        if (empty($connection->websocketType)) {
            $connection->websocketType = static::BINARY_TYPE_BLOB;
        }

        $first_byte = $connection->websocketType;

        if ($len <= 125) {
            $encode_buffer = $first_byte . \chr($len) . $buffer;
        } else {
            if ($len <= 65535) {
                $encode_buffer = $first_byte . \chr(126) . \pack("n", $len) . $buffer;
            } else {
                $encode_buffer = $first_byte . \chr(127) . \pack("xxxxN", $len) . $buffer;
            }
        }

        // Handshake not completed so temporary buffer websocket data waiting for send.
        if (empty($connection->context->websocketHandshake)) {
            if (empty($connection->context->tmpWebsocketData)) {
                $connection->context->tmpWebsocketData = '';
            }
            // If buffer has already full then discard the current package.
            if (\strlen($connection->context->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        ($connection->onError)($connection, ConnectionInterface::SEND_FAIL, 'send buffer full and drop package');
                    } catch (\Throwable $e) {
                        Worker::stopAll(250, $e);
                    }
                }
                return '';
            }
            $connection->context->tmpWebsocketData .= $encode_buffer;
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
            // Return empty string.
            return '';
        }

        return $encode_buffer;
    }

    /**
     * Websocket decode.
     *
     * @param string $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function decode($buffer, ConnectionInterface $connection)
    {
        $first_byte = \ord($buffer[1]);
        $len = $first_byte & 127;
        $rsv1 = $first_byte & 64;

        if ($len === 126) {
            $masks = \substr($buffer, 4, 4);
            $data = \substr($buffer, 8);
        } else {
            if ($len === 127) {
                $masks = \substr($buffer, 10, 4);
                $data = \substr($buffer, 14);
            } else {
                $masks = \substr($buffer, 2, 4);
                $data = \substr($buffer, 6);
            }
        }
        $dataLength = \strlen($data);
        $masks = \str_repeat($masks, \floor($dataLength / 4)) . \substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($connection->context->websocketCurrentFrameLength) {
            $connection->context->websocketDataBuffer .= $decoded;
            return $connection->context->websocketDataBuffer;
        } else {
            if ($connection->context->websocketDataBuffer !== '') {
                $decoded = $connection->context->websocketDataBuffer . $decoded;
                $connection->context->websocketDataBuffer = '';
            }
            return $decoded;
        }
    }

    /**
     * Websocket handshake.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function dealHandshake($buffer, TcpConnection $connection)
    {
        // HTTP protocol.
        if (0 === \strpos($buffer, 'GET')) {
            // Find \r\n\r\n.
            $header_end_pos = \strpos($buffer, "\r\n\r\n");
            if (!$header_end_pos) {
                return 0;
            }
            $header_length = $header_end_pos + 4;

            // Get Sec-WebSocket-Key.
            $Sec_WebSocket_Key = '';
            if (\preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
                $Sec_WebSocket_Key = $match[1];
            } else {
                $connection->close("HTTP/1.1 200 WebSocket\r\nServer: workerman/" . Worker::VERSION . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>workerman/" . Worker::VERSION . "</div>",
                    true);
                return 0;
            }
            // Calculation websocket key.
            $new_key = \base64_encode(\sha1($Sec_WebSocket_Key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
            $handshake_message = "HTTP/1.1 101 Switching Protocols\r\n"
                . "Upgrade: websocket\r\n"
                . "Sec-WebSocket-Version: 13\r\n"
                . "Connection: Upgrade\r\n"
                . "Sec-WebSocket-Accept: " . $new_key . "\r\n";

            // Websocket data buffer.
            $connection->context->websocketDataBuffer = '';
            // Current websocket frame length.
            $connection->context->websocketCurrentFrameLength = 0;
            // Current websocket frame data.
            $connection->context->websocketCurrentFrameBuffer = '';
            // Consume handshake data.
            $connection->consumeRecvBuffer($header_length);

            // Try to emit onWebSocketConnect callback.
            $on_websocket_connect = $connection->onWebSocketConnect ?? $connection->worker->onWebSocketConnect ?? false;
            if ($on_websocket_connect) {
                try {
                    $on_websocket_connect($connection, new Request($buffer));
                } catch (\Throwable $e) {
                    Worker::stopAll(250, $e);
                }
            }

            // blob or arraybuffer
            if (empty($connection->websocketType)) {
                $connection->websocketType = static::BINARY_TYPE_BLOB;
            }

            $has_server_header = false;

            if (isset($connection->headers)) {
                if (\is_array($connection->headers)) {
                    foreach ($connection->headers as $header) {
                        if (\stripos($header, 'Server:') === 0) {
                            $has_server_header = true;
                        }
                        $handshake_message .= "$header\r\n";
                    }
                } else {
                    if (\stripos($connection->headers, 'Server:') !== false) {
                        $has_server_header = true;
                    }
                    $handshake_message .= "$connection->headers\r\n";
                }
            }
            if (!$has_server_header) {
                $handshake_message .= "Server: workerman/" . Worker::VERSION . "\r\n";
            }
            $handshake_message .= "\r\n";
            // Send handshake response.
            $connection->send($handshake_message, true);
            // Mark handshake complete..
            $connection->context->websocketHandshake = true;

            // There are data waiting to be sent.
            if (!empty($connection->context->tmpWebsocketData)) {
                $connection->send($connection->context->tmpWebsocketData, true);
                $connection->context->tmpWebsocketData = '';
            }
            if (\strlen($buffer) > $header_length) {
                return static::input(\substr($buffer, $header_length), $connection);
            }
            return 0;
        }
        // Bad websocket handshake request.
        $connection->close("HTTP/1.1 200 WebSocket\r\nServer: workerman/" . Worker::VERSION . "\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>workerman/" . Worker::VERSION . "</div>",
            true);
        return 0;
    }

}
