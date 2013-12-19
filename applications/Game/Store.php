<?php
/**
 * 
 * 
 * @author walkor <worker-man@qq.com>
 * 
 */

class Store
{
    public static function set($key, $value, $ttl = 0)
    {
        $key = strval($key);
        return apc_store($key, $value);
    }
    
   public static function get($key)
   {
       return apc_fetch($key);
   }
   
   public static function delete($key)
   {
       $key = strval($key);
       return apc_delete($key);
   }
   
}
