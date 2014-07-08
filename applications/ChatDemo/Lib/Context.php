<?php
/**
 * 上下文 包含当前用户uid， 内部通信local_ip local_port socket_id ，以及客户端client_ip client_port
 * @author walkor
 */
class Context
{
    public static $series_id;
    public static $local_ip;
    public static $local_port;
    public static $socket_id;
    public static $client_ip;
    public static $client_port;
    public static $uid;
    public static function clear()
    {
        self::$series_id = self::$local_ip = self::$local_port = self::$socket_id = self::$client_ip = self::$client_port = self::$uid = null;
    }
}
