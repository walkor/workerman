<?php
namespace Man\Core\Lib;
/**
 * 锁
 */
class Mutex
{
    /**
     * 共享内存key
     * @var int
     */
    const SEM_KEY = IPC_KEY;
    
    /**
     * 信号量
     * @var resource
     */
    private static $semFd = null;
    
    /**
     * 获取写锁
     * @return true
     */
    public static function get()
    {
        self::getSemFd() && sem_acquire(self::getSemFd());
        return true;
    }
    
    /**
     * 释放写锁
     * @return true
     */
    public static function release()
    {
        self::getSemFd() && sem_release(self::getSemFd());
        return true;
    }
    
    /**
     * 获得SemFd
     */
    protected static function getSemFd()
    {
        if(!self::$semFd && extension_loaded('sysvsem'))
        {
            self::$semFd = sem_get(self::SEM_KEY);
        }
        return self::$semFd;
    }
}
