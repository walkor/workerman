<?php
namespace WORKERMAN\Core\Lib;
class Config
{
    public static $filename;
    public static $config = array();
    protected static $instances = null;

    private function __construct()
     {
        $config_file = WORKERMAN_ROOT_DIR . 'conf/workerman.conf';
        if (!file_exists($config_file)) 
        {
            throw new \Exception('Configuration file "' . $config_file . '" not found');
        }
        self::$config['workerman'] = self::parseFile($config_file);
        self::$filename = realpath($config_file);
        foreach(glob(WORKERMAN_ROOT_DIR . 'conf.d/*.conf') as $config_file)
        {
            $worker_name = basename($config_file, '.conf');
            self::$config[$worker_name] = self::parseFile($config_file);
        }
    }
    
    protected static function parseFile($config_file)
    {
        $config = parse_ini_file($config_file, true);
        if (!is_array($config) || empty($config))
        {
            throw new \Exception('Invalid configuration format');
        }
        return $config;
    }

    public static function instance()
    {
        if (!self::$instances) {
            self::$instances = new self();
        }
        return self::$instances;
    }

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
    
    public static function getAllWorkers()
    {
         $copy = self::$config;
         unset($copy['workerman']);
         return $copy;
    }
    
    public static function reload()
    {
        self::$instances = array();
    }
    
}
