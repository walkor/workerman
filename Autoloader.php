<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman;

// 包含常量定义文件
require_once __DIR__.'/Lib/Constants.php';

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
        // 如果是Workerman命名空间，则在当前目录寻找类文件
        if(strpos($name, 'Workerman\\') === 0)
        {
            $class_file = __DIR__.substr($class_path, strlen('Workerman')).'.php';
        }
        else 
        {
            // 先尝试在应用目录寻找文件
            if(self::$_appInitPath)
            {
                $class_file = self::$_appInitPath . DIRECTORY_SEPARATOR . $class_path.'.php';
            }
            // 文件不存在，则在上一层目录寻找
            if(empty($class_file) || !is_file($class_file))
            {
                $class_file = __DIR__.DIRECTORY_SEPARATOR.'..'.DIRECTORY_SEPARATOR . "$class_path.php";
            }
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