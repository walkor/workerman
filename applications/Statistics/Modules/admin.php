<?php
namespace Statistics\Modules;
function admin()
{
    $act = isset($_GET['act'])? $_GET['act'] : 'home';
    switch($act)
    {
        case 'detect_server':
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $buffer = json_encode(array('cmd'=>'REPORT_IP'))."\n";
            socket_sendto($socket, $buffer, strlen($buffer), 0, '255.255.255.255', \Statistics\Web\Config::$ProviderPort);
            $time_start = microtime(true);
            $time_out = 2;
            $ip_list = array();
            while(microtime(true) - $time_start < $time_out)
            {
                $buf = $host = $port = '';
                if(socket_recvfrom($socket, $buf, 65535, 0, $host, $port))
                {
                    $ip_list[$host] = $host;
                    echo $buf;
                    var_export($ip_list);
                }
            }
            break;
    }
}