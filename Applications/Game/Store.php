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
        return apc_store($key, $value, $ttl);
    }
    
   public static function get($key)
   {
       return apc_fetch($key);
   }
   
   public static function delete($key)
   {
       return apc_delete($key);
   }
   
}
