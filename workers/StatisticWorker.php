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
     * ip=>modid=>interface=>['code'=>[xx=>count,xx=>count],'suc_cost_time'=>xx,'fail_cost_time'=>xx, 'suc_count'=>xx, 'fail_count'=>xx, 'time'=>xxx]
     * @var array
     */
    protected $statisticData = array();
    
    /**
     * 日志的buffer
     * @var string
     */
    protected $logBuffer = '';
    
    public function dealInput($recv_str)
    {
        return 0;
    }
    
    public function dealProcess($recv_str)
    {
        $unpack_data = StatisticProtocol::decode($recv_str);
        $module = $unpack_data['module'];
        $interface = $unpack_data['interface'];
        $cost_time = $unpack_data['cost_time'];
        $success = $unpack_data['success'];
        $time = $unpack_data['time'];
        $code = $unpack_data['code'];
        $msg = str_replace("\n", "<br>", $unpack_data['msg']);
        $ip = $this->getRemoteIp();
        
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
            $this->statisticData[$ip][$module][$interface] = array('code'=>array(), 'suc_cost_time'=>0, 'fail_cost_time'=>0, 'suc_count'=>0, 'fail_count'=>0, 'time'=>$this->stLastWriteTime);
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
    
    
    
    
    public function writeLogToDisk()
    {
    
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
 * @author valkor
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
        $data = unpack("Cmodule_name_len/Cinterface_name_len/fcost_time/Csuccess/Ncode/nmsg_len/Ntime", $data);
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