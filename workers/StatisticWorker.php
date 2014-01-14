<?php 
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
/**
 * 
* @author walkor <worker-man@qq.com>
 */
class StatisticWorker extends Man\Core\SocketWorker
{
    public function dealInput($recv_str)
    {
        
    }
    
    
    public function dealProcess($recv_str)
    {
        
    }
} 

/**
 * 
 * struct statisticPortocol
 * {
 *     unsigned char module_name_len;
 *     unsigned char interface_name_len;
 *     float cost_time_ms;
 *     unsigned char success;
 *     int code;
 *     unsigned short msg_len;
 *     unsigned int time;
 *     unsigned int ip;
 *     char[module_name_len] module_name;
 *     char[interface_name_len] interface_name;
 *     char[msg_len] msg;
 * }
 *  
 * @author valkor
 */
class statisticProtocol
{
    /**
     * 包头长度
     * @var integer
     */
    const PACKEGE_FIXED_LENGTH = 21;
    
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
     * @param float $cost_time_ms
     * @param int $success
     * @param int $code
     * @param string $msg
     * @param string $ip
     * @return string
     */
    public static function encode($module, $interface , $cost_time_ms, $success,  $code = 0,$msg = '', $ip = '127.0.0.1')
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
        return pack('CCfCNnNN', $module_name_length, $interface_name_length, $cost_time_ms, $success ? 1 : 0, $code, strlen($msg), time(), ip2long($ip)).$module.$interface.$msg;
    }
   
    /**
     * 解包
     * @param string $bin_data
     * @return array
     */
    public static function decode($bin_data)
    {
        // 解包
        $data = unpack("Cmodule_name_len/Cinterface_name_len/fcost_time_ms/Csuccess/Ncode/nmsg_len/Ntime/Nip", $data);
        $module = substr($bin_data, self::PACKEGE_FIXED_LENGTH, $data['module_name_len']);
        $interface = substr($bin_data, self::PACKEGE_FIXED_LENGTH + $data['module_name_len'], $data['interface_name_len']);
        $msg = substr($bin_data, self::PACKEGE_FIXED_LENGTH + $data['module_name_len'] + $data['interface_name_len']);
        return array(
                'module'          => $module,
                'interface'        => $interface,
                'cost_time_ms' => $data['cost_time_ms'],
                'success'           => $data['success'],
                'time'                => $data['time'],
                'ip'                    => $data['ip'],
                'code'               => $data['code'],
                'msg'                => $msg, 
                );
    }
    
}