<?php
namespace Statistics\Modules;
function admin()
{
    $act = isset($_GET['act'])? $_GET['act'] : 'home';
    switch($act)
    {
        case 'detect_server':
            // 创建udp socket
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $buffer = json_encode(array('cmd'=>'REPORT_IP'))."\n";
            // 广播
            socket_sendto($socket, $buffer, strlen($buffer), 0, '255.255.255.255', \Statistics\Web\Config::$ProviderPort);
            // 超时相关
            $time_start = microtime(true);
            $global_timeout = 1;
            $ip_list = array();
            $recv_timeout = array('sec'=>0,'usec'=>8000);
            socket_set_option($socket,SOL_SOCKET,SO_RCVTIMEO,$recv_timeout);
            
            // 循环读数据
            while(microtime(true) - $time_start < $global_timeout)
            {
                $buf = $host = $port = '';
                if(@socket_recvfrom($socket, $buf, 65535, 0, $host, $port))
                {
                    $ip_list[$host] = $host;
                }
            }
            break;
    }
    
    include ST_ROOT . '/Views/header.tpl.php';
    include ST_ROOT . '/Views/main.tpl.php';
    include ST_ROOT . '/Views/footer.tpl.php';
}