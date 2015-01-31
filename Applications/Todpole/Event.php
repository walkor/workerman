<?php
/**
 * 
 * 主逻辑
 * 主要是处理 onMessage onClose 三个方法
 * @author walkor <walkor@workerman.net>
 * 
 */

use \GatewayWorker\Lib\Gateway;

class Event
{
   /**
    * 有消息时
    * @param int $client_id
    * @param string $message
    */
   public static function onMessage($client_id, $message)
   {
        // 获取客户端请求
        $message_data = json_decode($message, true);
        if(!$message_data)
        {
            return ;
        }
        
        switch($message_data['type'])
        {
            case 'login':
                Gateway::sendToCurrentClient('{"type":"welcome","id":'.$client_id.'}');
                break;
            // 更新用户
            case 'update':
                // 转播给所有用户
                Gateway::sendToAll(json_encode(
                        array(
                                'type'     => 'update',
                                'id'         => $client_id,
                                'angle'   => $message_data["angle"]+0,
                                'momentum' => $message_data["momentum"]+0,
                                'x'                   => $message_data["x"]+0,
                                'y'                   => $message_data["y"]+0,
                                'life'                => 1,
                                'name'           => isset($message_data['name']) ? $message_data['name'] : 'Guest.'.$client_id,
                                'authorized'  => false,
                                )
                        ));
                return;
            // 聊天
            case 'message':
                // 向大家说
                $new_message = array(
                    'type'=>'message', 
                    'id'=>$client_id,
                    'message'=>$message_data['message'],
                );
                return Gateway::sendToAll(json_encode($new_message));
        }
   }
   
   /**
    * 当用户断开连接时
    * @param integer $client_id 用户id
    */
   public static function onClose($client_id)
   {
       // 广播 xxx 退出了
       GateWay::sendToAll(json_encode(array('type'=>'closed', 'id'=>$client_id)));
   }
}
