<?php 
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
/**
 * 
* @author walkor <worker-man@qq.com>
 */
class StatisticWorker extends Man\Core\SocketWorker
{
    /**
     *  最大日志buffer，大于这个值就写磁盘
     * @var integer
     */
    const MAX_LOG_BUFFER_SZIE = 1024000;
    
    /**
     * 多长时间写一次数据到磁盘
     * @var integer
     */
    const WRITE_PERIOD_LENGTH = 60;
    
    /**
     * 多长时间清理一次老的磁盘数据
     * @var integer
     */
    const CLEAR_PERIOD_LENGTH = 86400;
    
    /**
     * 数据多长时间过期
     * @var integer
     */
    const EXPIRED_TIME = 1296000;
    
    /**
     * 统计数据 
     * ip=>modid=>interface=>['code'=>[xx=>count,xx=>count],'suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx]
     * @var array
     */
    protected $statisticData = array();
    
    /**
     * 日志的buffer
     * @var string
     */
    protected $logBuffer = '';
    
    /**
     * 放统计数据的目录（相对于workerman/logs/）
     * @var string
     */
    protected $statisticDir = 'statistic/statistic/';
    
    /**
     * 存放统计日志的目录（相对于workerman/logs/）
     * @var string
     */
    protected $logDir = 'statistic/log/';
    
    /**
     * 提供统计查询的socket
     * @var resource
     */
    protected $providerSocket = null;
    
    /**
     * udp 默认全部接收完毕
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return 0;
    }
    
    /**
     * 业务处理
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        // 如果是JSON协议，则是请求统计数据
        if($recv_str[0] === '{')
        {
            return $this->dealProvider($recv_str);
        }
        
        // 解码
        $unpack_data = StatisticProtocol::decode($recv_str);
        $module = $unpack_data['module'];
        $interface = $unpack_data['interface'];
        $cost_time = $unpack_data['cost_time'];
        $success = $unpack_data['success'];
        $time = $unpack_data['time'];
        $code = $unpack_data['code'];
        $msg = str_replace("\n", "<br>", $unpack_data['msg']);
        $ip = $this->getRemoteIp();
        
        // 模块接口统计
        $this->collectStatistics($module, $interface, $cost_time, $success, $ip, $code, $msg);
        // 全局统计
        $this->collectStatistics('WorkerMan', 'Statistics', $cost_time, $success, $ip, $code, $msg);
        
        // 失败记录日志
        if(!$success)
        {
            $this->logBuffer .= date('Y-m-d H:i:s',$time)."\t$ip\t$module::$interface\tcode:$code\tmsg:$msg\n";
            if(strlen($this->logBuffer) >= self::MAX_LOG_BUFFER_SZIE)
            {
                $this->writeLogToDisk();
            }
        }
    }
    
    /**
     * 收集统计数据
     * @param string $module
     * @param string $interface
     * @param float $cost_time
     * @param int $success
     * @param string $ip
     * @param int $code
     * @param string $msg
     * @return void
     */
   protected function collectStatistics($module, $interface , $cost_time, $success, $ip, $code, $msg)
   {
       // 统计相关信息
       if(!isset($this->statisticData[$ip]))
       {
           $this->statisticData[$ip] = array();
       }
       if(!isset($this->statisticData[$ip][$module]))
       {
           $this->statisticData[$ip][$module] = array();
       }
       if(!isset($this->statisticData[$ip][$module][$interface]))
       {
           $this->statisticData[$ip][$module][$interface] = array('code'=>array(), 'suc_cost_time'=>0, 'fail_cost_time'=>0, 'suc_count'=>0, 'fail_count'=>0);
       }
       if(!isset($this->statisticData[$ip][$module][$interface]['code'][$code]))
       {
           $this->statisticData[$ip][$module][$interface]['code'][$code] = 0;
       }
       $this->statisticData[$ip][$module][$interface]['code'][$code]++;
       if($success)
       {
           $this->statisticData[$ip][$module][$interface]['suc_cost_time'] += $cost_time;
           $this->statisticData[$ip][$module][$interface]['suc_count'] ++;
       }
       else
       {
           $this->statisticData[$ip][$module][$interface]['fail_cost_time'] += $cost_time;
           $this->statisticData[$ip][$module][$interface]['fail_count'] ++;
       }
   }
    
