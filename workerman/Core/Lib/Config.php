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
     * 默认应用配置匹配路径
     * @var string
     */
    const DEFAULT_CONFD_PATH = './conf/conf.d/*.conf';
    
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
         // 主配置位置
        $config_file = WORKERMAN_ROOT_DIR . '/conf/workerman.conf';
        if (!file_exists($config_file)) 
        {
            throw new \Exception('Configuration file "' . $config_file . '" not found');
        }
        // 载入主配置
        self::$config['workerman'] = self::parseFile($config_file);
        self::$config['workerman']['log_dir'] = isset(self::$config['workerman']['log_dir']) ? self::$config['workerman']['log_dir'] : WORKERMAN_ROOT_DIR.'/logs';
        self::$configFile = realpath($config_file);
        // 寻找应用配置
        $conf_d = isset(self::$config['workerman']['include']) ? self::$config['workerman']['include'] : self::DEFAULT_CONFD_PATH;
        $conf_d = WORKERMAN_ROOT_DIR . self::$config['workerman']['include'];
        foreach(glob($conf_d) as $config_file)
        {
            $worker_name = basename($config_file, '.conf');
            $config_data = self::parseFile($config_file);
            if(!isset($config_data['enable']) || $config_data['enable'] )
            {
                self::$config[$worker_name] = self::parseFile($config_file);
            }
            else 
            {
                continue;
            }
            // 支持 WORKERMAN_ROOT_DIR 配置
            array_walk_recursive(self::$config[$worker_name], array('\Man\Core\Lib\Config', 'replaceWORKERMAN_ROOT_DIR'));
            // 不是以 / 开头代表相对路径，相对于配置文件的路径，找出绝对路径
            if(0 !== strpos(self::$config[$worker_name]['worker_file'], '/'))
            {
                self::$config[$worker_name]['worker_file'] =dirname($config_file).'/'.self::$config[$worker_name]['worker_file'];
            }
            if(!isset(self::$config[$worker_name]['chdir']))
            {
                self::$config[$worker_name]['chdir'] = dirname($config_file);
            }
        }
        // 整理Monitor配置
        self::$config['Monitor'] = self::$config['workerman']['Monitor'];
        unset(self::$config['workerman']['Monitor']);
        self::$config['Monitor']['worker_file']= 'Common/Monitor.php';
        self::$config['Monitor']['persistent_connection'] = 1;
        self::$config['Monitor']['start_workers'] = 1;
        self::$config['Monitor']['user'] = 'root';
        self::$config['Monitor']['preread_length'] = 8192;
        self::$config['Monitor']['exclude_path'] = isset(self::$config['Monitor']['exclude_path']) ?  array_merge(self::$config['Monitor']['exclude_path'], get_included_files()) : get_included_files();
        self::$config['Monitor']['exclude_path'][] = self::$config['workerman']['log_dir'];
        if(!isset(self::$config['Monitor']['listen']))
        {
            $socket_file = '/tmp/workerman.'.fileinode(__FILE__).'.sock';
            self::$config['Monitor']['listen'] = 'unix://' . $socket_file;
        }
        // 支持 WORKERMAN_ROOT_DIR 配置
        array_walk_recursive(self::$config['Monitor'], array('\Man\Core\Lib\Config', 'replaceWORKERMAN_ROOT_DIR'));
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
    
    /**
     * 替换WORKERMAN_ROOT_DIR为真实WORKERMAN_ROOT_DIR常量表示的路径
     * @param mixed $val
     */
    public static function replaceWORKERMAN_ROOT_DIR(&$val)
    {
        $val = str_replace('WORKERMAN_ROOT_DIR', WORKERMAN_ROOT_DIR, $val);
    }
    
}
