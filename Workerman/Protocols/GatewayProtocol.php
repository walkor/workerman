<?php 
namespace Workerman\Protocols;
/**
 * Gateway与Worker间通讯的二进制协议
 * 
 * struct GatewayProtocol
 * {
 *     unsigned int        pack_len,
 *     unsigned char     cmd,//命令字
 *     unsigned int        local_ip,
 *     unsigned short    local_port,
 *     unsigned int        client_ip,
 *     unsigned short    client_port,
 *     unsigned int        client_id,
 *     unsigned char      flag,
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
    const CMD_ON_CONNECTION = 1;
    
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
    
    // 包体是标量
    const FLAG_BODY_IS_SCALAR = 0x01;
    
    /**
     * 包头长度
     * @var integer
     */
    const HEAD_LEN = 26;
    
    public static $empty = array(
        'cmd' => 0,
        'local_ip' => '0.0.0.0',
        'local_port' => 0,
        'client_ip' => '0.0.0.0',
        'client_port' => 0,
        'client_id' => 0,
        'flag' => 0,
        'ext_data' => '',
        'body' => '',
    );
     
    /**
     * 返回包长度
     * @param string $buffer
     * @return int return current package length
     */
    public static function input($buffer)
    {
        if(strlen($buffer) < self::HEAD_LEN)
        {
            return 0;
        }
        
        $data = unpack("Npack_len", $buffer);
        return $data['pack_len'];
    }
    
    /**
     * 获取整个包的buffer
     * @param array $data
     * @return string
     */
    public static function encode($data)
    {
        $flag = (int)is_scalar($data['body']);
        if(!$flag)
        {
            $data['body'] = serialize($data['body']);
        }
        $ext_len = strlen($data['ext_data']);
        $package_len = self::HEAD_LEN + $ext_len + strlen($data['body']);
        return pack("NCNnNnNNC",  $package_len,
                        $data['cmd'], ip2long($data['local_ip']), 
                        $data['local_port'], ip2long($data['client_ip']), 
                        $data['client_port'], $data['client_id'],
                       $ext_len, $flag) . $data['ext_data'] . $data['body'];
    }
    
    /**
     * 从二进制数据转换为数组
     * @param string $buffer
     * @return array
     */    
    public static function decode($buffer)
    {
        $data = unpack("Npack_len/Ccmd/Nlocal_ip/nlocal_port/Nclient_ip/nclient_port/Nclient_id/Next_len/Cflag", $buffer);
        $data['local_ip'] = long2ip($data['local_ip']);
        $data['client_ip'] = long2ip($data['client_ip']);
        if($data['ext_len'] > 0)
        {
            $data['ext_data'] = substr($buffer, self::HEAD_LEN, $data['ext_len']);
            if($data['flag'] & self::FLAG_BODY_IS_SCALAR)
            {
                $data['body'] = substr($buffer, self::HEAD_LEN + $data['ext_len']);
            }
            else
            {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN + $data['ext_len']));
            }
        }
        else
        {
            $data['ext_data'] = '';
            if($data['flag'] & self::FLAG_BODY_IS_SCALAR)
            {
                $data['body'] = substr($buffer, self::HEAD_LEN);
            }
            else
            {
                $data['body'] = unserialize(substr($buffer, self::HEAD_LEN));
            }
        }
        return $data;
    }
}



