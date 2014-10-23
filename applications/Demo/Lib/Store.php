<?php
namespace Lib;
/**
 * 存储类
 * 这里用memcache实现
 * @author walkor <walkor@workerman.net>
 */
class Store
{
    /**
     * 实例数组
     * @var array
     */
    protected static $instance = array();
    
    /**
     * 获取实例
     * @param string $config_name
     * @throws \Exception
     */
    public static function instance($config_name)
    {
        // memcache 驱动
        if(\Config\Store::$driver == \Config\Store::DRIVER_MC)
        {
            if(!isset(\Config\Store::$$config_name))
            {
                echo "\\Config\\Store::$config_name not set\n";
                throw new \Exception("\\Config\\Store::$config_name not set\n");
            }
            
            if(!isset(self::$instance[$config_name]))
            {
                if(extension_loaded('Memcached'))
                {
                    self::$instance[$config_name] = new \Memcached;
                }
                elseif(extension_loaded('Memcache'))
                {
                    self::$instance[$config_name] = new \Memcache;
                }
                else
                {
                    sleep(2);
                    exit("extension memcached is not installed\n");
                }
                foreach(\Config\Store::$$config_name as $address)
                {
                    list($ip, $port) = explode(':', $address);
                    self::$instance[$config_name] ->addServer($ip, $port);
                }
            }
            return self::$instance[$config_name];
        }
        // 文件驱动
        else 
        {
            if(!isset(self::$instance[$config_name]))
            {
                self::$instance[$config_name] = new \Lib\StoreDriver\File($config_name);
            }
            return self::$instance[$config_name];
        }
    }
}
