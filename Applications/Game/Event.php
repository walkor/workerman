<?php
/**
 * 
 * 
 * @author walkor <worker-man@qq.com>
 * 
 */

require_once WORKERMAN_ROOT_DIR . 'Applications/Game/Store.php';

class Event
{
   public static function onConnect($address, $socket_id, $sid)
   {
       // 检查sid是否合法
       $uid = self::getUidBySid($sid);
       // 不合法踢掉
       if(!$uid)
       {
           self::kickAddress($address, $socket_id);
           return;
       }
       
       // 合法记录uid到address的映射
       self::storeUidAddress($uid, $address);
       
       // 发送数据包到address，确认connection成功
       self::notifyConnectionSuccess($address, $socket_id, $uid);
   }
   
   public static function onClose($uid)
   {
       
   }
   
   public static function kickUid($uid)
   {
       
   }
   
   public static function kickAddress($address, $socket_id)
   {
     
   }
   
   public static function storeUidAddress($uid, $address)
   {
       Store::set($uid, $address);
   }
   
   public static function getAddressByUid($uid)
   {
       return Store::get($uid);
   }
   
   public static function deleteUidAddress($uid)
   {
       return Store::delete($uid);
   }
   
   protected static function notifyConnectionSuccess($address, $socket_id, $uid)
   {
       $buf = new GameBuffer();
       $buf->header['cmd'] = GameBuffer::CMD_GATEWAY;
       $buf->header['sub_cmd'] = GameBuffer::SCMD_CONNECT_SUCCESS;
       $buf->header['from_uid'] = $socket_id;
       $buf->header['to_uid'] = $uid;
       GameBuffer::sendToGateway($address, $buf->getBuffer());
   }
   
   protected static function getUidBySid($sid)
   {
       return $sid;
   }
}
