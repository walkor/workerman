<?php 
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
/**
 * 二进制协议，接收文件demo
 * @author walkor <worker-man@qq.com>
 */
class FileReceiverDemo extends Man\Core\SocketWorker
{
    /**
     * message_type 到 后缀名的映射
     * @var array
     */
    protected static $fileTypeMap = array(
        1 => 'jpg',
        2 => 'png',
        3 => 'gif',
        4 => 'mp3',
    );
    
    /**
     * 确定包是否完整
     * 二进制协议
     * buffer
     * {
     *     unsigned int message_len;           // 整个包的长度
     *     unsigned char message_type;         // 消息类型 自己定义 如：1图片数据 2声音数据....
     *     char[message_len - 5] message_body; // 这部分是文件原始二进制数据
     * }
     * @see Worker::dealInput()
     */
    public function dealInput($buffer)
    {
        // 已经接收到的数据的长度
        $recv_len = strlen($buffer);
        // unsigned int + unsigned char 共5字节
        $head_len = 5;
        // 数据头没接收全？，继续接收
        if($recv_len < $head_len)
        {
            return $head_len - $recv_len;
        }
        // 根据message_len判断当前数据是否接收完毕
        $message_data = unpack("Nmessage_len/Cmessage_type", $buffer);
        $message_len = $message_data['message_len'];
        $message_type = $message_data['message_type'];
        // 数据没接收完，继续接收$message_len - $recv_len 字节
        if($message_len > $recv_len)
        {
            return $message_len - $recv_len;
        }
        // 数据接收完毕
        return 0;
    }
    
    /**
     * 处理业务
     * @see Worker::dealProcess()
     */
    public function dealProcess($buffer)
    {
        // unsigned int + unsigned char 共5字节
        $head_len = 5;
        // 解包
        $message_data = unpack("Nmessage_len/Cmessage_type", $buffer);
        $message_len = $message_data['message_len'];
        $message_type = $message_data['message_type'];
        // 获得文件二进制数据
        $file_bin_buffer = substr($buffer, $head_len, $message_len - $head_len);
        // 保存数据到/tmp/workerman.recv.xxxxx
        file_put_contents('/tmp/workerman.recv.'.time().'.'.self::getExt($message_type), $file_bin_buffer);
        // 回复客户端 成功
        $response_message_body = "上传成功";
        // 255表示回包
        $response_message_type = 255;
        $this->sendToClient(pack("NC", $head_len+strlen($response_message_body), $response_message_type).$response_message_body);
    }
    
    /**
     * 根据message_type获得文件后缀
     * @param int $message_type
     * @return string  如：jpg gif ...
     */
    protected static function getExt($message_type)
    {
        return isset(self::$fileTypeMap[$message_type]) ? self::$fileTypeMap[$message_type] : 'unknown';
    }
} 
