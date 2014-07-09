<?php
namespace Statistics\Modules;

function admin()
{
    $act = isset($_GET['act'])? $_GET['act'] : 'home';
    $err_msg = $notice_msg = $suc_msg = $ip_list_str = '';
    $action = 'save_server_list';
    switch($act)
    {
        case 'detect_server':
            // 创建udp socket
            $socket = socket_create(AF_INET, SOCK_DGRAM, SOL_UDP);
            socket_set_option($socket, SOL_SOCKET, SO_BROADCAST, 1);
            $buffer = json_encode(array('cmd'=>'REPORT_IP'))."\n";
            // 广播
            socket_sendto($socket, $buffer, strlen($buffer), 0, '255.255.255.255', \Statistics\Config\Config::$ProviderPort);
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
            
            // 过滤掉已经保存的ip
            $count = 0;
            foreach($ip_list as $ip)
            {
                if(!isset(\Statistics\Lib\Cache::$ServerIpList[$ip]))
                {
                    $ip_list_str .= $ip."\r\n";
                    $count ++;
                }
            }
            $action = 'add_to_server_list';
            $notice_msg = "探测到{$count}个新数据源";
            break;
        case 'add_to_server_list':
            if(empty($_POST['ip_list']))
            {
                $err_msg = "保存的ip列表为空";
                break;
            }
            $ip_list = explode("\n", $_POST['ip_list']);
            if($ip_list)
            {
                foreach($ip_list as $ip)
                {
                    $ip = trim($ip);
                    if(false !== ip2long($ip))
                    {
                        \Statistics\Lib\Cache::$ServerIpList[$ip] = $ip;
                    }
                }
            }
            $suc_msg = "添加成功";
            foreach(\Statistics\Lib\Cache::$ServerIpList as $ip)
            {
                $ip_list_str .= $ip."\r\n";
            }
            saveServerIpListToCache();
            break;
        case 'save_server_list':
            if(empty($_POST['ip_list']))
            {
                $err_msg = "保存的ip列表为空";
                break;
            }
            \Statistics\Lib\Cache::$ServerIpList = array();
            $ip_list = explode("\n", $_POST['ip_list']);
            if($ip_list)
            {
                foreach($ip_list as $ip)
                {
                    $ip = trim($ip);
                    if(false !== ip2long($ip))
                    {
                        \Statistics\Lib\Cache::$ServerIpList[$ip] = $ip;
                    }
                }
            }
            $suc_msg = "保存成功";
            foreach(\Statistics\Lib\Cache::$ServerIpList as $ip)
            {
                $ip_list_str .= $ip."\r\n";
            }
            saveServerIpListToCache();
            break;
        default:
            foreach(\Statistics\Lib\Cache::$ServerIpList as $ip)
            {
                $ip_list_str .= $ip."\r\n";
            }
    }
    
    include ST_ROOT . '/Views/header.tpl.php';
    include ST_ROOT . '/Views/admin.tpl.php';
    include ST_ROOT . '/Views/footer.tpl.php';
}

function saveServerIpListToCache()
{
    foreach(glob(ST_ROOT . '/Config/Cache/*.iplist.cache.php') as $php_file)
    {
        unlink($php_file);
    }
    file_put_contents(ST_ROOT . '/Config/Cache/'.time().'.iplist.cache.php', "<?php\n\\Statistics\\Lib\\Cache::\$ServerIpList=".var_export(\Statistics\Lib\Cache::$ServerIpList,true).';');
}