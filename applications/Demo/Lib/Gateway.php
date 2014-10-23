<?php
namespace Lib;
/**
 * 
 * 数据发送相关
 * @author walkor <walkor@workerman.net>
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
    * 向所有客户端(或者client_id_array指定的客户端)广播消息
    * @param string $message 向客户端发送的消息（可以是二进制数据）
    * @param array $client_id_array 客户端id数组
    */
   public static function sendToAll($message, $client_id_array = null)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $pack->header['local_ip'] = Context::$local_ip;
       $pack->header['local_port'] = Context::$local_port;
       $pack->header['socket_id'] = Context::$socket_id;
       $pack->header['client_ip'] = Context::$client_ip;
       $pack->header['client_port'] = Context::$client_port;
       $pack->header['client_id'] = Context::$client_id;
       $pack->body = (string)$message;
       
       if($client_id_array)
       {
           $params = array_merge(array('N*'), $client_id_array);
           $pack->ext_data = call_user_func_array('pack', $params);
       }
       elseif(empty($client_id_array) && is_array($client_id_array))
       {
           return;
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
    * 向某个客户端发消息
    * @param int $client_id 客户端通过Gateway::bindClientId($client_id)绑定的client_id
    * @param string $message
    */
   public static function sendToClient($clinet_id, $message)
   {
       return self::sendCmdAndMessageToClient($clinet_id, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   } 
   
   /**
    * 向当前客户端发送消息
    * @param string $message
    */
   public static function sendToCurrentClient($message)
   {
       return self::sendCmdAndMessageToClient(null, GatewayProtocol::CMD_SEND_TO_ONE, $message);
   }
   
   /**
    * 判断某个客户端是否在线
    * @param int $client_id
    * @return 0/1
    */
   public static function isOnline($client_id)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = \Protocols\GatewayProtocol::CMD_IS_ONLINE;;
       $pack->header['client_id'] = $client_id;
       $address = Store::instance('gateway')->get($client_id);
       if(!$address)
       {
           return 0;
       }
       return self::sendUdpAndRecv($address['local_ip']. ':' .$address['local_port'], $pack->getBuffer());
   }
   
   /**
    * 获取在线状态，目前返回一个在线client_id数组
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
                   // udp
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
    * 将某个客户端踢出
    * @param int $client_id
    * @param string $message
    */
   public static function kickClient($client_id)
   {
       if($client_id === Context::$client_id)
       {
           return self::kickCurrentClient();
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address = Store::instance('gateway')->get($client_id);
           if(!$address)
           {
               return false;
           }
           return self::kickAddress($address['local_ip'], $address['local_port'], $address['socket_id']);
       }
   }
   
   /**
    * 踢掉当前客户端
    * @param string $message
    */
   public static function kickCurrentClient()
   {
       return self::kickAddress(Context::$local_ip, Context::$local_port, Context::$socket_id);
   }
   
   /**
    * 更新session,框架自动调用，开发者不要调用
    * @param int $client_id
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
    * 想某个用户网关发送命令和消息
    * @param int $client_id
    * @param int $cmd
    * @param string $message
    * @return boolean
    */
   protected static function sendCmdAndMessageToClient($client_id, $cmd , $message)
   {
       $pack = new GatewayProtocol();
       $pack->header['cmd'] = $cmd;
       // 如果是发给当前用户则直接获取上下文中的地址
       if($client_id === Context::$client_id || $client_id === null)
       {
           $pack->header['local_ip'] = Context::$local_ip;
           $pack->header['local_port'] = Context::$local_port;
           $pack->header['socket_id'] = Context::$socket_id;
           $pack->header['client_id'] = Context::$client_id;
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address = Store::instance('gateway')->get($client_id);
           if(!$address)
           {
               return false;
           }
           $pack->header['local_ip'] = $address['local_ip'];
           $pack->header['local_port'] = $address['local_port'];
           $pack->header['socket_id'] = $address['socket_id'];
           $pack->header['client_id'] = $client_id;
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
               throw new \Exception("sendToGateway($address, \$buffer) fail \$connections:".var_export($connections, true));
           }
           return self::$businessWorker->sendToClient($buffer, $connections[$address]);
       }
       // 非workerman环境，使用udp发送数据
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       return strlen($buffer) == stream_socket_sendto($client, $buffer);
   }
   
   /**
    * 踢掉某个网关的socket
    * @param string $local_ip
    * @param int $local_port
    * @param int $socket_id
    * @param string $message
    * @param int $client_id
    */
   protected  static function kickAddress($local_ip, $local_port, $socket_id)
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
       $pack->header['client_id'] =  0;
       $pack->body = '';
        
       return self::sendToGateway("{$pack->header['local_ip']}:{$pack->header['local_port']}", $pack->getBuffer());
   }
   
   /**
    * 设置gateway实例
    * @param Bootstrap/Gateway $gateway_instance
    */
   public static function setBusinessWorker($business_worker_instance)
   {
       self::$businessWorker = $business_worker_instance;
   }
 
}
