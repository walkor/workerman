<?php
namespace Workerman\Protocols;

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
    public static function input($buffer, $connection)
    {
        if (empty($connection->handshakeStep)) {
            echo "recv data before handshake\n";
            return false;
        }
        // Recv handshake response
        if ($connection->handshakeStep === 1) {
            $pos = strpos($buffer, "\r\n\r\n");
            if ($pos) {
                // handshake complete
                $connection->handshakeStep = 2;
                $handshake_respnse_length = $pos + 4;
                // Try to emit onWebSocketConnect callback.
                if (isset($connection->onWebSocketConnect)) {
                    try {
                        call_user_func($connection->onWebSocketConnect, $connection, substr($buffer, 0, $handshake_respnse_length));
                    } catch (\Exception $e) {
                        echo $e;
                        exit(250);
                    }
                }
                // Headbeat.
                if (!empty($connection->websocketPingInterval)) {
                    $connection->websocketPingTimer = \Workerman\Lib\Timer::add($connection->websocketPingInterval, function() use ($connection){
                        if (false === $connection->send(pack('H*', '8900'), true)) {
                            \Workerman\Lib\Timer::del($connection->websocketPingTimer);
                        }
                    });
                }

                $connection->consumeRecvBuffer($handshake_respnse_length);
                if (!empty($connection->tmpWebsocketData)) {
                    $connection->send($connection->tmpWebsocketData, true);
                }
                if (strlen($buffer > $handshake_respnse_length)) {
                    return self::input(substr($buffer, $handshake_respnse_length));
                }
            }
            return 0;
        }
        if (strlen($buffer) < 2) {
            return 0;
        }
        $opcode = ord($buffer[0]) & 0xf;
        $data_len = ord($buffer[1]) & 127;
        switch ($opcode) {
            // Continue.
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
                        call_user_func($connection->onWebSocketClose, $connection);
                    } catch (\Exception $e) {
                        echo $e;
                        exit(250);
                    }
                } else {
                    // Close connection.
                    $connection->close();
                }
                return 0;
            // Ping package.
            case 0x9:
                // Try to emit onWebSocketPing callback.
                if (isset($connection->onWebSocketPing)) {
                    try {
                        call_user_func($connection->onWebSocketPing, $connection);
                    } catch (\Exception $e) {
                        echo $e;
                        exit(250);
                    }
                } else {
                    // Send pong package to remote.
                    $connection->send(pack('H*', '8a00'), true);
                }
                // Consume data from receive buffer.
                if (!$data_len) {
                    $connection->consumeRecvBuffer(2);
                    return 0;
                }
                break;
            // Pong package.
            case 0xa:
                // Try to emit onWebSocketPong callback.
                if (isset($connection->onWebSocketPong)) {
                    try {
                        call_user_func($connection->onWebSocketPong, $connection);
                    } catch (\Exception $e) {
                        echo $e;
                        exit(250);
                    }
                }
                //  Consume data from receive buffer.
                if (!$data_len) {
                    $connection->consumeRecvBuffer(2);
                    return 0;
                }
                break;
        }

        if ($data_len === 126) {
            if (strlen($buffer) < 6) {
                return 0;
            }
            $pack = unpack('nn/ntotal_len', $buffer);
            $data_len = $pack['total_len'] + 4;
        } else if ($data_len === 127) {
            if (strlen($buffer) < 10) {
                return 0;
            }
            $arr = unpack('n/N2c', $buffer);
            $data_len = $arr['c1']*4294967296 + $arr['c2'] + 10;
        } else {
            $data_len += 2;
        }
        return $data_len;
    }

    /**
     * Websocket encode.
     *
     * @param string              $buffer
     * @param ConnectionInterface $connection
     * @return string
     */
    public static function encode($payload, $connection)
    {
        if (empty($connection->handshakeStep)) {
            // Get Host.
            $port = $connection->getRemotePort();
            $host = $port === 80 ? $connection->getRemoteHost() : $connection->getRemoteHost() . ':' . $port;
            // Handshake header.
            $header = "GET / HTTP/1.1\r\n".
            "Host: $host\r\n".
            "Connection: Upgrade\r\n".
            "Upgrade: websocket\r\n".
            "Origin: ". (isset($connection->websocketOrigin) ? $connection->websocketOrigin : '*') ."\r\n".
            "Sec-WebSocket-Version: 13\r\n".
            "Sec-WebSocket-Key: ".base64_encode(sha1(uniqid(mt_rand(), true), true))."\r\n\r\n";
            $connection->send($header, true);
            $connection->handshakeStep = 1;
            if (empty($connection->websocketType)) {
                $connection->websocketType = self::BINARY_TYPE_BLOB;
            }
        }
        $mask = 1;
        $mask_key = "\x00\x00\x00\x00";

        $pack = '';
        $length = $length_flag = strlen($payload);
        if (65535 < $length) {
            $pack   = pack('NN', ($length & 0xFFFFFFFF00000000) >> 0b100000, $length & 0x00000000FFFFFFFF);
            $length_flag = 127;
        } else if (125 < $length) {
            $pack   = pack('n*', $length);
            $length_flag = 126;
        }

        $head = ($mask << 7) | $length_flag;
        $head = $connection->websocketType . chr($head) . $pack;

        $frame = $head . $mask_key;
        // append payload to frame:
        for ($i = 0; $i < $length; $i++) {
            $frame .= $payload[$i] ^ $mask_key[$i % 4];
        }
        if ($connection->handshakeStep === 1) {
            $connection->tmpWebsocketData = isset($connection->tmpWebsocketData) ? $connection->tmpWebsocketData . $frame : $frame;
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
    public static function decode($bytes, $connection)
    {
        $masked = $bytes[1] >> 7;
        $data_length = $masked ? ord($bytes[1]) & 127 : ord($bytes[1]);
        $decoded_data = '';
        if ($masked === true) {
            if ($data_length === 126) {
                $mask = substr($bytes, 4, 4);
                $coded_data = substr($bytes, 8);
            } else if ($data_length === 127) {
                $mask = substr($bytes, 10, 4);
                $coded_data = substr($bytes, 14);
            } else {
                $mask = substr($bytes, 2, 4);
                $coded_data = substr($bytes, 6);
            }
            for ($i = 0; $i < strlen($coded_data); $i++) {
                $decoded_data .= $coded_data[$i] ^ $mask[$i % 4];
            }
        } else {
            if ($data_length === 126) {
                $decoded_data = substr($bytes, 4);
            } else if ($data_length === 127) {
                $decoded_data = substr($bytes, 10);
            } else {
                $decoded_data = substr($bytes, 2);
            }
        }
        return $decoded_data;
    }

}
