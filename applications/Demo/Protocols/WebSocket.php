<?php 
namespace Protocols;
/**
 * WebSocket 协议解包和打包
 * @author walkor <walkor@workerman.net>
 */

class WebSocket
{
    /**
     * 检查包的完整性
     * @param unknown_type $buffer
     */
    public static function check($buffer)
    {
        // 数据长度
        $recv_len = strlen($buffer);
        // 长度不够
        if($recv_len < 6)
        {
            return 6-$recv_len;
        }
        
        // 握手阶段客户端发送HTTP协议
        if(0 === strpos($buffer, 'GET'))
        {
            // 判断\r\n\r\n边界
            if(strlen($buffer) - 4 === strpos($buffer, "\r\n\r\n"))
            {
                return 0;
            }
            return 1;
        }
        // 如果是flash的policy-file-request
        elseif(0 === strpos($buffer,'<polic'))
        {
            if('>' != $buffer[strlen($buffer) - 1])
            {
                return 1;
            }
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
        $remain_len = $head_len + $data_len - $recv_len;
        if($remain_len < 0)
        {
            return false;
        }
        return $remain_len;
    }
    
    /**
     * 打包
     * @param string $buffer
     */
    public static function encode($buffer)
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
    public static function decode($buffer)
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
    
    /**
     * 是否是websocket断开的数据包
     * @param string $buffer
     */
    public static function isClosePacket($buffer)
    {
        $opcode = self::getOpcode($buffer);
        return $opcode == 8;
    }
    
    /**
     * 是否是websocket ping的数据包
     * @param string $buffer
     */
    public static function isPingPacket($buffer)
    {
        $opcode = self::getOpcode($buffer);
        return $opcode == 9;
    }
    
    /**
     * 是否是websocket pong的数据包
     * @param string $buffer
     */
    public static function isPongPacket($buffer)
    {
        $opcode = self::getOpcode($buffer);
        return $opcode == 0xa;
    }
    
    /**
     * 获取wbsocket opcode
     * @param string $buffer
     */
    public static function getOpcode($buffer)
    {
        return ord($buffer[0]) & 0xf;
    }
}
