<?php
/**
 * 
 * 数据发送相关
 * sendToAll sendToUid
 * @author walkor <workerman.net>
 * 
 */

if(!defined('ROOT_DIR'))
{
    define('ROOT_DIR', __DIR__."/../");
}

require_once ROOT_DIR . '/Lib/Store.php';
require_once ROOT_DIR . '/Lib/Context.php';
require_once ROOT_DIR . '/Lib/APLog.php';
require_once ROOT_DIR . '/Protocols/GatewayProtocol.php';

class GateWay
{
    
    /**
     * gateway实例
     * @var object
     */
    protected static  $businessWorker = null;
    
    /**
     * 设置gateway实例，用于与
     * @param unknown_type $gateway_instance
     */
    public static function setBusinessWorker($business_worker_instance)
    {
        self::$businessWorker = $business_worker_instance;
    }
    
   /**
    * 向所有客户端广播消息
    * @param string $message
    */
   public static function sendToAll($message)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $pack->header['series_id'] = 0;
       $pack->header['local_ip'] = Context::$local_ip;
       $pack->header['local_port'] = Context::$local_port;
       $pack->header['socket_id'] = Context::$socket_id;
       $pack->header['client_ip'] = Context::$client_ip;
       $pack->header['client_port'] = Context::$client_port;
       $pack->header['uid'] = Context::$uid;
       $pack->body = (string)$message;
       $buffer = $pack->getBuffer();
       // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
       if(self::$businessWorker)
       {
           foreach(self::$businessWorker->getGatewayConnections() as $con)
           {
               self::$businessWorker->sendToClient($buffer, $con);
           }
       }
       // 运行在其它环境中，使用udp向worker发送数据
       else
       {
           $all_addresses = Store::get('GLOBAL_GATEWAY_ADDRESS');
           foreach($all_addresses as $address)
           {
               self::sendToGateway($address, $buffer);
           }
       }
   }
   
   /**
    * 向某个用户发消息
    * @param int $uid
    * @param string $message
    */
   public static function sendToUid($uid, $message)
   {
       return self::sendCmdAndMessageToUid($uid, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   }
   
   /**
    * 向当前用户发送消息
    * @param string $message
    */
   public static function sendToCurrentUid($message)
   {
       return self::sendCmdAndMessageToUid(null, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   }
   
   /**
    * 将某个用户踢出
    * @param int $uid
    * @param string $message
    */
   public static function kickUid($uid, $message)
   {
       if($uid === Context::$uid)
       {
           return self::kickCurrentUser($message);
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address = self::getAddressByUid($uid);
           if(!$address)
           {
               return false;
           }
           return self::kickAddress($address['local_ip'], $address['local_port'], $address['socket_id'], $message);
       }
   }
   
   /**
    * 踢掉当前用户
    * @param string $message
    */
   public static function kickCurrentUser($message)
   {
       return self::kickAddress(Context::$local_ip, Context::$local_port, Context::$socket_id, $message);
   }
   

   /**
    * 想某个用户网关发送命令和消息
    * @param int $uid
    * @param int $cmd
    * @param string $message
    * @return boolean
    */
   public static function sendCmdAndMessageToUid($uid, $cmd , $message)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = $cmd;
       $pack->header['series_id'] = Context::$series_id > 0 ? Context::$series_id : 0;
       // 如果是发给当前用户则直接获取上下文中的地址
       if($uid === Context::$uid || $uid === null)
       {
           $pack->header['local_ip'] = Context::$local_ip;
           $pack->header['local_port'] = Context::$local_port;
           $pack->header['socket_id'] = Context::$socket_id;
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address = self::getAddressByUid($uid);
           if(!$address)
           {
               return false;
           }
           $pack->header['local_ip'] = $address['local_ip'];
           $pack->header['local_port'] = $address['local_port'];
           $pack->header['socket_id'] = $address['socket_id'];
       }
       $pack->header['client_ip'] = Context::$client_ip;
       $pack->header['client_port'] = Context::$client_port;
       $pack->header['uid'] = empty($uid) ? 0 : $uid;
       $pack->body = (string)$message;
       
       return self::sendToGateway("{$pack->header['local_ip']}:{$pack->header['local_port']}", $pack->getBuffer());
   }
   
   
   /**
    * 踢掉某个网关的socket
    * @param string $local_ip
    * @param int $local_port
    * @param int $socket_id
    * @param string $message
    * @param int $uid
    */
   public static function kickAddress($local_ip, $local_port, $socket_id, $message, $uid = null)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = GatewayProtocol::CMD_KICK;
       $pack->header['series_id'] = Context::$series_id > 0 ? Context::$series_id : 0;
       $pack->header['local_ip'] = $local_ip;
       $pack->header['local_port'] = $local_port;
       $pack->header['socket_id'] = $socket_id;
       if(null !== Context::$client_ip)
       {
           $pack->header['client_ip'] = Context::$client_ip;
           $pack->header['client_port'] = Context::$client_port;
       }
       $pack->header['uid'] = $uid ? $uid : 0;
       $pack->body = (string)$message;
       
       return self::sendToGateway("{$pack->header['local_ip']}:{$pack->header['local_port']}", $pack->getBuffer());
   }
   
   /**
    * 存储uid的网关地址
    * @param int $uid
    */
   public static function storeUid($uid)
   {
       $address = array('local_ip'=>Context::$local_ip, 'local_port'=>Context::$local_port, 'socket_id'=>Context::$socket_id);
       Store::set($uid, $address);
   }
   
   /**
    * 获取用户的网关地址
    * @param int $uid
    */
   public static function getAddressByUid($uid)
   {
       return Store::get($uid);
   }
   
   /**
    * 删除用户的网关地址
    * @param int $uid
    */
   public static function deleteUidAddress($uid)
   {
       return Store::delete($uid);
   }
   
   /**
    * 通知网关uid链接成功（通过验证）
    * @param int $uid
    */
   public static function notifyConnectionSuccess($uid)
   {
       return self::sendCmdAndMessageToUid($uid, GatewayProtocol::CMD_CONNECT_SUCCESS, '');
   }
   
   /**
    * 发送数据到网关
    * @param string $address
    * @param string $buffer
    */
   public static function sendToGateway($address, $buffer)
   {
       // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
       if(self::$businessWorker)
       {
           $connections = self::$businessWorker->getGatewayConnections();
           if(!isset($connections[$address]))
           {
               $e = new \Exception("sendToGateway($address, $buffer) fail \$connections:".json_encode($connections));
               APLog::add($e->__toString());
               return false;
           }
           return self::$businessWorker->sendToClient($buffer, $connections[$address]);
       }
       // 非workerman环境，使用udp发送数据
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       return strlen($buffer) == stream_socket_sendto($client, $buffer);
   }
}
