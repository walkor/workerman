<?php
namespace WORKERMAN\Core\Lib;
class Config
{
    public $filename;
    public $config;
    protected static $instances = array();

    private function __construct($domain = 'main') {
        $folder = WORKERMAN_ROOT_DIR . 'Config';
        $filename = $folder . '/' . $domain;
        $filename .= '.ini';

        if (!file_exists($filename)) {
            throw new \Exception('Configuration file "' . $filename . '" not found');
        }

        $config = parse_ini_file($filename, true);
        if (!is_array($config) || empty($config)) {
            throw new \Exception('Invalid configuration file format');
        }

        $this->config = $config['main'];
        unset($config['main']);
        $this->config['workers'] = $config;
        $this->filename = realpath($filename);
    }

    public static function instance($domain = 'main')
    {
        if (empty(self::$instances[$domain])) {
            self::$instances[$domain] = new self($domain);
        }
        return self::$instances[$domain];
    }

    public static function get($uri, $domain = 'main')
    {
        $node = self::instance($domain)->config;

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
    
    public static function reload()
    {
        self::$instances = array();
    }
    
}
