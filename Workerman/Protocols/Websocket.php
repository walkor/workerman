<?php 
namespace Workerman\Protocols;
/**
 * WebSocket 协议服务端解包和打包
 * @author walkor <walkor@workerman.net>
 */

use Workerman\Connection\ConnectionInterface;

class Websocket implements \Workerman\Protocols\ProtocolInterface
{
    /**
     * 检查包的完整性
     * @param string $buffer
     */
    public static function input($buffer, ConnectionInterface $connection)
    {
        // 数据长度
        $recv_len = strlen($buffer);
        // 长度不够
        if($recv_len < 6)
        {
            return 0;
        }
        
        // 还没有握手
        if(empty($connection->handshake))
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
                if(preg_match("/Sec-WebSocket-Key: *(.*?)\r\n/", $buffer, $match))
                {
                    $Sec_WebSocket_Key = $match[1];
                }
                $new_key = base64_encode(sha1($Sec_WebSocket_Key."258EAFA5-E914-47DA-95CA-C5AB0DC85B11",true));
                // 握手返回的数据
                $new_message = "HTTP/1.1 101 Switching Protocols\r\n";
                $new_message .= "Upgrade: websocket\r\n";
                $new_message .= "Sec-WebSocket-Version: 13\r\n";
                $new_message .= "Connection: Upgrade\r\n";
                $new_message .= "Sec-WebSocket-Accept: " . $new_key . "\r\n\r\n";
                $connection->handshake = true;
                $connection->consumeRecvBuffer(strlen($buffer));
                $connection->send($new_message, true);
                return 0;
            }
            // 如果是flash的policy-file-request
            elseif(0 === strpos($buffer,'<polic'))
            {
                if('>' != $buffer[strlen($buffer) - 1])
                {
                    return 0;
                }
                $policy_xml = '<?xml version="1.0"?><cross-domain-policy><site-control permitted-cross-domain-policies="all"/><allow-access-from domain="*" to-ports="*"/></cross-domain-policy>'."\0";
                $connection->send($policy_xml, true);
                $connection->consumeRecvBuffer(strlen($buffer));
                return 0;
            }
            // error
            $connection->close();
            return 0;
        }
        
        // close package
        if(ord($buffer[0]) & 0xf == 8)
        {
            $connection->close();
            return 0;
        }
        
        // websocket二进制数据
        $data_len = ord($buffer[1]) & 127;
        $head_len = 6;
        if ($data_len === 126) {
            $pack = unpack('ntotal_len', substr($buffer, 2, 2));
            $data_len = $pack['total_len'];
            $head_len = 8;
        } else if ($data_len === 127) {
            $arr = unpack('N2', substr($buffer, 2, 8));
            $data_len = $arr[1]*4294967296 + $arr[2];
            $head_len = 14;
        }
        return $head_len + $data_len;
    }
    
    /**
     * 打包
     * @param string $buffer
     */
    public static function encode($buffer, ConnectionInterface $connection)
    {
        $len = strlen($buffer);
        if($len<=125)
        {
            return "\x81".chr($len).$buffer;
        }
        else if($len<=65535)
        {
            return "\x81".chr(126).pack("n", $len).$buffer;
        }
        else
        {
            return "\x81".chr(127).pack("xxxxN", $len).$buffer;
        }
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
        return $decoded;
    }
}
