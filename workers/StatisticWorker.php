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
 *     unsigned char $success;
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
    
    public static function encode($module, $interface , $cost_time_ms, $success, $code = 0,$msg = '')
    {
        $module_len = strlen($module);
        $interface_len = strlen($interface);
        $bin_data = pack("CC", $module_len, $interface_len);
        $bin_data .= self::FToN($cost_time_ms);
        $bin_data .= pack("C", $success);
    }
    
    public static function decode($bin_data)
    {
        
    }
    
    public static function IToN($val)
    {
        
    }
    
    public static function NToI($val)
    {
        $foo = unpack("N", $val);
        if($foo[1] > 0x7fffffff)
        {
            $foo[1] = 0 - (($foo[1] - 1) ^ 0xffffffff); 
        }
        return $foo[1];
    }
    
    /* Convert float from HostOrder to Network Order */
    public static function FToN( $val )
    {
        $a = unpack("I",pack( "f",$val ));
        return pack("N",$a[1] );
    }
    
    /* Convert float from Network Order to HostOrder */
    public static function NToF($val )
    {
        $a = unpack("N",$val);
        $b = unpack("f",pack( "I",$a[1]));
        return $b[1];
    }
    
    
}