   /**
    * 将统计数据写入磁盘
    * @return void
    */
   public function writeStatisticsToDisk()
   {
       $time = time();
       // 循环将每个ip的统计数据写入磁盘
       foreach($this->statisticData as $ip => $mod_if_data)
       {
           foreach($mod_if_data as $module=>$items)
           {
               // 文件夹不存在则创建一个
               $file_dir = WORKERMAN_LOG_DIR . $this->statisticDir.$module;
               if(!is_dir($file_dir))
               {
                   umask(0);
                   mkdir($file_dir, 0777, true);
               }
               // 依次写入磁盘
               foreach($items as $interface=>$data)
               {
                   file_put_contents($file_dir. "/{$interface}|".date('Y-m-d'), "$ip\t$time\t{$data['suc_count']}\t{$data['suc_cost_time']}\t{$data['fail_count']}\t{$data['fail_cost_time']}\t".json_encode($data['code'])."\n", FILE_APPEND | LOCK_EX);
               }
           }
       }
       // 清空统计
       $this->statisticData = array();
   }
    
    /**
     * 将日志数据写入磁盘
     * @return void
     */    
    public function writeLogToDisk()
    {
        // 没有统计数据则返回
        if(empty($this->logBuffer))
        {
            return;
        }
        // 写入磁盘
        file_put_contents(WORKERMAN_LOG_DIR . $this->logDir . date('Y-m-d'), $this->logBuffer, FILE_APPEND | LOCK_EX);
        $this->logBuffer = '';
    }
    
    /**
     * 初始化
     * 统计目录检查
     * 初始化任务
     * @see Man\Core.SocketWorker::onStart()
     */
    protected function onStart()
    {
        // 初始化目录
        umask(0);
        $statistic_dir = WORKERMAN_LOG_DIR . $this->statisticDir;
        if(!is_dir($statistic_dir))
        {
            mkdir($statistic_dir, 0777, true);
        }
        $log_dir = WORKERMAN_LOG_DIR . $this->logDir;
        if(!is_dir($log_dir))
        {
            mkdir($log_dir, 0777, true);
        }
        // 初始化任务
        \Man\Core\Lib\Task::init($this->event);
        // 定时保存统计数据
        \Man\Core\Lib\Task::add(self::WRITE_PERIOD_LENGTH, array($this, 'writeStatisticsToDisk'));
        \Man\Core\Lib\Task::add(self::WRITE_PERIOD_LENGTH, array($this, 'writeLogToDisk'));
        // 定时清理不用的统计数据
        \Man\Core\Lib\Task::add(self::CLEAR_PERIOD_LENGTH, array($this, 'clearDisk'), array(WORKERMAN_LOG_DIR . $this->statisticDir, self::EXPIRED_TIME));
        \Man\Core\Lib\Task::add(self::CLEAR_PERIOD_LENGTH, array($this, 'clearDisk'), array(WORKERMAN_LOG_DIR . $this->logDir, self::EXPIRED_TIME));
        
        // 创建一个tcp监听，用来提供统计查询服务
        $this->providerSocket = stream_socket_server(\Man\Core\Lib\Config::get($this->workerName.'.provider_listen'));
        if($this->providerSocket)
        {
            $ret = $this->event->add($this->providerSocket,  \Man\Core\Events\BaseEvent::EV_READ, array($this, 'accept'));
        }
    }
    
    /**
     * 进程停止时需要将数据写入磁盘
     * @see Man\Core.SocketWorker::onStop()
     */
    protected function onStop()
    {
        $this->writeLogToDisk();
        $this->writeStatisticsToDisk();
    }
    
