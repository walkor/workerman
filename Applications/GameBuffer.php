<?php
/**
 * 
 * 命令字相关
* @author walkor <worker-man@qq.com>
* 
 */
require_once WORKERMAN_ROOT_DIR . 'Protocols/Buffer.php';
require_once WORKERMAN_ROOT_DIR . 'Applications/Event.php';

class GameBuffer extends WORKERMAN\Protocols\Buffer
{
    // 系统命令
    const CMD_SYSTEM = 128;
    // 连接事件 
    const SCMD_ON_CONNECT = 1;
    // 关闭事件
    const SCMD_ON_CLOSE = 2;
    
    // 发送给网关的命令
    const CMD_GATEWAY = 129;
    // 给用户发送数据包
    const SCMD_SEND_DATA = 1;
    // 根据uid踢人
    const SCMD_KICK_UID = 2;
    // 根据地址和socket编号踢人
    const SCMD_KICK_ADDRESS = 3;
    // 广播内容
    const SCMD_BROADCAST = 4;
    // 通知连接成功
    const SCMD_CONNECT_SUCCESS = 5;
    
    // 用户中心
    const CMD_USER = 1;
    // 登录
    const SCMD_LOGIN = 1 ;
    // 获取用户内部通信网关
    const SCMD_GET_GATEWAY = 4;
    
    // 普通业务处理
    const CMD_MAP  = 2;
   
    public static $cmdMap = array(
            self::CMD_USER  => 'User',
            self::CMD_MAP => 'Map',
            self::CMD_GATEWAY => 'GateWay',
            self::CMD_Event => 'Event',
     );
    
    public static $scmdMap = array(
            self::SCMD_BROADCAST     => 'broadcast',
            self::SCMD_GET_GATEWAY => 'getGateway',
            self::SCMD_LOGIN                => 'login',
            self::SCMD_ON_CONNECT   =>'onConnect',
            self::SCMD_ON_CLOSE         => 'onClose',
     );
    
    public static function sendToGateway($address, $bin_data, $to_uid = 0, $from_uid = 0)
    {
        $client = stream_socket_client($address);
        $len = stream_socket_sendto($client, $bin_data);
        return $len == strlen($bin_data);
    }
    
    public static function sendToUid($uid, $buffer)
    {
        $address = Event::getAddressByUid($uid);
        if($address)
        {
            return self::sendToGateway($address, $buffer);
        }
        return false;
    }
    
    public static function sendToAll($buffer)
    {
        $data = GameBuffer::decode($buffer);
        $all_addresses = Store::get('GLOBAL_GATEWAY_ADDRESS');
        foreach($all_addresses as $address)
        {
            self::sendToGateway($address, $buffer);
        }
    }
}
