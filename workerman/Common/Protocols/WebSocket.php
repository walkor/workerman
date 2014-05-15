<?php 
namespace Man\Common\Protocols;

/**
 * WebSocket 协议解包和打包
 * @author walkor <worker-man@qq.com>
 */

class WebSocket
{
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
            return "\x81".char(127).pack("xxxxN", $len).$buffer;
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
    
}
