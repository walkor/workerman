<?php
namespace GatewayWorker\Lib;
/**
 * 
 * 数据发送相关
 * @author walkor <walkor@workerman.net>
 * 
 */
use \Workerman\Protocols\GatewayProtocol;
use \GatewayWorker\Lib\Store;
use \GatewayWorker\Lib\Context;

class Gateway
{
    /**
     * gateway实例
     * @var object
     */
    protected static  $businessWorker = null;
    
   /**
    * 向所有客户端(或者client_id_array指定的客户端)广播消息
    * @param string $message 向客户端发送的消息
    * @param array $client_id_array 客户端id数组
    */
   public static function sendToAll($message, $client_id_array = null)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_SEND_TO_ALL;
       $gateway_data['body'] = $message;
       
       if($client_id_array)
       {
           $params = array_merge(array('N*'), $client_id_array);
           $gateway_data['ext_data'] = call_user_func_array('pack', $params);
       }
       elseif(empty($client_id_array) && is_array($client_id_array))
       {
           return;
       }
       
       // 如果有businessWorker实例，说明运行在workerman环境中，通过businessWorker中的长连接发送数据
       if(self::$businessWorker)
       {
           foreach(self::$businessWorker->gatewayConnections as $gateway_connection)
           {
               $gateway_connection->send($gateway_data);
           }
       }
       // 运行在其它环境中，使用udp向worker发送数据
       else
       {
           $all_addresses = Store::instance('gateway')->get('GLOBAL_GATEWAY_ADDRESS');
           if(!$all_addresses)
           {
               throw new \Exception('GLOBAL_GATEWAY_ADDRESS is ' . var_export($all_addresses, true));
           }
           foreach($all_addresses as $address)
           {
               self::sendToGateway($address, $gateway_data);
           }
       }
   }
   
   /**
    * 向某个客户端发消息
    * @param int $client_id 
    * @param string $message
    */
   public static function sendToClient($client_id, $message)
   {
       return self::sendCmdAndMessageToClient($client_id, GatewayProtocol::CMD_SEND_TO_ONE, $message);
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
       $address = Store::instance('gateway')->get('gateway-'.$client_id);
       if(!$address)
       {
           return 0;
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_IS_ONLINE;
       $gateway_data['client_id'] = $client_id;
       return self::sendUdpAndRecv($address, $gateway_data);
   }
   
   /**
    * 获取在线状态，目前返回一个在线client_id数组
    * @return array
    */
   public static function getOnlineStatus()
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_GET_ONLINE_STATUS;
       $gateway_buffer = GatewayProtocol::encode($gateway_data);
       
       $all_addresses = Store::instance('gateway')->get('GLOBAL_GATEWAY_ADDRESS');
       $client_array = $status_data = array();
       // 批量向所有gateway进程发送CMD_GET_ONLINE_STATUS命令
       foreach($all_addresses as $address)
       {
           $client = stream_socket_client("udp://$address", $errno, $errmsg);
           if(strlen($gateway_buffer) === stream_socket_sendto($client, $gateway_buffer))
           {
               $client_id = (int) $client;
               $client_array[$client_id] = $client;
           }
       }
       // 超时1秒
       $time_out = 1;
       $time_start = microtime(true);
       // 批量接收请求
       while(count($client_array) > 0)
       {
           $write = $except = array();
           $read = $client_array;
           if(@stream_select($read, $write, $except, $time_out))
           {
               foreach($read as $client)
               {
                   // udp
                   $data = json_decode(fread($client, 65535), true);
                   if($data)
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
    * 关闭某个客户端
    * @param int $client_id
    * @param string $message
    */
   public static function closeClient($client_id)
   {
       if($client_id === Context::$client_id)
       {
           return self::closeCurrentClient();
       }
       // 不是发给当前用户则使用存储中的地址
       else
       {
           $address = Store::instance('gateway')->get('gateway-'.$client_id);
           if(!$address)
           {
               return false;
           }
           return self::kickAddress($address, $client_id);
       }
   }
   
   /**
    * 踢掉当前客户端
    * @param string $message
    */
   public static function closeCurrentClient()
   {
       return self::kickAddress(Context::$local_ip.':'.Context::$local_port, Context::$client_id);
   }
   
   /**
    * 更新session,框架自动调用，开发者不要调用
    * @param int $client_id
    * @param string $session_str
    */
   public static function updateSocketSession($client_id, $session_str)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_UPDATE_SESSION;
       $gateway_data['client_id'] = $client_id;
       $gateway_data['ext_data'] = $session_str;
       return self::sendToGateway(Context::$local_ip . ':' . Context::$local_port, $gateway_data);
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
       // 如果是发给当前用户则直接获取上下文中的地址
       if($client_id === Context::$client_id || $client_id === null)
       {
           $address = Context::$local_ip.':'.Context::$local_port;
       }
       else
       {
           $address = Store::instance('gateway')->get('gateway-'.$client_id);
           if(!$address)
           {
               return false;
           }
       }
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = $cmd;
       $gateway_data['client_id'] = $client_id ? $client_id : Context::$client_id;
       $gateway_data['body'] = $message;
       
       return self::sendToGateway($address, $gateway_data);
   }
   
   /**
    * 发送udp数据并返回
    * @param int $address
    * @param string $message
    * @return boolean
    */
   protected static function sendUdpAndRecv($address , $data)
   {
       $buffer = GatewayProtocol::encode($data);
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
   protected static function sendToGateway($address, $gateway_data)
   {
       // 有$businessWorker说明是workerman环境，使用$businessWorker发送数据
       if(self::$businessWorker)
       {
           if(!isset(self::$businessWorker->gatewayConnections[$address]))
           {
               return false;
           }
           return self::$businessWorker->gatewayConnections[$address]->send($gateway_data);
       }
       // 非workerman环境，使用udp发送数据
       $gateway_buffer = GatewayProtocol::encode($gateway_data);
       $client = stream_socket_client("udp://$address", $errno, $errmsg);
       return strlen($gateway_buffer) == stream_socket_sendto($client, $gateway_buffer);
   }
   
   /**
    * 踢掉某个网关的socket
    * @param string $local_ip
    * @param int $local_port
    * @param int $client_id
    * @param string $message
    * @param int $client_id
    */
   protected  static function kickAddress($address, $client_id)
   {
       $gateway_data = GatewayProtocol::$empty;
       $gateway_data['cmd'] = GatewayProtocol::CMD_KICK;
       $gateway_data['client_id'] = $client_id;
       return self::sendToGateway($address, $gateway_data);
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
