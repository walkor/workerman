<?php
namespace Man\Core\Lib;
/**
 * 锁
 */
class Mutex
{
    /**
     * handle
     * @var resource
     */
    private static $fileHandle = null;
    
    /**
     * 获取写锁
     * @return true
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
     * 释放写锁
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
     * 获得handle
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
