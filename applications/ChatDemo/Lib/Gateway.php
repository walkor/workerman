<?php
namespace Lib;
/**
 * 
 * 数据发送相关
 * sendToAll sendToUid
 * @author walkor <workerman.net>
 * 
 */
require_once __DIR__ . '/Autoloader.php';
use \Protocols\GatewayProtocol;
use \Lib\Store;
use \Lib\Context;

class Gateway
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
   public static function sendToAll($message, $uid_array = array())
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $pack->header['local_ip'] = Context::$local_ip;
       $pack->header['local_port'] = Context::$local_port;
       $pack->header['socket_id'] = Context::$socket_id;
       $pack->header['client_ip'] = Context::$client_ip;
       $pack->header['client_port'] = Context::$client_port;
       $pack->header['uid'] = Context::$uid;
       $pack->body = (string)$message;
       
       if($uid_array)
       {
           $params = array_merge(array('N*'), $uid_array);
           $pack->ext_data = call_user_func_array('pack', $params);
       }
       
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
           $all_addresses = Store::instance('gateway')->get('GLOBAL_GATEWAY_ADDRESS');
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
    * 判断是否在线
    * @param int $uid
    * @return 0/1
    */
   public static function isOnline($uid)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = \Protocols\GatewayProtocol::CMD_IS_ONLINE;;
       $pack->header['uid'] = $uid;
       $address = self::getAddressByUid($uid);
       if(!$address)
       {
           return 0;
       }
       return self::sendUdpAndRecv($address['local_ip']. ':' .$address['local_port'], $pack->getBuffer());
   }
   
   /**
    * 获取在线状态，目前返回一个在线uid数组
    * @return array
    */
   public static function getOnlineStatus()
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = \Protocols\GatewayProtocol::CMD_GET_ONLINE_STATUS;
       $buffer = $pack->getBuffer();
       $all_addresses = Store::instance('gateway')->get('GLOBAL_GATEWAY_ADDRESS');
       $client_array = $status_data = array();
       // 批量向所有gateway进程发送CMD_GET_ONLINE_STATUS命令
       foreach($all_addresses as $address)
       {
           $client = stream_socket_client("udp://$address", $errno, $errmsg);
           if(strlen($buffer) == stream_socket_sendto($client, $buffer))
           {
               $client_id = (int) $client;
               $client_array[$client_id] = $client;
           }
       }
       // 超时2秒
       $time_out = 2;
       $time_start = microtime(true);
       // 批量接收请求
       while(count($client_array) > 0)
       {
           $write = $except = array();
           $read = $client_array;
           if(stream_select($read, $write, $except, 1))
           {
               foreach($read as $client)
               {
                   if($data = json_decode(fread($client, 655350), true))
                   {
                       $status_data = array_merge($status_data, $data);
                   }
                   unset($client_array[$client]);
               }
           }
           if(microtime(true) - $time_start > $time_out)
           {
               break;
           }
       }
       return $status_data;
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
       // 如果是发给当前用户则直接获取上下文中的地址
       if($uid === Context::$uid || $uid === null)
       {
           $pack->header['local_ip'] = Context::$local_ip;
           $pack->header['local_port'] = Context::$local_port;
           $pack->header['socket_id'] = Context::$socket_id;
           $pack->header['uid'] = Context::$uid;
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
           $pack->header['uid'] = $uid;
       }
       $pack->header['client_ip'] = Context::$client_ip;
       $pack->header['client_port'] = Context::$client_port;
       $pack->body = (string)$message;
       
       return self::sendToGateway("{$pack->header['local_ip']}:{$pack->header['local_port']}", $pack->getBuffer());
   }
   
   /**
    * 发送udp数据并返回
    * @param int $address
    * @param string $message
    * @return boolean
    */
   protected static function sendUdpAndRecv($address , $buffer)
   {
       // 非workerman环境，使用udp发送数据
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       if(strlen($buffer) == stream_socket_sendto($client, $buffer))
       {
           // 阻塞读
           stream_set_blocking($client, 1);
           // 1秒超时
           stream_set_timeout($client, 1);
           // 读udp数据
           $data = fread($client, 655350);
           // 返回结果
           return json_decode($data, true);
       }
       else
       {
           throw new \Exception("sendUdpAndRecv($address, \$bufer) fail ! Can not send UDP data!", 502);
       }
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
       Store::instance('gateway')->set($uid, $address);
   }
   
   /**
    * 获取用户的网关地址
    * @param int $uid
    */
   public static function getAddressByUid($uid)
   {
       return Store::instance('gateway')->get($uid);
   }
   
   /**
    * 删除用户的网关地址
    * @param int $uid
    */
   public static function deleteUidAddress($uid)
   {
       return Store::instance('gateway')->delete($uid);
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
    * 更新session
    * @param int $uid
    * @param string $session_str
    */
   public static function updateSocketSession($socket_id, $session_str)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = GatewayProtocol::CMD_UPDATE_SESSION;
       $pack->header['socket_id'] = Context::$socket_id;
       $pack->ext_data = (string)$session_str;
       return self::sendToGateway(Context::$local_ip . ':' . Context::$local_port, $pack->getBuffer());
   }
   
   /**
    * 发送数据到网关
    * @param string $address
    * @param string $buffer
    */
   protected static function sendToGateway($address, $buffer)
   {
       // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
       if(self::$businessWorker)
       {
           $connections = self::$businessWorker->getGatewayConnections();
           if(!isset($connections[$address]))
           {
               $e = new \Exception("sendToGateway($address, $buffer) fail \$connections:".json_encode($connections));
               return false;
           }
           return self::$businessWorker->sendToClient($buffer, $connections[$address]);
       }
       // 非workerman环境，使用udp发送数据
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       return strlen($buffer) == stream_socket_sendto($client, $buffer);
   }
}
