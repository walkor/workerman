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
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return int
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        // Receive length.
        $recv_len = strlen($buffer);
        // We need more data.
        if ($recv_len < 6) {
            return 0;
        }

        // Has not yet completed the handshake.
        if (empty($connection->websocketHandshake)) {
            return static::dealHandshake($buffer, $connection);
        }

        // Buffer websocket frame data.
        if ($connection->websocketCurrentFrameLength) {
            // We need more frame data.
            if ($connection->websocketCurrentFrameLength > $recv_len) {
                // Return 0, because it is not clear the full packet length, waiting for the frame of fin=1.
                return 0;
            }
        } else {
            $firstbyte    = ord($buffer[0]);
            $secondbyte   = ord($buffer[1]);
            $data_len     = $secondbyte & 127;
            $is_fin_frame = $firstbyte >> 7;
            $masked       = $secondbyte >> 7;

            if (!$masked) {
                Worker::safeEcho("frame not masked so close the connection\n");
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
                    if (isset($connection->onWebSocketClose) || isset($connection->worker->onWebSocketClose)) {
                        try {
                            call_user_func(isset($connection->onWebSocketClose)?$connection->onWebSocketClose:$connection->worker->onWebSocketClose, $connection);
                        } catch (\Exception $e) {
                            Worker::log($e);
                            exit(250);
                        } catch (\Error $e) {
                            Worker::log($e);
                            exit(250);
                        }
                    } // Close connection.
                    else {
                        $connection->close("\x88\x02\x27\x10", true);
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
                $pack     = unpack('nn/ntotal_len', $buffer);
                $data_len = $pack['total_len'];
            } else {
                if ($data_len === 127) {
                    $head_len = 14;
                    if ($head_len > $recv_len) {
                        return 0;
                    }
                    $arr      = unpack('n/N2c', $buffer);
                    $data_len = $arr['c1']*4294967296 + $arr['c2'];
                }
            }
            $current_frame_length = $head_len + $data_len;

            $total_package_size = strlen($connection->websocketDataBuffer) + $current_frame_length;
            if ($total_package_size > TcpConnection::$maxPackageSize) {
                Worker::safeEcho("error package. package_length=$total_package_size\n");
                $connection->close();
                return 0;
            }

            if ($is_fin_frame) {
                if ($opcode === 0x9) {
                    if ($recv_len >= $current_frame_length) {
                        $ping_data = static::decode(substr($buffer, 0, $current_frame_length), $connection);
                        $connection->consumeRecvBuffer($current_frame_length);
                        $tmp_connection_type = isset($connection->websocketType) ? $connection->websocketType : static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        if (isset($connection->onWebSocketPing) || isset($connection->worker->onWebSocketPing)) {
                            try {
                                call_user_func(isset($connection->onWebSocketPing)?$connection->onWebSocketPing:$connection->worker->onWebSocketPing, $connection, $ping_data);
                            } catch (\Exception $e) {
                                Worker::log($e);
                                exit(250);
                            } catch (\Error $e) {
                                Worker::log($e);
                                exit(250);
                            }
                        } else {
                            $connection->send($ping_data);
                        }
                        $connection->websocketType = $tmp_connection_type;
                        if ($recv_len > $current_frame_length) {
                            return static::input(substr($buffer, $current_frame_length), $connection);
                        }
                    }
                    return 0;
                } else if ($opcode === 0xa) {
                    if ($recv_len >= $current_frame_length) {
                        $pong_data = static::decode(substr($buffer, 0, $current_frame_length), $connection);
                        $connection->consumeRecvBuffer($current_frame_length);
                        $tmp_connection_type = isset($connection->websocketType) ? $connection->websocketType : static::BINARY_TYPE_BLOB;
                        $connection->websocketType = "\x8a";
                        // Try to emit onWebSocketPong callback.
                        if (isset($connection->onWebSocketPong) || isset($connection->worker->onWebSocketPong)) {
                            try {
                                call_user_func(isset($connection->onWebSocketPong)?$connection->onWebSocketPong:$connection->worker->onWebSocketPong, $connection, $pong_data);
                            } catch (\Exception $e) {
                                Worker::log($e);
                                exit(250);
                            } catch (\Error $e) {
                                Worker::log($e);
                                exit(250);
                            }
                        }
                        $connection->websocketType = $tmp_connection_type;
                        if ($recv_len > $current_frame_length) {
                            return static::input(substr($buffer, $current_frame_length), $connection);
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
            static::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $connection->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->websocketCurrentFrameLength < $recv_len) {
            static::decode(substr($buffer, 0, $connection->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $current_frame_length                    = $connection->websocketCurrentFrameLength;
            $connection->websocketCurrentFrameLength = 0;
            // Continue to read next frame.
            return static::input(substr($buffer, $current_frame_length), $connection);
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
    public static function encode($buffer, ConnectionInterface $connection)
    {
        if (!is_scalar($buffer)) {
            throw new \Exception("You can't send(" . gettype($buffer) . ") to client, you need to convert it to a string. ");
        }
        $len = strlen($buffer);
        if (empty($connection->websocketType)) {
            $connection->websocketType = static::BINARY_TYPE_BLOB;
        }

        $first_byte = $connection->websocketType;

        if ($len <= 125) {
            $encode_buffer = $first_byte . chr($len) . $buffer;
        } else {
            if ($len <= 65535) {
                $encode_buffer = $first_byte . chr(126) . pack("n", $len) . $buffer;
            } else {
                $encode_buffer = $first_byte . chr(127) . pack("xxxxN", $len) . $buffer;
            }
        }

        // Handshake not completed so temporary buffer websocket data waiting for send.
        if (empty($connection->websocketHandshake)) {
            if (empty($connection->tmpWebsocketData)) {
                $connection->tmpWebsocketData = '';
            }
            // If buffer has already full then discard the current package.
            if (strlen($connection->tmpWebsocketData) > $connection->maxSendBufferSize) {
                if ($connection->onError) {
                    try {
                        call_user_func($connection->onError, $connection, WORKERMAN_SEND_FAIL, 'send buffer full and drop package');
                    } catch (\Exception $e) {
                        Worker::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        Worker::log($e);
                        exit(250);
                    }
                }
                return '';
            }
            $connection->tmpWebsocketData .= $encode_buffer;
            // Check buffer is full.
            if ($connection->maxSendBufferSize <= strlen($connection->tmpWebsocketData)) {
                if ($connection->onBufferFull) {
                    try {
                        call_user_func($connection->onBufferFull, $connection);
                    } catch (\Exception $e) {
                        Worker::log($e);
                        exit(250);
                    } catch (\Error $e) {
                        Worker::log($e);
                        exit(250);
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
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function decode($buffer, ConnectionInterface $connection)
    {
        $masks = $data = $decoded = '';
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data  = substr($buffer, 8);
        } else {
            if ($len === 127) {
                $masks = substr($buffer, 10, 4);
                $data  = substr($buffer, 14);
            } else {
                $masks = substr($buffer, 2, 4);
                $data  = substr($buffer, 6);
            }
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        if ($connection->websocketCurrentFrameLength) {
            $connection->websocketDataBuffer .= $decoded;
            return $connection->websocketDataBuffer;
        } else {
            if ($connection->websocketDataBuffer !== '') {
                $decoded                         = $connection->websocketDataBuffer . $decoded;
                $connection->websocketDataBuffer = '';
            }
            return $decoded;
        }
    }

    /**
     * Websocket handshake.
     *
     * @param string                              $buffer
     * @param \Workerman\Connection\TcpConnection $connection
     * @return int
     */
    protected static function dealHandshake($buffer, $connection)
    {
        // HTTP protocol.
        if (0 === strpos($buffer, 'GET')) {
            // Find \r\n\r\n.
            $heder_end_pos = strpos($buffer, "\r\n\r\n");
            if (!$heder_end_pos) {
                return 0;
            }
            $header_length = $heder_end_pos + 4;

            // Get Sec-WebSocket-Key.
            $Sec_WebSocket_Key = '';
            if (preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
                $Sec_WebSocket_Key = $match[1];
            } else {
                $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n<b>400 Bad Request</b><br>Sec-WebSocket-Key not found.<br>This is a WebSocket service and can not be accessed via HTTP.<br>See <a href=\"http://wiki.workerman.net/Error1\">http://wiki.workerman.net/Error1</a> for detail.",
                    true);
                $connection->close();
                return 0;
            }
            // Calculation websocket key.
            $new_key = base64_encode(sha1($Sec_WebSocket_Key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
            $handshake_message = "HTTP/1.1 101 Switching Protocols\r\n";
            $handshake_message .= "Upgrade: websocket\r\n";
            $handshake_message .= "Sec-WebSocket-Version: 13\r\n";
            $handshake_message .= "Connection: Upgrade\r\n";
            $handshake_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n";

            // Websocket data buffer.
            $connection->websocketDataBuffer = '';
            // Current websocket frame length.
            $connection->websocketCurrentFrameLength = 0;
            // Current websocket frame data.
            $connection->websocketCurrentFrameBuffer = '';
            // Consume handshake data.
            $connection->consumeRecvBuffer($header_length);

            // blob or arraybuffer
            if (empty($connection->websocketType)) {
                $connection->websocketType = static::BINARY_TYPE_BLOB;
            }

            $has_server_header = false;

            // Try to emit onWebSocketConnect callback.
            if (isset($connection->onWebSocketConnect) || isset($connection->worker->onWebSocketConnect)) {
                static::parseHttpHeader($buffer);
                try {
                    call_user_func(isset($connection->onWebSocketConnect)?$connection->onWebSocketConnect:$connection->worker->onWebSocketConnect, $connection, $buffer);
                } catch (\Exception $e) {
                    Worker::log($e);
                    exit(250);
                } catch (\Error $e) {
                    Worker::log($e);
                    exit(250);
                }
                if (!empty($_SESSION) && class_exists('\GatewayWorker\Lib\Context')) {
                    $connection->session = \GatewayWorker\Lib\Context::sessionEncode($_SESSION);
                }
                $_GET = $_SERVER = $_SESSION = $_COOKIE = array();

                if (isset($connection->headers)) {
                    if (is_array($connection->headers))  {
                        foreach ($connection->headers as $header) {
                            if (strpos($header, 'Server:') === 0) {
                                $has_server_header = true;
                            }
                            $handshake_message .= "$header\r\n";
                        }
                    } else {
                        $handshake_message .= "$connection->headers\r\n";
                    }
                }
            }
            if (!$has_server_header) {
                $handshake_message .= "Server: workerman/".Worker::VERSION."\r\n";
            }
            $handshake_message .= "\r\n";
            // Send handshake response.
            $connection->send($handshake_message, true);
            // Mark handshake complete..
            $connection->websocketHandshake = true;
            // There are data waiting to be sent.
            if (!empty($connection->tmpWebsocketData)) {
                $connection->send($connection->tmpWebsocketData, true);
                $connection->tmpWebsocketData = '';
            }
            if (strlen($buffer) > $header_length) {
                return static::input(substr($buffer, $header_length), $connection);
            }
            return 0;
        } // Is flash policy-file-request.
        elseif (0 === strpos($buffer, '<polic')) {
            $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>' . "\0";
            $connection->send($policy_xml, true);
            $connection->consumeRecvBuffer(strlen($buffer));
            return 0;
        }
        // Bad websocket handshake request.
        $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n<b>400 Bad Request</b><br>Invalid handshake data for websocket. <br> See <a href=\"http://wiki.workerman.net/Error1\">http://wiki.workerman.net/Error1</a> for detail.",
            true);
        $connection->close();
        return 0;
    }

    /**
     * Parse http header.
     *
     * @param string $buffer
     * @return void
     */
    protected static function parseHttpHeader($buffer)
    {
        // Parse headers.
        list($http_header, ) = explode("\r\n\r\n", $buffer, 2);
        $header_data = explode("\r\n", $http_header);

        if ($_SERVER) {
            $_SERVER = array();
        }

        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ',
            $header_data[0]);

        unset($header_data[0]);
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value)       = explode(':', $content, 2);
            $key                     = str_replace('-', '_', strtoupper($key));
            $value                   = trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            switch ($key) {
                // HTTP_HOST
                case 'HOST':
                    $tmp                    = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // cookie
                case 'COOKIE':
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
            }
        }

        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }
    }
}
