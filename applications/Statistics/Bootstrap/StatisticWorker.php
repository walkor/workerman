<?php 
/**
 * 
* @author walkor <walkor@workerman.net>
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
    public function dealInput($recv_buffer)
    {
        return 0;
    }
    
    /**
     * 业务处理
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_buffer)
    {
        // 解码
        $unpack_data = StatisticProtocol::decode($recv_buffer);
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
                   file_put_contents($file_dir. "/{$interface}.".date('Y-m-d'), "$ip\t$time\t{$data['suc_count']}\t{$data['suc_cost_time']}\t{$data['fail_count']}\t{$data['fail_cost_time']}\t".json_encode($data['code'])."\n", FILE_APPEND | LOCK_EX);
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
    public function clearDisk($file = null, $exp_time = 86400)
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
    const PACKAGE_FIXED_LENGTH = 17;

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
        $avalible_size = self::MAX_UDP_PACKGE_SIZE - self::PACKAGE_FIXED_LENGTH - $module_name_length - $interface_name_length;
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
        $module = substr($bin_data, self::PACKAGE_FIXED_LENGTH, $data['module_name_len']);
        $interface = substr($bin_data, self::PACKAGE_FIXED_LENGTH + $data['module_name_len'], $data['interface_name_len']);
        $msg = substr($bin_data, self::PACKAGE_FIXED_LENGTH + $data['module_name_len'] + $data['interface_name_len']);
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