    /**
     * 清除磁盘数据
     * @param string $file
     * @param int $exp_time
     */
    protected function clearDisk($file = null, $exp_time = 86400)
    {
        $time_now = time();
        if(is_file($file))
        {
            $mtime = filemtime($file);
            if(!$mtime)
            {
                $this->notice("filemtime $file fail");
                return;
            }
            if($time_now - $mtime > $exp_time)
            {
                unlink($file);
            }
            return;
        }
        foreach (glob($file."/*") as $file_name) 
        {
            $this->clearDisk($file_name, $exp_time);
        }
    }
    
    /**
     * 处理请求统计
     * @param string $recv_str
     */
    protected function dealProvider($recv_str)
    {
        $req_data = json_decode(trim($recv_str), true);
        $module = $req_data['module'];
        $interface = $req_data['interface'];
        $cmd = $req_data['cmd'];
        $start_time = isset($req_data['start_time']) ? $req_data['start_time'] : '';
        $end_time = isset($req_data['end_time']) ? $req_data['end_time'] : '';
        $date = isset($req_data['date']) ? $req_data['date'] : '';
        $code = isset($req_data['code']) ? $req_data['code'] : '';
        $msg = isset($req_data['msg']) ? $req_data['msg'] : '';
        $offset = isset($req_data['offset']) ? $req_data['offset'] : '';
        $count = isset($req_data['count']) ? $req_data['count'] : 10;
        switch($cmd)
        {
            case 'get_statistic':
                $buffer = json_encode(array('modules'=>$this->getModules($module), 'statistic' => $this->getStatistic($date, $module, $interface)))."\n";
                return $this->sendToClient($buffer);
            case 'get_log':
                $buffer = json_encode($this->getStasticLog($module, $interface , $start_time , $end_time, $code = '', $msg = '', $offset='', $count=10))."\n";
                return $this->sendToClient($buffer);
        }
        return $this->sendToClient('pack err');
    }
    
    /**
     * 获取模块
     * @return array
     */
    public function getModules($current_module = '')
    {
        $st_dir = WORKERMAN_ROOT_DIR . $this->statisticDir;
        $modules_name_array = array();
        foreach(glob($st_dir."/*", GLOB_ONLYDIR) as $module_file)
        {
            $tmp = explode("/", $module_file);
            $module = end($tmp);
            $modules_name_array[$module] = array();
            if($current_module == $module)
            {
                $st_dir = $st_dir.$current_module.'/';
                $all_interface = array();
                foreach(glob($st_dir."*") as $file)
                {
                    if(is_dir($file))
                    {
                        continue;
                    }
                    list($interface, $date) = explode("|", basename($file));
                    $all_interface[$interface] = $interface;
                }
                $modules_name_array[$module] = $all_interface;
            }
        }
        return $modules_name_array;
    }
    
    /**
     * 获得统计数据
     * @param string $module
     * @param string $interface
     * @param int $date
     * @return bool/string
     */
    protected function getStatistic($date, $module, $interface)
    {
        if(empty($module) || empty($interface))
        {
            return '';
        }
        // log文件
        $log_file = $this->statisticDir."{$module}/{$interface}|{$date}";
        return @file_get_contents($log_file);
    }
    
    
    /**
     * 批量请求
     * @param array $request_buffer_array ['ip:port'=>req_buf, 'ip:port'=>req_buf, ...]
     * @return array
     */
    public function multiRequest($request_buffer_array)
    {
        $client_array = $sock_to_ip = $ip_list = array();
        foreach($request_buffer_array as $address => $buffer)
        {
            $client = stream_socket_client($address, $errno, $errmsg, 1);
            if(!$client)
            {
                $this->notice("connect $address fail");
                continue;
            }
            $client_array[$address] = $client;
            stream_set_timeout($client_array[$address], 0, 100000);
            fwrite($client_array[$address], $buffer);
            stream_set_blocking($client_array[$address], 0);
            $sock_to_address[(int)$client] = $address;
        }
        $read = $client_array;
        $write = $except = $read_buffer = array();
        $time_start = microtime(true);
        // 超时设置
        $timeout = 1;
        // 轮询处理数据
        while(count($read) > 0)
        {
            if(stream_select($read, $write, $except, $timeout))
            {
                foreach($read as $socket)
                {
                    $address = $sock_to_address[(int)$socket];
                    $buf = fread($socket, 8192);
                    if(!$buf)
                    {
                        if(feof($socket))
                        {
                            unset($client_array[$address]);
                        }
                        continue;
                    }
                    if(!isset($read_buffer[$address]))
                    {
                        $read_buffer[$address] = $buf;
                    }
                    else
                    {
                        $read_buffer[$address] .= $buf;
                    }
                    // 数据接收完毕
                    if("\n" === $read_buffer[$address][strlen($read_buffer[$address])-1])
                    {
                        unset($client_array[$address]);
                    }
                }
            }
            // 超时了
            if(microtime(true) - $time_start > $timeout)
            {
                break;
            }
            $read = $client_array;
        }
        ksort($read_buffer);
        return $read_buffer;
    }
    
