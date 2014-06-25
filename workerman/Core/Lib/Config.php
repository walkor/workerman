<?php
namespace Man\Core\Lib;
/**
 * 
 * 配置
 * @author walkor
 *
 */
class Config
{
    /**
     * 配置文件名称
     * @var string
     */
    public static $configFile;
    
    /**
     * 配置数据
     * @var array
     */
    public static $config = array();
    
    /**
     * 实例
     * @var instance of Config
     */
    protected static $instances = null;

    /**
     * 构造函数
     * @throws \Exception
     */
    private function __construct()
     {
        $config_file = WORKERMAN_ROOT_DIR . '/conf/workerman.conf';
        if (!file_exists($config_file)) 
        {
            throw new \Exception('Configuration file "' . $config_file . '" not found');
        }
        self::$config['workerman'] = self::parseFile($config_file);
        self::$configFile = realpath($config_file);
        foreach(glob(WORKERMAN_ROOT_DIR . 'conf/conf.d/*.conf') as $config_file)
        {
            $worker_name = basename($config_file, '.conf');
            self::$config[$worker_name] = self::parseFile($config_file);
        }
    }
    
    /**
     * 解析配置文件
     * @param string $config_file
     * @throws \Exception
     */
    protected static function parseFile($config_file)
    {
        $config = parse_ini_file($config_file, true);
        if (!is_array($config) || empty($config))
        {
            throw new \Exception('Invalid configuration format');
        }
        return $config;
    }

   /**
    * 获取实例
    * @return \Man\Core\Lib\instance
    */
    public static function instance()
    {
        if (!self::$instances) {
            self::$instances = new self();
        }
        return self::$instances;
    }

    /**
     * 获取配置
     * @param string $uri
     * @return mixed
     */
    public static function get($uri)
    {
        $node = self::$config;
        $paths = explode('.', $uri);
        while (!empty($paths)) {
            $path = array_shift($paths);
            if (!isset($node[$path])) {
                return null;
            }
            $node = $node[$path];
        }
        return $node;
    }
    
    /**
     * 获取所有的workers
     * @return array
     */
    public static function getAllWorkers()
    {
         $copy = self::$config;
         unset($copy['workerman']);
         return $copy;
    }
    
    /**
     * 重新载入配置
     * @return void
     */
    public static function reload()
    {
        self::$instances = null;
        self::instance();
    }
    
}
