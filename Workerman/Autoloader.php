<?php
namespace Workerman;

if(!defined('WORKERMAN_ROOT_DIR'))
{
    define('WORKERMAN_ROOT_DIR', realpath(__DIR__  . '/../'));
}

require_once WORKERMAN_ROOT_DIR.'/Workerman/Lib/Constants.php';

class Autoloader
{
    protected static $_appInitPath = '';

    public static function setRootPath($root_path)
    {
        self::$_appInitPath = $root_path;
    }

    public static function loadByNamespace($name)
    {
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
        $class_file = self::$_appInitPath . '/' . $class_path.'.php';
        if(!is_file($class_file))
        {
            $class_file = WORKERMAN_ROOT_DIR . DIRECTORY_SEPARATOR . "$class_path.php";
        }
        if(is_file($class_file))
        {
            require_once($class_file);
            if(class_exists($name, false))
            {
                return true;
            }
        }
        return false;
    }
}

spl_autoload_register('\Workerman\Autoloader::loadByNamespace');