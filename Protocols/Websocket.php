<?php 
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Protocols;

use Workerman\Connection\ConnectionInterface;

/**
 * WebSocket 协议服务端解包和打包
 */
class Websocket implements \Workerman\Protocols\ProtocolInterface
{
    /**
     * websocket头部最小长度
     * @var int
     */
    const MIN_HEAD_LEN = 6;
    
    /**
     * websocket blob类型
     * @var char
     */
    const BINARY_TYPE_BLOB = "\x81";

    /**
     * websocket arraybuffer类型
     * @var char
     */
    const BINARY_TYPE_ARRAYBUFFER = "\x82";
    
    /**
     * 检查包的完整性
     * @param string $buffer
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        // 数据长度
        $recv_len = strlen($buffer);
        // 长度不够
        if($recv_len < self::MIN_HEAD_LEN)
        {
            return 0;
        }
        
        // 还没有握手
        if(empty($connection->websocketHandshake))
        {
            return self::dealHandshake($buffer, $connection);
        }
        
        // $connection->websocketCurrentFrameLength有值说明当前fin为0，则缓冲websocket帧数据
        if($connection->websocketCurrentFrameLength)
        {
            // 如果当前帧数据未收全，则继续收
            if($connection->websocketCurrentFrameLength > $recv_len)
            {
                // 返回0，因为不清楚完整的数据包长度，需要等待fin=1的帧
                return 0;
            }
        }
        else 
        {
            $data_len = ord($buffer[1]) & 127;
            $firstbyte = ord($buffer[0]);
            $is_fin_frame = $firstbyte>>7;
            $opcode = $firstbyte & 0xf;
            switch($opcode)
            {
                // 附加数据帧 @todo 实现附加数据帧
                case 0x0:
                    break;
                // 文本数据帧
                case 0x1:
                    break;
                // 二进制数据帧
                case 0x2:
                    break;
                // 关闭的包
                case 0x8:
                    // 如果有设置onWebSocketClose回调，尝试执行
                    if(isset($connection->onWebSocketClose))
                    {
                        call_user_func($connection->onWebSocketClose, $connection);
                    }
                    // 默认行为是关闭连接
                    else
                    {
                        $connection->close();
                    }
                    return 0;
                // ping的包
                case 0x9:
                    // 如果有设置onWebSocketPing回调，尝试执行
                    if(isset($connection->onWebSocketPing))
                    {
                        call_user_func($connection->onWebSocketPing, $connection);
                    }
                    // 默认发送pong
                    else 
                    {
                        $connection->send(pack('H*', '8a00'), true);
                    }
                    // 从接受缓冲区中消费掉该数据包
                    if(!$data_len)
                    {
                        $connection->consumeRecvBuffer(self::MIN_HEAD_LEN);
                        return 0;
                    }
                    break;
                // pong的包
                case 0xa:
                    // 如果有设置onWebSocketPong回调，尝试执行
                    if(isset($connection->onWebSocketPong))
                    {
                        call_user_func($connection->onWebSocketPong, $connection);
                    }
                    // 从接受缓冲区中消费掉该数据包
                    if(!$data_len)
                    {
                        $connection->consumeRecvBuffer(self::MIN_HEAD_LEN);
                        return 0;
                    }
                    break;
                // 错误的opcode 
                default :
                    echo "error opcode $opcode and close websocket connection\n";
                    $connection->close();
                    return 0;
            }
            
            // websocket二进制数据
            $head_len = self::MIN_HEAD_LEN;
            if ($data_len === 126) {
                $head_len = 8;
                if($head_len > $recv_len)
                {
                    return 0;
                }
                $pack = unpack('ntotal_len', substr($buffer, 2, 2));
                $data_len = $pack['total_len'];
            } else if ($data_len === 127) {
                $head_len = 14;
                if($head_len > $recv_len)
                {
                    return 0;
                }
                $arr = unpack('N2', substr($buffer, 2, 8));
                $data_len = $arr[1]*4294967296 + $arr[2];
            }
            $current_frame_length = $head_len + $data_len;
            if($is_fin_frame)
            {
                return $current_frame_length;
            }
            else
            {
                $connection->websocketCurrentFrameLength = $current_frame_length;
            }
        }
        
        // 收到的数据刚好是一个frame
        if($connection->websocketCurrentFrameLength == $recv_len)
        {
            self::decode($buffer, $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $connection->websocketCurrentFrameLength = 0;
            return 0;
        }
        // 收到的数据大于一个frame
        elseif($connection->websocketCurrentFrameLength < $recv_len)
        {
            self::decode(substr($buffer, 0, $connection->websocketCurrentFrameLength), $connection);
            $connection->consumeRecvBuffer($connection->websocketCurrentFrameLength);
            $current_frame_length = $connection->websocketCurrentFrameLength;
            $connection->websocketCurrentFrameLength = 0;
            // 继续读取下一个frame
            return self::input(substr($buffer, $current_frame_length), $connection);
        }
        // 收到的数据不足一个frame
        else
        {
            return 0;
        }
    }
    
    /**
     * 打包
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer, ConnectionInterface $connection)
    {
        $len = strlen($buffer);
        if(empty($connection->websocketHandshake))
        {
            // 默认是utf8文本格式
            $connection->websocketType = self::BINARY_TYPE_BLOB;
        }
        
        $first_byte = $connection->websocketType;
        
        if($len<=125)
        {
            $encode_buffer = $first_byte.chr($len).$buffer;
        }
        else if($len<=65535)
        {
            $encode_buffer = $first_byte.chr(126).pack("n", $len).$buffer;
        }
        else
        {
            $encode_buffer = $first_byte.chr(127).pack("xxxxN", $len).$buffer;
        }
        
        // 还没握手不能发数据，先将数据缓冲起来，等握手完毕后发送
        if(empty($connection->websocketHandshake))
        {
            if(empty($connection->tmpWebsocketData))
            {
                // 临时数据缓冲
                $connection->tmpWebsocketData = '';
            }
            $connection->tmpWebsocketData .= $encode_buffer;
            // 返回空，阻止发送
            return '';
        }
        
        return $encode_buffer;
    }
    
    /**
     * 解包
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer, ConnectionInterface $connection)
    {
        $len = $masks = $data = $decoded = null;
        $len = ord($buffer[1]) & 127;
        if ($len === 126) {
            $masks = substr($buffer, 4, 4);
            $data = substr($buffer, 8);
        } else if ($len === 127) {
            $masks = substr($buffer, 10, 4);
            $data = substr($buffer, 14);
        } else {
            $masks = substr($buffer, 2, 4);
            $data = substr($buffer, 6);
        }
        for ($index = 0; $index < strlen($data); $index++) {
            $decoded .= $data[$index] ^ $masks[$index % 4];
        }
        if($connection->websocketCurrentFrameLength)
        {
            $connection->websocketDataBuffer .= $decoded;
            return $connection->websocketDataBuffer;
        }
        else
        {
            $decoded = $connection->websocketDataBuffer . $decoded;
            $connection->websocketDataBuffer = '';
            return $decoded;
        }
    }
    
    /**
     * 处理websocket握手
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    protected static function dealHandshake($buffer, $connection)
    {
        // 握手阶段客户端发送HTTP协议
        if(0 === strpos($buffer, 'GET'))
        {
            // 判断\r\n\r\n边界
            $heder_end_pos = strpos($buffer, "\r\n\r\n");
            if(!$heder_end_pos)
            {
                return 0;
            }
            
            // 解析Sec-WebSocket-Key
            $Sec_WebSocket_Key = '';
            if(preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/i", $buffer, $match))
            {
                $Sec_WebSocket_Key = $match[1];
            }
            else
            {
                $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n<b>400 Bad Request</b><br>Sec-WebSocket-Key not found.<br>This is a WebSocket service and can not be accessed via HTTP.", true);
                $connection->close();
                return 0;
            }
            // 握手的key
            $new_key = base64_encode(sha1($Sec_WebSocket_Key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
            // 握手返回的数据
            $handshake_message = "HTTP/1.1 101 Switching Protocols\r\n";
            $handshake_message .= "Upgrade: websocket\r\n";
            $handshake_message .= "Sec-WebSocket-Version: 13\r\n";
            $handshake_message .= "Connection: Upgrade\r\n";
            $handshake_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
            // 标记已经握手
            $connection->websocketHandshake = true;
            // 缓冲fin为0的包，直到fin为1
            $connection->websocketDataBuffer = '';
            // 当前数据帧的长度，可能是fin为0的帧，也可能是fin为1的帧
            $connection->websocketCurrentFrameLength = 0;
            // 当前帧的数据缓冲
            $connection->websocketCurrentFrameBuffer = '';
            // 消费掉握手数据，不触发onMessage
            $connection->consumeRecvBuffer(strlen($buffer));
            // 发送握手数据
            $connection->send($handshake_message, true);
            
            // 握手后有数据要发送
            if(!empty($connection->tmpWebsocketData))
            {
                $connection->send($connection->tmpWebsocketData, true);
                $connection->tmpWebsocketData = '';
            }
            // blob or arraybuffer
            $connection->websocketType = self::BINARY_TYPE_BLOB; 
            // 如果有设置onWebSocketConnect回调，尝试执行
            if(isset($connection->onWebSocketConnect))
            {
                self::parseHttpHeader($buffer);
                try
                {
                    call_user_func($connection->onWebSocketConnect, $connection, $buffer);
                }
                catch(\Exception $e)
                {
                    echo $e;
                }
                $_GET = $_COOKIE = $_SERVER = array();
            }
            return 0;
        }
        // 如果是flash的policy-file-request
        elseif(0 === strpos($buffer,'<polic'))
        {
            $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>'."\0";
            $connection->send($policy_xml, true);
            $connection->consumeRecvBuffer(strlen($buffer));
            return 0;
        }
        // 出错
        $connection->send("HTTP/1.1 400 Bad Request\r\n\r\n<b>400 Bad Request</b><br>Invalid handshake data for websocket. ", true);
        $connection->close();
        return 0;
    }
    
    /**
     * 从header中获取
     * @param string $buffer
     * @return void
     */
    protected static function parseHttpHeader($buffer)
    {
        $header_data = explode("\r\n", $buffer);
        $_SERVER = array();
        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header_data[0]);
        unset($header_data[0]);
        foreach($header_data as $content)
        {
            // \r\n\r\n
            if(empty($content))
            {
                continue;
            }
            list($key, $value) = explode(':', $content, 2);
            $key = strtolower($key);
            $value = trim($value);
            switch($key)
            {
                // HTTP_HOST
                case 'host':
                    $_SERVER['HTTP_HOST'] = $value;
                    $tmp = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if(isset($tmp[1]))
                    {
                        $_SERVER['SERVER_PORT'] = $tmp[1];
                    }
                    break;
                // HTTP_COOKIE
                case 'cookie':
                    $_SERVER['HTTP_COOKIE'] = $value;
                    parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    break;
                // HTTP_USER_AGENT
                case 'user-agent':
                    $_SERVER['HTTP_USER_AGENT'] = $value;
                    break;
                // HTTP_REFERER
                case 'referer':
                    $_SERVER['HTTP_REFERER'] = $value;
                    break;
                case 'origin':
                    $_SERVER['HTTP_ORIGIN'] = $value;
                    break;
            }
        }
        
        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        if($_SERVER['QUERY_STRING'])
        {
            // $GET
            parse_str($_SERVER['QUERY_STRING'], $_GET);
        }
        else
        {
            $_SERVER['QUERY_STRING'] = '';
        }
    }
}
