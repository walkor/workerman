<?php 
if(!ini_get('date.timezone') )
{
    date_default_timezone_set('Asia/Shanghai');
}
ini_set('display_errors', 'on');

define('WORKERMAN_CONNECT_FAIL', 1);

define('WORKERMAN_SEND_FAIL', 2);