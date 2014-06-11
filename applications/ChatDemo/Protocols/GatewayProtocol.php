<?php 
/**
 * 二进制协议
 * 
 * struct GatewayProtocol
 * {
 *     unsigned short    series_id,//序列号 udp协议使用
 *     unsigned char     cmd,//命令字
 *     unsigned int      local_ip,
 *     unsigned short    local_port,
 *     unsigned int      socket_id,
 *     unsigned int      client_ip,
 *     unsigned short    client_port,
 *     unsigned int      pack_len,
 *     unsigned int      uid,
 *     char[pack_length-HEAD_LEN] body//包体
 * }
 * 
 * 
 * @author walkor <worker-man@qq.com>
 */

class GatewayProtocol
{
    // 发给worker上的链接事件
    const CMD_ON_CONNECTION = 1;
    
    // 发给worker上的有消息可读事件
    const CMD_ON_MESSAGE = 2;
    
    // 发给worker上的关闭链接事件
    const CMD_ON_CLOSE = 3;
    
    // 发给gateway的向单个用户发送数据
    const CMD_SEND_TO_ONE = 4;
    
    // 发给gateway的向所有用户发送数据
    const CMD_SEND_TO_ALL = 5;
    
    // 发给gateway的踢出用户
    const CMD_KICK = 6;
    
    // 发给gateway的通知用户（通过验证）链接成功
    const CMD_CONNECT_SUCCESS = 7;
    
    /**
     * 包头长度
     * @var integer
     */
    const HEAD_LEN = 27;
     
    /**
     * 序列号，防止串包
     * @var integer
     */
    protected static $seriesId = 0;
    
    /**
     * 协议头
     * @var array
     */
    public $header = array(
        'cmd'            => 0,
        'series_id'      => 0,
        'local_ip'       => '',
        'local_port'     => 0,
        'socket_id'      => 0,
        'client_ip'      => '',
        'client_port'    => 0,
        'uid'            => 0,
        'pack_len'       => self::HEAD_LEN,
    );
    
    /**
     * 包体
     * @var string
     */
    public $body = '';
    
    /**
     * 初始化
     * @return void
     */
    public function __construct($buffer = null)
    {
        if($buffer)
        {
            $data = self::decode($buffer);
            $this->body = $data['body'];
            unset($data['body']);
            $this->header = $data;
        }
        else
        {
            if(self::$seriesId>=65535)
            {
                self::$seriesId = 0;
            }
            else
            {
                $this->header['series_id'] = self::$seriesId++;
            }
        }
    }
    
    /**
     * 判断数据包是否都到了
     * @param string $buffer
     * @return int int=0数据是完整的 int>0数据不完整，还要继续接收int字节
     */
    public static function input($buffer)
    {
        $len = strlen($buffer);
        if($len < self::HEAD_LEN)
        {
            return self::HEAD_LEN - $len;
        }
        
        $data = unpack("nseries_id/Ccmd/Nlocal_ip/nlocal_port/Nsocket_id/Nclient_ip/nclient_port/Nuid/Npack_len", $buffer);
        if($data['pack_len'] > $len)
        {
            return $data['pack_len'] - $len;
        }
        return 0;
    }
    
    
    /**
     * 设置包体
     * @param string $body_str
     * @return void
     */
    public function setBody($body_str)
    {
        $this->body = (string) $body_str;
    }
    
    /**
     * 获取整个包的buffer
     * @param string $data
     * @return string
     */
    public function getBuffer()
    {
        $this->header['pack_len'] = self::HEAD_LEN + strlen($this->body);
        return pack("nCNnNNnNN", $this->header['series_id'], 
                        $this->header['cmd'], ip2long($this->header['local_ip']), 
                        $this->header['local_port'], $this->header['socket_id'], 
                        ip2long($this->header['client_ip']), $this->header['client_port'], 
                        $this->header['uid'], $this->header['pack_len']).$this->body;
    }
    
    /**
     * 从二进制数据转换为数组
     * @param string $buffer
     * @return array
     */    
    protected static function decode($buffer)
    {
        $data = unpack("nseries_id/Ccmd/Nlocal_ip/nlocal_port/Nsocket_id/Nclient_ip/nclient_port/Nuid/Npack_len", $buffer);
        $data['body'] = '';
        $data['local_ip'] = long2ip($data['local_ip']);
        $data['client_ip'] = long2ip($data['client_ip']);
        $body_len = $data['pack_len'] - self::HEAD_LEN;
        if($body_len > 0)
        {
            $data['body'] = substr($buffer, self::HEAD_LEN, $body_len);
        }
        return $data;
    }
    
}



