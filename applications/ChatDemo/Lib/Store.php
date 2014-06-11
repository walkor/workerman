<?php
/**
 * 
 * 这里用php数组文件来存储数据，
 * 为了获取高性能需要用类似memcache、redis的存储
 * @author walkor <worker-man@qq.com>
 * 
 */

class Store
{
    // 为了避免频繁读取磁盘，增加了缓存机制
    protected static $dataCache = array();
    // 上次缓存时间
    protected static $lastCacheTime = 0;
    // 保存数据的文件相对与WORKERMAN_LOG_DIR目录目录
    protected static $dataFile = 'data.php';
    // 打开文件的句柄
    protected static $dataFileHandle = null;
    
    // 缓存过期时间
    const CACHE_EXP_TIME = 1;
    
    public static function set($key, $value, $ttl = 0)
    {
        self::readDataFromDisk();
        self::$dataCache[$key] = $value;
        return self::writeToDisk();
    }
    
    public static function get($key, $use_cache = true)
    {
        if(time() - self::$lastCacheTime > self::CACHE_EXP_TIME)
        {
            self::readDataFromDisk();
        }
        return isset(self::$dataCache[$key]) ? self::$dataCache[$key] : null;
    }
   
    public static function delete($key)
    {
        self::readDataFromDisk();
        unset(self::$dataCache[$key]);
        return self::writeToDisk();
    }
    
    public static function deleteAll()
    {
        self::$dataCache = array();
        self::writeToDisk();
    }
   
    protected static function writeToDisk()
    {
        $data_file = WORKERMAN_LOG_DIR . self::$dataFile;
        if(!self::$dataFileHandle)
        {
            if(!is_file($data_file))
            {
                touch($data_file);
            }
            self::$dataFileHandle = fopen($data_file, 'r+');
            if(!self::$dataFileHandle)
            {
                return false;
            }
        }
        flock(self::$dataFileHandle, LOCK_EX);
        $ret = file_put_contents($data_file, "<?php \n return " . var_export(self::$dataCache, true). ';');
        flock(self::$dataFileHandle, LOCK_UN);
        return $ret;
    }
    
    protected static function readDataFromDisk()
    {
        $data_file = WORKERMAN_LOG_DIR . self::$dataFile;
        if(!is_file($data_file))
        {
            touch($data_file);
        }
        $cache = include WORKERMAN_LOG_DIR . self::$dataFile;
        if(is_array($cache))
        {
            self::$dataCache = $cache;
        }
        self::$lastCacheTime = time();
    }
}
