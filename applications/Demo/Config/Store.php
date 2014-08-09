<?php 
namespace Config;

/**
 * 存储配置
 * @author walkor
 */
class Store
{
    // 使用文件存储，注意使用文件存储无法支持workerman分布式部署
    const DRIVER_FILE = 1;
    // 使用memcache存储，支持workerman分布式部署
    const DRIVER_MC = 2;
    
    // 使用哪种存储驱动 文件存储DRIVER_FILE 或者 memcache存储DRIVER_MC，为了更好的性能请使用DRIVER_MC
    public static $driver = self::DRIVER_FILE;
    
    // 如果使用文件存储，则在这里设置数据存储的目录，默认/tmp/下
    public static $storePath = '/tmp/workerman-Demo/';
    
    // 如果是memcache存储，则在这里设置memcache的ip端口，注意确保你安装了memcache扩展
    public static $gateway = array(
        '127.0.0.1:22322',
    );
    
}
