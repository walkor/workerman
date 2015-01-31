<?php
namespace GatewayWorker\Lib;
/**
 * lock
 */
class Lock
{
    /**
     * handle
     * @var resource
     */
    private static $fileHandle = null;
    
    /**
     * get lock
     * @param bool block
     * @return bool
     */
    public static function get($block=true)
    {
        $operation = $block ? LOCK_EX : LOCK_EX | LOCK_NB;
        if(self::getHandle())
        {
            return flock(self::$fileHandle, $operation);
        }
        return false;
    }
    
    /**
     * release lock
     * @return true
     */
    public static function release()
    {
        if(self::getHandle())
        {
            return flock(self::$fileHandle, LOCK_UN);
        }
        return false;
    }
    
    /**
     * get handle
     * @return resource
     */
    protected static function getHandle()
    {
        if(!self::$fileHandle)
        {
            self::$fileHandle = fopen(__FILE__, 'r+');
        }
        return self::$fileHandle;
    }
}
