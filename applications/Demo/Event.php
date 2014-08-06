<?php
/**
 * 
 * 
 * @author walkor <workerman.net>
 * 
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
        Gateway::sendToCurrentUid(TextProtocol::encode("type in your name:"));
    }
    
    /**
     * 网关有消息时，判断消息是否完整
     */
    public static function onGatewayMessage($buffer)
    {
        return TextProtocol::check($buffer);
    }
    
   /**
    * 此链接的用户没调用GateWay::notifyConnectionSuccess($uid);前（即没有得到验证），都触发onConnect
    * 已经调用GateWay::notifyConnectionSuccess($uid);的用户有消息时，则触发onMessage
    * @param string $message 一般是传递的账号密码等信息
    * @return void
    */
   public static function onConnect($message)
   {
       /*
        * 通过message验证用户，并获得uid。
        * 一般流程这里$message应该包含用户名 密码，然后根据用户名密码从数据库中获取uid
        * 这里只是根据时间戳生成uid，高并发下会有小概率uid冲突
        */ 
       $uid = self::checkUser($message);
       // 不合法踢掉
       if(!$uid)
       {
           // 踢掉
           return GateWay::kickCurrentUser(TextProtocol::encode('uid非法'));
       }
       
       $_SESSION['name'] = TextProtocol::decode($message);
       
       // [这步是必须的]合法，记录uid到gateway通信地址的映射
       GateWay::storeUid($uid);
       
       // [这步是必须的]发送数据包到address对应的gateway，确认connection成功
       GateWay::notifyConnectionSuccess($uid);
       
       Gateway::sendToCurrentUid("
chart room login success, your uid is $uid, name is {$_SESSION['name']}
use uid:words send message to one user
use words send message to all\n");
       
       // 广播所有用户，xxx come 
       GateWay::sendToAll(TextProtocol::encode("{$_SESSION['name']}[$uid] come"));
   }
   
   /**
    * 当用户断开连接时触发的方法
    * @param string $address 和该用户gateway通信的地址
    * @param integer $uid 断开连接的用户id 
    * @return void
    */
   public static function onClose($uid)
   {
       // 删除这个用户的gateway通信地址
       GateWay::deleteUidAddress($uid);
       
       // 广播 xxx 退出了
       GateWay::sendToAll(TextProtocol::encode("{$_SESSION['name']}[$uid] logout"));
   }
   
   /**
    * 有消息时触发该方法
    * @param int $uid 发消息的uid
    * @param string $message 消息
    * @return void
    */
   public static function onMessage($uid, $message)
   {
        $message_data = TextProtocol::decode($message);
        
        // 判断是否是私聊，私聊数据格式 uid:xxxxx
        $explode_array = explode(':', $message, 2);
        if(count($explode_array) > 1)
        {
            $to_uid = (int)$explode_array[0];
            GateWay::sendToUid($uid, TextProtocol::encode($_SESSION['name'] . "[$uid] said said to [$to_uid] :" . $explode_array[1]));
            return GateWay::sendToUid($to_uid, TextProtocol::encode($_SESSION['name'] . "[$uid] said to You :" . $explode_array[1]));
        }
        // 群聊
        return GateWay::sendToAll(TextProtocol::encode($_SESSION['name'] . "[$uid] said :" . $message));
   }
   
   
   /**
    * 用户第一次链接时，根据用户传递的消息（一般是用户名 密码）返回当前uid
    * 这里只是返回了时间戳相关的一个数字，高并发会有一定的几率uid冲突
    * @param string $message
    * @return number
    */
   protected static function checkUser($message)
   {
       return substr(strval(microtime(true)), 3, 10)*100;
   }
}
