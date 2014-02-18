<?php
require_once WORKERMAN_ROOT_DIR .'applications/Statistics/Web/_init.php';

$func = isset($_GET['fn']) ? $_GET['fn'] : 'main';
$func = "\\Statistics\\Modules\\".$func;
if(!function_exists($func))
{
    foreach(glob(ST_ROOT . "/Modules/*") as $php_file)
    {
        require_once $php_file;
    }
}

if(!function_exists($func))
{
    $func = "\\Statistics\\Modules\\main";
}

$module = isset($_GET['module']) ? $_GET['module'] : '';
$interface = isset($_GET['interface']) ? $_GET['interface'] : '';
$date = isset($_GET['date']) ? $_GET['date'] : date('Y-m-d');
$start_time = isset($_GET['start_time']) ? $_GET['start_time'] : strtotime(date('Y-m-d'));
$offset =  isset($_GET['offset']) ? $_GET['offset'] : 0; 
$count =  isset($_GET['count']) ? $_GET['count'] : 10; 
call_user_func_array($func, array($module, $interface, $date, $start_time, $offset, $count));