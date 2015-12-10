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

// 如果ini没设置时区，则设置一个默认的
if(!ini_get('date.timezone') )
{
    date_default_timezone_set('Asia/Shanghai');
}
// 显示错误到终端
ini_set('display_errors', 'on');
// 报告所有错误
error_reporting(E_ALL);

// 连接失败
define('WORKERMAN_CONNECT_FAIL', 1);
// 发送失败
define('WORKERMAN_SEND_FAIL', 2);