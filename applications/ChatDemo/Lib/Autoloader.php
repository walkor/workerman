<?php
if(!defined('ROOT_DIR'))
{
    define('ROOT_DIR', realpath(__DIR__ . '/../') . '/');
}
function loadByNamespace($name)
{
    $class_path = str_replace('\\', DIRECTORY_SEPARATOR ,$name);
    $class_file = ROOT_DIR . $class_path.'.php';
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
spl_autoload_register('loadByNamespace');