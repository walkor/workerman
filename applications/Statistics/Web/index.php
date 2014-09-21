<?php
require_once  __DIR__.'/_init.php';

// 检查是否登录
check_auth();

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
$log_count_per_ip = $log_count_per_page = 40;
if(empty($_GET['count']) && $ip_count = count(\Statistics\Lib\Cache::$ServerIpList))
{
    $log_count_per_ip = ceil($log_count_per_page/$ip_count); 
}
call_user_func_array($func, array($module, $interface, $date, $start_time, $offset, $log_count_per_ip));