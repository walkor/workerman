<?php
if(!defined('WORKERMAN_ROOT_DIR'))
{
    define('WORKERMAN_ROOT_DIR', realpath(__DIR__  . '/../'));
}

require_once WORKERMAN_ROOT_DIR.'/Workerman/Lib/Constants.php';

function workerman_loader($name)
{
    $class_path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    $class_file = WORKERMAN_ROOT_DIR . DIRECTORY_SEPARATOR . "$class_path.php";
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
spl_autoload_register('workerman_loader');