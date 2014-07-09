<?php
define('ST_ROOT', realpath(__DIR__.'/../'));
require_once ST_ROOT .'/Lib/functions.php';
require_once ST_ROOT .'/Lib/Cache.php';
require_once ST_ROOT .'/Config/Config.php';
// 覆盖配置文件
foreach(glob(ST_ROOT . '/Config/Cache/*.php')  as $php_file)
{
    require_once $php_file;
}