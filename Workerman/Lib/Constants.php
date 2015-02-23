<?php
// 如果ini没设置时区，则设置一个默认的
if(!ini_get('date.timezone') )
{
    date_default_timezone_set('Asia/Shanghai');
}
// 显示错误到终端
ini_set('display_errors', 'on');

// 连接失败
define('WORKERMAN_CONNECT_FAIL', 1);
// 发送失败
define('WORKERMAN_SEND_FAIL', 2);