<?php 
namespace Protocols;
/**
 * Gateway与Worker间通讯的二进制协议
 * 
 * struct GatewayProtocol
 * {
 *     unsigned int        pack_len,
 *     unsigned char     cmd,//命令字
 *     unsigned int        local_ip,
 *     unsigned short    local_port,
 *     unsigned int        socket_id,
 *     unsigned int        client_ip,
 *     unsigned short    client_port,
 *     unsigned int        client_id,
 *     unsigned int        ext_len,
 *     char[ext_len]        ext_data,
 *     char[pack_length-HEAD_LEN] body//包体
 * }
 * 
 * 
 * @author walkor <walkor@workerman.net>
 */

class GatewayProtocol
{
    // 发给worker，gateway有一个新的连接
    const CMD_ON_GATEWAY_CONNECTION = 1;
    
    // 发给worker的，客户端有消息
    const CMD_ON_MESSAGE = 3;
    
    // 发给worker上的关闭链接事件
    const CMD_ON_CLOSE = 4;
    
    // 发给gateway的向单个用户发送数据
    const CMD_SEND_TO_ONE = 5;
    
    // 发给gateway的向所有用户发送数据
    const CMD_SEND_TO_ALL = 6;
    
    // 发给gateway的踢出用户
    const CMD_KICK = 7;
    
    // 发给gateway，通知用户session更改
    const CMD_UPDATE_SESSION = 9;
    
    // 获取在线状态
    const CMD_GET_ONLINE_STATUS = 10;
    
    // 判断是否在线
    const CMD_IS_ONLINE = 11;
    
    /**
     * 包头长度
     * @var integer
     */
    const HEAD_LEN = 29;
     
    /**
     * 协议头
     * @var array
     */
    public $header = array(
        'pack_len'       => self::HEAD_LEN,
        'cmd'              => 0,
        'local_ip'         => '',
        'local_port'     => 0,
        'socket_id'      => 0,
        'client_ip'        => '',
        'client_port'    => 0,
        'client_id'        => 0,
        'ext_len'          => 0,
    );
    
    /**
     * 扩展数据，
     * gateway发往worker时这里存储的是session字符串
     * worker发往gateway时，并且CMD_UPDATE_SESSION时存储的是session字符串
     * worker发往gateway时，并且CMD_SEND_TO_ALL时存储的是接收的client_id序列，可能是空（代表向所有人发）
     * @var string
     */
    public $ext_data = '';
    
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
            $this->ext_data = $data['ext_data'];
            $this->body = $data['body'];
            unset($data['ext_data'], $data['body']);
            $this->header = $data;
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
        // 至少需要四字节才能解出包的长度
        if($len < 4)
        {
            return 4 - $len;
        }
        
        $data = unpack("Npack_len", $buffer);
        return $data['pack_len'] - $len;
    }
    
    /**
     * 获取整个包的buffer
     * @param string $data
     * @return string
     */
    public function getBuffer()
    {
        $this->header['ext_len'] = strlen($this->ext_data);
        $this->header['pack_len'] = self::HEAD_LEN + $this->header['ext_len'] + strlen($this->body);
        return pack("NCNnNNnNN",  $this->header['pack_len'],
                        $this->header['cmd'], ip2long($this->header['local_ip']), 
                        $this->header['local_port'], $this->header['socket_id'], 
                        ip2long($this->header['client_ip']), $this->header['client_port'], 
                        $this->header['client_id'],
                       $this->header['ext_len']) . $this->ext_data . $this->body;
    }
    
    /**
     * 从二进制数据转换为数组
     * @param string $buffer
     * @return array
     */    
    protected static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nsocket_id/Nclient_ip/nclient_port/Nclient_id/Next_len", $buffer);
        $data['local_ip'] = long2ip($data['local_ip']);
        $data['client_ip'] = long2ip($data['client_ip']);
        if($data['ext_len'] > 0)
        {
            $data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
            $data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
        }
        else
        {
            $data['ext_data'] = '';
            $data['body'] = substr($buffer, self::HEAD_LEN);
        }
        return $data;
    }
}



