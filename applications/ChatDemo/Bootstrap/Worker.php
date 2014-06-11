<?php
/**
 * 
 * 处理具体逻辑
 * 
 * @author walkor <worker-man@qq.com>
 * 
 */
define('ROOT_DIR', realpath(__DIR__.'/../'));
require_once ROOT_DIR . '/Protocols/GatewayProtocol.php';
require_once ROOT_DIR . '/Event.php';

class Worker extends Man\Core\SocketWorker
{
    public function dealInput($recv_str)
    {
        return GatewayProtocol::input($recv_str); 
    }

    public function dealProcess($recv_str)
    {
        $pack = new GatewayProtocol($recv_str);
        Context::$client_ip = $pack->header['client_ip'];
        Context::$client_port = $pack->header['client_port'];
        Context::$local_ip = $pack->header['local_ip'];
        Context::$local_port = $pack->header['local_port'];
        Context::$socket_id = $pack->header['socket_id'];
        Context::$uid = $pack->header['uid'];
        switch($pack->header['cmd'])
        {
            case GatewayProtocol::CMD_ON_CONNECTION:
                $ret = call_user_func_array(array('Event', 'onConnect'), array($pack->body));
                break;
            case GatewayProtocol::CMD_ON_MESSAGE:
                $ret = call_user_func_array(array('Event', 'onMessage'), array(Context::$uid, $pack->body));
                break;
            case GatewayProtocol::CMD_ON_CLOSE:
                $ret = call_user_func_array(array('Event', 'onClose'), array(Context::$uid));
                break;
        }
        Context::clear();
        return $ret;
    }
}

/**
 * 上下文 包含当前用户uid， 内部通信local_ip local_port socket_id ，以及客户端client_ip client_port
 * @author walkor
 *
 */
class Context
{
    public static $series_id;
    public static $local_ip;
    public static $local_port;
    public static $socket_id;
    public static $client_ip;
    public static $client_port;
    public static $uid;
    public static function clear()
    {
        self::$series_id = self::$local_ip = self::$local_port = self::$socket_id = self::$client_ip = self::$client_port = self::$uid = null;
    }
}
