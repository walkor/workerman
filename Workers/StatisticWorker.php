<?php 
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
/**
 * 
 * 接口成功率统计worker
 * 定时写入磁盘，用来统计请求量、延迟、波动等信息
* @author walkor <worker-man@qq.com>
 */
class StatisticWorker extends WORKERMAN\Core\SocketWorker
{
    /**
     * 最大buffer长度
     * @var ineger
     */
    const MAX_BUFFER_SIZE = 524288;
    
    /**
     * 上次写日志数据到磁盘的时间
     * @var integer
     */
    protected $logLastWriteTime = 0;
    
    /**
     * 上次写统计数据到磁盘的时间
     * @var integer
     */
    protected $stLastWriteTime = 0;
    
    /**
     * 上次清理磁盘的时间
     * @var integer
     */
    protected $lastClearTime = 0;
    
    /**
     * 缓冲的日志数据
     * @var string
     */
    protected $logBuffer = '';
    
    /**
     * 缓冲的统计数据
     * modid=>interface=>['code'=>[xx=>count,xx=>count],'suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx, 'time'=>xxx]
     * @var array
     */
    protected $statisticData = array();
    
    /**
     * 多长时间写一次log数据
     * @var integer
     */
    protected $logSendTimeLong = 20;
    
    /**
     * 多长时间写一次统计数据
     * @var integer
     */
    protected $stSendTimeLong = 300;
    
    /**
     * 多长时间清除一次统计数据
     * @var integer
     */
    protected $clearTimeLong = 86400;
    
    /**
     * 日志过期时间 14days
     * @var integer
     */
    protected $logExpTimeLong = 1296000;
    
    /**
     * 统计结果过期时间 14days
     * @var integer
     */
    protected $stExpTimeLong = 1296000;
    
    /**
     * 固定包长
     * @var integer
     */
    const PACKEGE_FIXED_LENGTH = 25;
    
    
    
    /**
     * 默认只收1个包
     * 上报包的格式如下
     * struct{
     *     int                                    code,                 // 返回码
     *     unsigned int                           time,                 // 时间
     *     float                                  cost_time,            // 消耗时间 单位秒 例如1.xxx
     *     unsigned int                           source_ip,            // 来源ip
     *     unsigned int                           target_ip,            // 目标ip
     *     unsigned char                          success,              // 是否成功
     *     unsigned char                          module_name_length,   // 模块名字长度
     *     unsigned char                          interface_name_length,//接口名字长度
     *     unsigned short                         msg_length,           // 日志信息长度
     *     unsigned char[module_name_length]      module,               // 模块名字
     *     unsigned char[interface_name_length]   interface,            // 接口名字
     *     char[msg_length]                       msg                   // 日志内容
     *  }
     * @see Worker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return 0;
    }
    
    /**
     * 处理上报的数据 log buffer满的时候写入磁盘
     * @see Worker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        // 解包
        $time_now = time();
        $unpack_data = unpack("icode/Itime/fcost_time/Isource_ip/Itarget_ip/Csuccess/Cmodule_name_length/Cinterface_name_length/Smsg_length", $recv_str);
        $module = substr($recv_str, self::PACKEGE_FIXED_LENGTH, $unpack_data['module_name_length']);
        $interface = substr($recv_str, self::PACKEGE_FIXED_LENGTH + $unpack_data['module_name_length'], $unpack_data['interface_name_length']);
        $msg = substr($recv_str, self::PACKEGE_FIXED_LENGTH + $unpack_data['module_name_length'] + $unpack_data['interface_name_length'], $unpack_data['msg_length']);
        $msg = str_replace("\n", '<br>', $msg);
        $code = $unpack_data['code'];
        
        // 统计调用量、延迟、成功率等信息
        if(!isset($this->statisticData[$module]))
        {
            $this->statisticData[$module] = array();
        }
        if(!isset($this->statisticData[$module][$interface]))
        {
            $this->statisticData[$module][$interface] = array('code'=>array(), 'suc_cost_time'=>0, 'fail_cost_time'=>0, 'suc_count'=>0, 'fail_count'=>0, 'time'=>$this->stLastWriteTime);
        }
        if(!isset($this->statisticData[$module][$interface]['code'][$code]))
        {
            $this->statisticData[$module][$interface]['code'][$code] = 0;
        }
        $this->statisticData[$module][$interface]['code'][$code]++;
        if($unpack_data['success'])
        {
            $this->statisticData[$module][$interface]['suc_cost_time'] += $unpack_data['cost_time'];
            $this->statisticData[$module][$interface]['suc_count'] ++;
        }
        else
        {
            $this->statisticData[$module][$interface]['fail_cost_time'] += $unpack_data['cost_time'];
            $this->statisticData[$module][$interface]['fail_count'] ++;
        }
        
        // 如果不成功写入日志
        if(!$unpack_data['success'])
        {
            $log_str = date('Y-m-d H:i:s',$unpack_data['time'])."\t{$module}::{$interface}\tcode:{$unpack_data['code']}\tmsg:{$msg}\tsource_ip:".long2ip($unpack_data['source_ip'])."\ttarget_ip:".long2ip($unpack_data['target_ip'])."\n";
            // 如果buffer溢出，则写磁盘,并清空buffer
            if(strlen($this->logBuffer) + strlen($recv_str) > self::MAX_BUFFER_SIZE)
            {
                // 写入log数据到磁盘
                $this->wirteLogToDisk();
                $this->logBuffer = $log_str;
            }
            else 
            {
                $this->logBuffer .= $log_str;
            }
        }
        
    }
    
    /**
     * 将日志数据写入磁盘
     * @return void
     */
    protected function wirteLogToDisk()
    {
        // 初始化下一波统计数据
        $this->logLastWriteTime = time();
        
        // 有数据才写
        if(empty($this->logBuffer))
        {
            return true;
        }
        
        file_put_contents(WORKERMAN_LOG_DIR . 'statistic/log/'.date('Y-m-d', $this->logLastWriteTime), $this->logBuffer, FILE_APPEND | LOCK_EX);
        
        $this->logBuffer = '';
    }
    