    /**
     * 获取指定日志
     *
     */
    protected function getStasticLog($module, $interface , $start_time = '', $end_time = '', $code = '', $msg = '', $offset='', $count=100)
    {
        // log文件
        $log_file = WORKERMAN_ROOT_DIR . $this->logDir. (empty($start_time) ? date('Y-m-d') : date('Y-m-d', $start_time));
        if(!is_readable($log_file))
        {
            return array('offset'=>0, 'data'=>$log_file . 'not exists or not readable');
        }
        // 读文件
        $h = fopen($log_file, 'r');
    
        // 如果有时间，则进行二分查找，加速查询
        if($start_time && $offset === '' && ($file_size = filesize($log_file) > 50000))
        {
            $offset = $this->binarySearch(0, $file_size, $start_time-1, $h);
            $offset = $offset < 1000 ? 0 : $offset - 1000;
        }
    
        // 正则表达式
        $pattern = "/^([\d: \-]+)\t";
    
        if($module && $module != 'WorkerMan')
        {
            $pattern .= $module."::";
        }
        else
        {
            $pattern .= ".*::";
        }
    
        if($interface && $module != 'WorkerMan')
        {
            $pattern .= $interface."\t";
        }
        else
        {
            $pattern .= ".*\t";
        }
    
        if($code !== '')
        {
            $pattern .= "code:$code\t";
        }
        else
        {
            $pattern .= "code:\d+\t";
        }
    
        if($msg)
        {
            $pattern .= "msg:$msg";
        }
         
        $pattern .= '/';
    
        // 指定偏移位置
        if($offset >= 0)
        {
            fseek($h, (int)$offset);
        }
    
        // 查找符合条件的数据
        $now_count = 0;
        $log_buffer = '';
    
        while(1)
        {
            if(feof($h))
            {
                break;
            }
            // 读1行
            $line = fgets($h);
            if(preg_match($pattern, $line, $match))
            {
                // 判断时间是否符合要求
                $time = strtotime($match[1]);
                if($start_time)
                {
                    if($time<$start_time)
                    {
                        continue;
                    }
                }
                if($end_time)
                {
                    if($time>$end_time)
                    {
                        break;
                    }
                }
                // 收集符合条件的log
                $log_buffer .= $line;
                if(++$now_count >= $count)
                {
                    break;
                }
            }
        }
        // 记录偏移位置
        $offset = ftell($h);
        return array('offset'=>$offset, 'data'=>$log_buffer);
    }
    /**
     * 日志二分查找法
     * @param int $start_point
     * @param int $end_point
     * @param int $time
     * @param fd $fd
     * @return int
     */
    protected function binarySearch($start_point, $end_point, $time, $fd)
    {
        // 计算中点
        $mid_point = (int)(($end_point+$start_point)/2);
    
        // 定位文件指针在中点
        fseek($fd, $mid_point);
    
        // 读第一行
        $line = fgets($fd);
        if(feof($fd) || false === $line)
        {
            return ftell($fd);
        }
    
        // 第一行可能数据不全，再读一行
        $line = fgets($fd);
        if(feof($fd) || false === $line || trim($line) == '')
        {
            return ftell($fd);
        }
    
        // 判断是否越界
        $current_point = ftell($fd);
        if($current_point>=$end_point)
        {
            return $end_point;
        }
    
        // 获得时间
        $tmp = explode("\t", $line);
        $tmp_time = strtotime($tmp[0]);
    
        // 判断时间，返回指针位置
        if($tmp_time > $time)
        {
            return $this->binarySearch($start_point, $current_point, $time, $fd);
        }
        elseif($tmp_time < $time)
        {
            return $this->binarySearch($current_point, $end_point, $time, $fd);
        }
        else
        {
            return $current_point;
        }
    }
} 

