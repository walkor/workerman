<?php
/**
 *
 * 上报接口调用统计信息的客户端 UDP协议
 * 用来统计调用量、成功率、耗时、错误码等信息
 *
 * @author liangl
 */
class StatisticClient
{
    // udp最大包长 linux:65507 mac:9216
    const MAX_UDP_PACKGE_SIZE  = 65507;
    
    // char类型能保存的最大数值
    const MAX_CHAR_VALUE = 255;
    // usigned short 能保存的最大数值
    const MAX_UNSIGNED_SHORT_VALUE = 65535;
    // 固定包长
    const PACKEGE_FIXED_LENGTH = 25;
    
    /**
     * [module=>[interface=>time_start, interface=>time_start ...], module=>[interface=>time_start..],..]
     * @var array
     */
    protected static $timeMap = array();


    /**
     * 模块接口上报消耗时间记时
     * @param string $module
     * @param string $interface
     * @return void
     */
    public static function tick($module = '', $interface = '')
    {
        self::$timeMap[$module][$interface] = microtime(true);
    }


    /**
     * 模块接口上报统计
     * 格式：
     * struct{
     *     int                                    code,                 // 返回码
     *     unsigned int                           time,                 // 时间
     *     float                                  cost_time,            // 消耗时间 单位秒 例如1.xxx
     *     unsigned int                           source_ip,            // 来源ip
     *     unsigned int                           target_ip,            // 目标ip
     *     unsigned char                          success,              // 是否成功
     *     unsigned char                          module_name_length,   // 模块名字长度
     *     unsigned char                          interface_name_length,// 接口名字长度
     *     unsigned short                         msg_length,           // 日志信息长度
     *     unsigned char[module_name_length]      module,               // 模块名字
     *     unsigned char[interface_name_length]   interface,            // 接口名字
     *     char[msg_length]                       msg                   // 日志内容
     *  }
     * @param string $module 模块名/类名
     * @param string $interface 接口名/方法名
     * @param int $code 返回码
     * @param string $msg 日志内容
     * @param bool $success 是否成功
     * @param string $ip ip1
     * @param string $source_ip ip2
     * @return true/false
     */
    public static function report($module, $interface, $code = 0, $msg = '', $success = true, $source_ip = '', $target_ip = '')
    {
        if(isset(self::$timeMap[$module][$interface]) && self::$timeMap[$module][$interface] > 0)
        {
            $time_start = self::$timeMap[$module][$interface];
            self::$timeMap[$module][$interface] = 0;
        }
        else if(isset(self::$timeMap['']['']) && self::$timeMap[''][''] > 0)
        {
            $time_start = self::$timeMap[''][''];
            self::$timeMap[''][''] = 0;
        }
        else
        {
            $time_start = microtime(true);
        }
         
        if(strlen($module) > self::MAX_CHAR_VALUE)
        {
            $module = substr($module, 0, self::MAX_CHAR_VALUE);
        }
        if(strlen($interface) > self::MAX_CHAR_VALUE)
        {
            $interface = substr($interface, 0, self::MAX_CHAR_VALUE);
        }
        $module_name_length = strlen($module);
        $interface_name_length = strlen($interface);
        //花费的时间
        $cost_time = microtime(true) - $time_start;
        $avalible_size = self::MAX_UDP_PACKGE_SIZE - self::PACKEGE_FIXED_LENGTH - $module_name_length - $interface_name_length;
        if(strlen($msg) > $avalible_size)
        {
            $msg = substr($msg, 0, $avalible_size);
        }
         
        $data = pack("iIfIICCCS",
                $code,
                time(),
                $cost_time,
                $source_ip ? ip2long($source_ip) : ip2long('127.0.0.1'),
                $target_ip ? ip2long($target_ip) : ip2long('127.0.0.1'),
                $success ? 1 : 0,
                $module_name_length,
                $interface_name_length,
                strlen($msg)
        );
         
        return self::sendData($data.$module.$interface.$msg);
    }

    /**
     * 发送统计数据到监控进程
     *
     * @param string $bin_data
     * @param string $ip
     * @param int $port
     * @param string $protocol upd/tcp
     * @return bool
     */
    private static function sendData($bin_data, $ip = '127.0.0.1', $port = 2207, $protocol = 'udp')
    {
        $socket = stream_socket_client("{$protocol}://$ip:{$port}");
        if(!$socket)
        {
            return false;
        }
        $len = stream_socket_sendto($socket, $bin_data);
        return $len == strlen($bin_data);
    }
}