    /**
     * 将统计数据写入磁盘
     * @return void
     */
    protected function wirteStToDisk()
    {
        // 记录
        $this->stLastWriteTime = $this->stLastWriteTime + $this->stSendTimeLong;
        
        // 有数据才写磁盘
        if(empty($this->statisticData))
        {
            return true;
        }
        
        $ip = $this->getRemoteIp();
        
        foreach($this->statisticData as $module=>$items)
        {
            if(!is_dir(WORKERMAN_LOG_DIR . 'statistic/st/'.$module))
            {
                umask(0);
                mkdir(WORKERMAN_LOG_DIR . 'statistic/st/'.$module, 0777, true);
            }
            foreach($items as $interface=>$data)
            {
                // modid=>['code'=>[xx=>count,xx=>count],'suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx, 'time'=>xxx]
                file_put_contents(WORKERMAN_LOG_DIR . "statistic/st/{$module}/{$interface}|".date('Y-m-d',$data['time']-1), "$ip\t{$data['time']}\t{$data['suc_count']}\t{$data['suc_cost_time']}\t{$data['fail_count']}\t{$data['fail_cost_time']}\t".json_encode($data['code'])."\n", FILE_APPEND | LOCK_EX);
            }
        }
        
        $this->statisticData = array();
    }
    
    /**
     * 该worker进程开始服务的时候会触发一次，初始化$logLastWriteTime
     * @return bool
     */
    protected function onStart()
    {
        // 创建LOG目录
        if(!is_dir(WORKERMAN_LOG_DIR . 'statistic/log'))
        {
            umask(0);
            @mkdir(WORKERMAN_LOG_DIR . 'statistic/log', 0777, true);
        }
        
        $time_now = time();
        $this->logLastWriteTime = $time_now;
        $this->stLastWriteTime = $time_now - $time_now%$this->stSendTimeLong;
        \WORKERMAN\Core\Lib\Task::init($this->event);
        \WORKERMAN\Core\Lib\Task::add(1, array($this, 'onAlarm'));
    }
    
    /**
     * 该worker进程停止服务的时候会触发一次，保存数据到磁盘
     * @return bool
     */
    protected function onStop()
    {
        // 发送数据到统计中心
        $this->wirteLogToDisk();
        $this->wirteStToDisk();
        return false;
    }
    
    /**
     * 每隔一定时间触发一次 
     * @see Worker::onAlarm()
     */
    public function onAlarm()
    {
        $time_now = time();
        // 检查距离最后一次发送数据到统计中心的时间是否超过设定时间
        if($time_now - $this->logLastWriteTime >= $this->logSendTimeLong)
        {
            // 发送数据到统计中心
            $this->wirteLogToDisk();
        }
        // 检查是否到了该发送统计数据的时间
        if($time_now - $this->stLastWriteTime >= $this->stSendTimeLong)
        {
            $this->wirteStToDisk();
        }
        
        // 检查是否到了清理数据的时间
        if($time_now - $this->lastClearTime >= $this->clearTimeLong)
        {
            $this->lastClearTime = $time_now;
            $this->clearDisk(WORKERMAN_LOG_DIR . 'statistic/log/', $this->logExpTimeLong);
            $this->clearDisk(WORKERMAN_LOG_DIR . 'statistic/st/', $this->stExpTimeLong);
        }
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
            $stat = stat($file);
            if(!$stat)
            {
                $this->notice("stat $file fail");
                return;
            }
            $mtime = $stat['mtime'];
            if($time_now - $mtime > $exp_time)
            {
                unlink($file);
            }
            return;
        }
        foreach (glob($file."/*") as $file_name) {
            if(is_dir($file_name))
            {
                $this->clearDisk($file_name, $exp_time);
                continue;
            }
            $stat = stat($file_name);
            if(!$stat)
            {
                $this->notice("stat $file fail");
                return;
            }
            $mtime = $stat['mtime'];
            if($time_now - $mtime > $exp_time)
            {
                unlink($file_name);
            }
        }
        
    }
    
} 
