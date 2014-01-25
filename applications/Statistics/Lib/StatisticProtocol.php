<?php 
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