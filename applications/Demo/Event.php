<?php
/**
 * 聊天逻辑，使用的协议是 文本+回车
 * 测试方法 运行
 * telnet ip 8480
 * 可以开启多个telnet窗口，窗口间可以互相聊天
 * 
 * websocket协议的聊天室见workerman-chat及workerman-todpole
 * @author walkor <workerman.net>
 */

use \Lib\Context;
use \Lib\Gateway;
use \Lib\StatisticClient;
use \Lib\Store;
use \Protocols\GatewayProtocol;
use \Protocols\TextProtocol;

class Event
{
    /**
     * 当网关有客户端链接上来时触发，一般这里留空
     */
    public static function onGatewayConnect()
    {
        Gateway::sendToCurrentClient(TextProtocol::encode("type in your name:"));
    }
    
    /**
     * 网关有消息时，判断消息是否完整
     */
    public static function onGatewayMessage($buffer)
    {
        return TextProtocol::check($buffer);
    }
    
   /**
    * 有消息时触发该方法
    * @param int $client_id 发消息的client_id
    * @param string $message 消息
    * @return void
    */
   public static function onMessage($client_id, $message)
   {
        $message_data = TextProtocol::decode($message);
        
        // **************如果没有$_SESSION['name']说明没有设置过用户名，进入设置用户名逻辑************
        if(empty($_SESSION['name']))
        {
            $_SESSION['name'] = TextProtocol::decode($message);
            Gateway::sendToCurrentClient("chart room login success, your client_id is $client_id, name is {$_SESSION['name']}\nuse client_id:words send message to one user\nuse words send message to all\n");
             
            // 广播所有用户，xxx come
            return GateWay::sendToAll(TextProtocol::encode("{$_SESSION['name']}[$client_id] come"));
        }
        
        // ********* 进入聊天逻辑 ****************
        // 判断是否是私聊
        $explode_array = explode(':', $message, 2);
        // 私聊数据格式 client_id:xxxxx
        if(count($explode_array) > 1)
        {
            $to_client_id = (int)$explode_array[0];
            GateWay::sendToClient($client_id, TextProtocol::encode($_SESSION['name'] . "[$client_id] said said to [$to_client_id] :" . $explode_array[1]));
            return GateWay::sendToClient($to_client_id, TextProtocol::encode($_SESSION['name'] . "[$client_id] said to You :" . $explode_array[1]));
        }
        // 群聊
        return GateWay::sendToAll(TextProtocol::encode($_SESSION['name'] . "[$client_id] said :" . $message));
   }
   
   /**
    * 当用户断开连接时触发的方法
    * @param integer $client_id 断开连接的用户id
    * @return void
    */
   public static function onClose($client_id)
   {
       // 广播 xxx 退出了
       GateWay::sendToAll(TextProtocol::encode("{$_SESSION['name']}[$client_id] logout"));
   }
}
