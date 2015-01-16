<?php 
namespace Protocols;
/**
 * 以回车为请求结束标记的 文本协议 
 * 协议格式 文本+回车
 * 由于是逐字节读取，效率会有些影响，与JsonProtocol相比JsonProtocol效率会高一些
 * @author walkor
 */
class TextProtocol 
{
    /**
     * 判断数据边界
     * @param string $buffer
     * @return number
     */
    public static function check($buffer)
    {
        // 判断最后一个字符是否是回车("\n")
        if($buffer[strlen($buffer)-1] === "\n")
        {
            return 0;
        }
        
        // 说明还有请求数据没收到，但是由于不知道还有多少数据没收到，所以只能返回1，因为有可能下一个字符就是回车（"\n"）
        return 1;
    }

    /**
     * 打包
     * @param mixed $data
     * @return string
     */
    public static function encode($data)
    {
        // 选用json格式化数据
        return $data."\n";
    }

    /**
     * 解包
     * @param string $buffer
     * @return mixed
     */
    public static function decode($buffer)
    {
        return trim($buffer);
    }
}
