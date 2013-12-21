<?php 

/**
 * RPC 协议解析 相关
 * 协议格式为 [json字符串\n]
 * @author walkor <worker-man@qq.com>
 * */
class RpcProtocol
{
   /**
    * 从socket缓冲区中预读长度
    * @var integer
    */
    const PRREAD_LENGTH = 87380;
    
    /**
     * 判断数据包是否接收完整
     * @param string $bin_data
     * @param mixed $data
     * @return integer 0代表接收完毕，大于0代表还要接收数据
     */
    public static function dealInput($bin_data)
    {
        $bin_data_length = strlen($bin_data);
        // 判断最后一个字符是否为\n，\n代表一个数据包的结束
        if($bin_data[$bin_data_length-1] !="\n")
        {
            // 再读
            return self::PRREAD_LENGTH;
        }
        return 0;
    }
    
    /**
     * 将数据打包成Rpc协议数据
     * @param mixed $data
     * @return string
     */
    public static function encode($data)
    {
        return json_encode($data)."\n";
    }
    
   /**
    * 解析Rpc协议数据
    * @param string $bin_data
    * @return mixed
    */
    public static function decode($bin_data)
    {
        return json_decode(trim($bin_data), true);
    }
    
}