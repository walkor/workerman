<?php 
namespace Protocols;
/**
 * 以四字节int标记请求长度的json协议 
 * 协议格式int+json
 * @author walkor
 */
class JsonProtocol
{
    // 根据首部四个字节（int）判断数据是否接收完毕
    public static function check($buffer)
    {
        // 已经收到的长度（字节）
        $recv_length = strlen($buffer);
        // 接收到的数据长度不够？
        if($recv_length<4)
        {
            return 4 - $recv_length;
        }
        // 读取首部4个字节，网络字节序int
        $buffer_data = unpack('Ntotal_length', $buffer);
        // 得到这次数据的整体长度（字节）
        $total_length = $buffer_data['total_length'];
        if($total_length>$recv_length)
        {
            // 还有这么多字节要接收
            return $total_length - $recv_length;
        }
        // 接收完毕
        return 0;
    }

    // 打包
    public static function encode($data)
    {
        // 选用json格式化数据
        $buffer = json_encode($data);
        // 包的整体长度为json长度加首部四个字节(首部数据包长度存储占用空间)
        $total_length = 4 + strlen($buffer);
        return pack('N', $total_length) . $buffer;
    }

    // 解包
    public static function decode($buffer)
    {
        $buffer_data = unpack('Ntotal_length', $buffer);
        // 得到这次数据的整体长度（字节）
        $total_length = $buffer_data['total_length'];
        // json的数据
        $json_string = substr($buffer, 4);
        return json_decode($json_string, true);
    }
}