/**
 *
 * struct statisticPortocol
 * {
 *     unsigned char module_name_len;
 *     unsigned char interface_name_len;
 *     float cost_time;
 *     unsigned char success;
 *     int code;
 *     unsigned short msg_len;
 *     unsigned int time;
 *     char[module_name_len] module_name;
 *     char[interface_name_len] interface_name;
 *     char[msg_len] msg;
 * }
 *
 * @author workerman.net
 */
class StatisticProtocol
{
    /**
     * 包头长度
     * @var integer
     */
    const PACKEGE_FIXED_LENGTH = 17;

    /**
     * udp 包最大长度
     * @var integer
     */
    const MAX_UDP_PACKGE_SIZE  = 65507;

    /**
     * char类型能保存的最大数值
     * @var integer
     */
    const MAX_CHAR_VALUE = 255;

    /**
     *  usigned short 能保存的最大数值
     * @var integer
     */
    const MAX_UNSIGNED_SHORT_VALUE = 65535;

    /**
     * 编码
     * @param string $module
     * @param string $interface
     * @param float $cost_time
     * @param int $success
     * @param int $code
     * @param string $msg
     * @return string
     */
    public static function encode($module, $interface , $cost_time, $success,  $code = 0,$msg = '')
    {
        // 防止模块名过长
        if(strlen($module) > self::MAX_CHAR_VALUE)
        {
            $module = substr($module, 0, self::MAX_CHAR_VALUE);
        }

        // 防止接口名过长
        if(strlen($interface) > self::MAX_CHAR_VALUE)
        {
            $interface = substr($interface, 0, self::MAX_CHAR_VALUE);
        }

        // 防止msg过长
        $module_name_length = strlen($module);
        $interface_name_length = strlen($interface);
        $avalible_size = self::MAX_UDP_PACKGE_SIZE - self::PACKEGE_FIXED_LENGTH - $module_name_length - $interface_name_length;
        if(strlen($msg) > $avalible_size)
        {
            $msg = substr($msg, 0, $avalible_size);
        }

        // 打包
        return pack('CCfCNnN', $module_name_length, $interface_name_length, $cost_time, $success ? 1 : 0, $code, strlen($msg), time()).$module.$interface.$msg;
    }
     
    /**
     * 解包
     * @param string $bin_data
     * @return array
     */
    public static function decode($bin_data)
    {
        // 解包
        $data = unpack("Cmodule_name_len/Cinterface_name_len/fcost_time/Csuccess/Ncode/nmsg_len/Ntime", $bin_data);
        $module = substr($bin_data, self::PACKEGE_FIXED_LENGTH, $data['module_name_len']);
        $interface = substr($bin_data, self::PACKEGE_FIXED_LENGTH + $data['module_name_len'], $data['interface_name_len']);
        $msg = substr($bin_data, self::PACKEGE_FIXED_LENGTH + $data['module_name_len'] + $data['interface_name_len']);
        return array(
                'module'          => $module,
                'interface'        => $interface,
                'cost_time' => $data['cost_time'],
                'success'           => $data['success'],
                'time'                => $data['time'],
                'code'               => $data['code'],
                'msg'                => $msg,
        );
    }

}