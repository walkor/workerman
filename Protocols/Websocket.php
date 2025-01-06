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
    const BINARY_TYPE_ARRAYBUFFER = "\x82";

    /**
     * Websocket arraybuffer type.
     *
     * @var string
     */
    const BINARY_TYPE_ARRAYBUFFER_DEFLATE = "\xc2";

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
        $recv_len = \strlen($buffer);
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
            $firstbyte    = \ord($buffer[0]);
            $secondbyte   = \ord($buffer[1]);
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
                            \call_user_func(isset($connection->onWebSocketClose)?$connection->onWebSocketClose:$connection->worker->onWebSocketClose, $connection);
                        } catch (\Exception $e) {
                            Worker::stopAll(250, $e);
                        } catch (\Error $e) {
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
                $pack     = \unpack('nn/ntotal_len', $buffer);
                $data_len = $pack['total_len'];
            } else {
                if ($data_len === 127) {
                    $head_len = 14;
                    if ($head_len > $recv_len) {
                        return 0;
                    }
                    $arr      = \unpack('n/N2c', $buffer);
                    $data_len = $arr['c1']*4294967296 + $arr['c2'];
                }
            }
            $current_frame_length = $head_len + $data_len;

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
                        if (isset($connection->onWebSocketPing) || isset($connection->worker->onWebSocketPing)) {
                            try {
                                \call_user_func(isset($connection->onWebSocketPing)?$connection->onWebSocketPing:$connection->worker->onWebSocketPing, $connection, $ping_data);
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
                        if (isset($connection->onWebSocketPong) || isset($connection->worker->onWebSocketPong)) {
                            try {
                                \call_user_func(isset($connection->onWebSocketPong)?$connection->onWebSocketPong:$connection->worker->onWebSocketPong, $connection, $pong_data);
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
            static::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $connection->websocketCurrentFrameLength = 0;
            return 0;
        } // The length of the received data is greater than the length of a frame.
        elseif ($connection->websocketCurrentFrameLength < $recv_len) {
            static::decode(\substr($buffer, 0, $connection->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $current_frame_length                    = $connection->websocketCurrentFrameLength;
            $connection->websocketCurrentFrameLength = 0;
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
     * @param string              $buffer
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

        // permessage-deflate
        if (\ord($connection->websocketType) & 64) {
            $buffer = static::deflate($connection, $buffer);
        }

        $first_byte = $connection->websocketType;
        $len = \strlen($buffer);

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
        if (empty($connection->websocketHandshake)) {
            if (empty($connection->tmpWebsocketData)) {
                $connection->tmpWebsocketData = '';
            }
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
            $connection->tmpWebsocketData .= $encode_buffer;
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
        $first_byte = \ord($buffer[0]);
        $second_byte = \ord($buffer[1]);
        $len = $second_byte & 127;
        $is_fin_frame = $first_byte >> 7;
        $rsv1 = 64 === ($first_byte & 64);

        if ($len === 126) {
            $masks = \substr($buffer, 4, 4);
            $data  = \substr($buffer, 8);
        } else {
            if ($len === 127) {
                $masks = \substr($buffer, 10, 4);
                $data  = \substr($buffer, 14);
            } else {
                $masks = \substr($buffer, 2, 4);
                $data  = \substr($buffer, 6);
            }
        }
        $dataLength = \strlen($data);
        $masks = \str_repeat($masks, \floor($dataLength / 4)) . \substr($masks, 0, $dataLength % 4);
        $decoded = $data ^ $masks;
        if ($connection->websocketCurrentFrameLength) {
            $connection->websocketDataBuffer .= $decoded;
            if ($rsv1) {
                return static::inflate($connection, $connection->websocketDataBuffer, $is_fin_frame);
            }
            return $connection->websocketDataBuffer;
        } else {
            if ($connection->websocketDataBuffer !== '') {
                $decoded                         = $connection->websocketDataBuffer . $decoded;
                $connection->websocketDataBuffer = '';
            }
            if ($rsv1) {
                return static::inflate($connection, $decoded, $is_fin_frame);
            }
            return $decoded;
        }
    }

    /**
     * Inflate.
     *
     * @param $connection
     * @param $buffer
     * @param $is_fin_frame
     * @return false|string
     */
    protected static function inflate($connection, $buffer, $is_fin_frame)
    {
        if (!isset($connection->inflator)) {
            $connection->inflator = \inflate_init(
                \ZLIB_ENCODING_RAW,
                [
                    'level'    => -1,
                    'memory'   => 8,
                    'window'   => 15,
                    'strategy' => \ZLIB_DEFAULT_STRATEGY
                ]
            );
        }
        # websocket-sharp add \x00\x00\xff\xff
        if ($is_fin_frame) { 
            $buffer .= "\x00\x00\xff\xff";
        }
        // $buffer .= "\x00\x00\xff\xff";
        // $buffer .= "\x00";
        $ret = \inflate_add($connection->inflator, $buffer);
        // if ($is_fin_frame) { 
        //     $ret .= "\x00\x00\xff\xff";
        // }
        # websocket-sharp add +1  BFINAL 
        // $ret[0]=\chr(\ord($ret[0])+1);
        return $ret;
    }

    /**
     * Deflate.
     *
     * @param $connection
     * @param $buffer
     * @return false|string
     */
    protected static function deflate($connection, $buffer)
    {
        if (!isset($connection->deflator)) {
            $connection->deflator = \deflate_init(
                \ZLIB_ENCODING_RAW,
                [
                    'level'    => -1,
                    'memory'   => 8,
                    'window'   => 15,
                    'strategy' => \ZLIB_DEFAULT_STRATEGY
                ]
            );
        }
        return \substr(\deflate_add($connection->deflator, $buffer), 0, -4);
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
        // HTTP protocol.
        if (0 === \strpos($buffer, 'GET')) {
            // Find \r\n\r\n.
            $heder_end_pos = \strpos($buffer, "\r\n\r\n");
            if (!$heder_end_pos) {
                return 0;
            }
            $header_length = $heder_end_pos + 4;

            // Get Sec-WebSocket-Key.
            $Sec_WebSocket_Key = '';
            if (\preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match)) {
                $Sec_WebSocket_Key = $match[1];
            } else {
                $connection->close("HTTP/1.0 400 Bad Request\r\nServer: workerman\r\n\r\n<div style=\"text-align:center\"><h1>WebSocket</h1><hr>workerman</div>", true);
                return 0;
            }
            // Calculation websocket key.
            $new_key = \base64_encode(\sha1($Sec_WebSocket_Key . "258EAFA5-E914-47DA-95CA-C5AB0DC85B11", true));
            // Handshake response data.
            $handshake_message = "HTTP/1.1 101 Switching Protocols\r\n"
                                ."Upgrade: websocket\r\n"
                                ."Sec-WebSocket-Version: 13\r\n"
                                ."Connection: Upgrade\r\n"
                                ."Sec-WebSocket-Accept: " . $new_key . "\r\n";
            
            // Increase automatic compression
            if(
                \defined('WORKERMAN_WEBSOCKET_DEFLATE') 
                && WORKERMAN_WEBSOCKET_DEFLATE
                && \preg_match("/Sec-WebSocket-Extensions: .*permessage-deflate.*\r\n/i", $buffer, $matchEX)
            ){
                $headExt = "Sec-WebSocket-Extensions: permessage-deflate";
                if(\preg_match("/Sec-WebSocket-Extensions: .*server_no_context_takeover.*\r\n/i", $buffer, $matchEX))$headExt .= "; server_no_context_takeover";
                if(\preg_match("/Sec-WebSocket-Extensions: .*client_no_context_takeover.*\r\n/i", $buffer, $matchEX))$headExt .= "; client_no_context_takeover";
                if(\preg_match("/Sec-WebSocket-Extensions: .*server_max_window_bits.*\r\n/i", $buffer, $matchEX))$headExt .= "; server_max_window_bits=15";
                if(\preg_match("/Sec-WebSocket-Extensions: .*client_max_window_bits.*\r\n/i", $buffer, $matchEX))$headExt .= "; client_max_window_bits=15";
                // $handshake_message.="Sec-WebSocket-Extensions: permessage-deflate; server_no_context_takeover; client_no_context_takeover; client_max_window_bits=15; server_max_window_bits=15\r\n";
                // if(\preg_match("/websocket-sharp/i", $buffer, $matchEX)){
                //     # c# websocket-sharp not client_no_context_takeover
                //     $handshake_message = str_replace('client_no_context_takeover; ','',$handshake_message);
                // }
                $handshake_message .= $headExt."\r\n";
                $connection->websocketType = Websocket::BINARY_TYPE_BLOB_DEFLATE;
            }

            // Websocket data buffer.
            $connection->websocketDataBuffer = '';
            // Current websocket frame length.
            $connection->websocketCurrentFrameLength = 0;
            // Current websocket frame data.
            $connection->websocketCurrentFrameBuffer = '';
            // Consume handshake data.
            $connection->consumeRecvBuffer($header_length);

            // Try to emit onWebSocketConnect callback.
            $on_websocket_connect = $connection->onWebSocketConnect ?? $connection->worker->onWebSocketConnect ?? false;
            if ($on_websocket_connect) {
                static::parseHttpHeader($buffer);
                try {
                    \call_user_func($on_websocket_connect, $connection, $buffer);
                } catch (\Exception $e) {
                    Worker::stopAll(250, $e);
                } catch (\Error $e) {
                    Worker::stopAll(250, $e);
                }
                if (!empty($_SESSION) && \class_exists('\GatewayWorker\Lib\Context')) {
                    $connection->session = \GatewayWorker\Lib\Context::sessionEncode($_SESSION);
                }
                $_GET = $_SERVER = $_SESSION = $_COOKIE = array();
            }

            // blob or arraybuffer
            if (empty($connection->websocketType)) {
                $connection->websocketType = static::BINARY_TYPE_BLOB;
            }

            $has_server_header = false;

            if (isset($connection->headers)) {
                if (\is_array($connection->headers))  {
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
            if (\strlen($buffer) > $header_length) {
                return static::input(\substr($buffer, $header_length), $connection);
            }
            return 0;
        } // Is flash policy-file-request.
        elseif (0 === \strpos($buffer, '<polic')) {
            $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>' . "\0";
            $connection->send($policy_xml, true);
            $connection->consumeRecvBuffer(\strlen($buffer));
            return 0;
        }
        // Bad websocket handshake request.
        $connection->close("HTTP/1.0 400 Bad Request\r\nServer: workerman\r\n\r\n<div style=\"text-align:center\"><h1>400 Bad Request</h1><hr>workerman</div>", true);
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
        list($http_header, ) = \explode("\r\n\r\n", $buffer, 2);
        $header_data = \explode("\r\n", $http_header);

        if ($_SERVER) {
            $_SERVER = array();
        }

        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = \explode(' ',
            $header_data[0]);

        unset($header_data[0]);
        foreach ($header_data as $content) {
            // \r\n\r\n
            if (empty($content)) {
                continue;
            }
            list($key, $value)       = \explode(':', $content, 2);
            $key                     = \str_replace('-', '_', \strtoupper($key));
            $value                   = \trim($value);
            $_SERVER['HTTP_' . $key] = $value;
            switch ($key) {
                // HTTP_HOST
                case 'HOST':
                    $tmp                    = \explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if (isset($tmp[1])) {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // cookie
                case 'COOKIE':
                    \parse_str(\str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
            }
        }

        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = \parse_url($_SERVER['REQUEST_URI'], \PHP_URL_QUERY);
        if ($_SERVER['QUERY_STRING']) {
            // $GET
            \parse_str($_SERVER['QUERY_STRING'], $_GET);
        } else {
            $_SERVER['QUERY_STRING'] = '';
        }
    }
}
