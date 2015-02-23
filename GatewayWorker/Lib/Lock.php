<?php
namespace GatewayWorker\Lib;
/**
 * 锁
 * 基于文件锁实现
 */
class Lock
{
    /**
     * handle
     * @var resource
     */
    private static $fileHandle = null;
    
    /**
     * 获取锁
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
     * 释放锁
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
     * 获得文件句柄
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
