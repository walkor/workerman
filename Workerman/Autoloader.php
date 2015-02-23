<?php
namespace Workerman;

// 定义Workerman根目录
if(!defined('WORKERMAN_ROOT_DIR'))
{
    define('WORKERMAN_ROOT_DIR', realpath(__DIR__  . '/../'));
}
// 包含常量定义文件
require_once WORKERMAN_ROOT_DIR.'/Workerman/Lib/Constants.php';

/**
 * 自动加载类
 * @author walkor<walkor@workerman.net>
 */
class Autoloader
{
    // 应用的初始化目录，作为加载类文件的参考目录
    protected static $_appInitPath = '';

    /**
     * 设置应用初始化目录
     * @param string $root_path
     * @return void
     */
    public static function setRootPath($root_path)
    {
          self::$_appInitPath = $root_path;
    }

    /**
     * 根据命名空间加载文件
     * @param string $name
     * @return boolean
     */
    public static function loadByNamespace($name)
    {
        // 相对路径
        $class_path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
        // 先尝试在应用目录寻找文件
        $class_file = self::$_appInitPath . '/' . $class_path.'.php';
        // 文件不存在，则在workerman根目录中寻找
        if(!is_file($class_file))
        {
            $class_file = WORKERMAN_ROOT_DIR . DIRECTORY_SEPARATOR . "$class_path.php";
        }
        // 找到文件
        if(is_file($class_file))
        {
            // 加载
            require_once($class_file);
            if(class_exists($name, false))
            {
                return true;
            }
        }
        return false;
    }
}
// 设置类自动加载回调函数
spl_autoload_register('\Workerman\Autoloader::loadByNamespace');