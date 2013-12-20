<?php 

/**
 * http 协议解析 相关
 * 简单的实现 不支持header cookie
 * @author walkor <worker-man@qq.com>
 * */
class SimpleHttp{
    
    /**
     * 构造函数
     */
    private  function __construct(){}
    
    /**
     * http头
     * @var array
     */
    public static $header = array();
    
    /**
     * cookie 
     * @var array
     */
    protected static $cookie = array();
    
    /**
     * 判断数据包是否全部接收完成
     * 
     * @param string $data
     * @return int 0:完成 1:还要接收数据
     */
    public static function input($data)
    {
        // 查找\r\n\r\n
        $data_length = strlen($data);
        
        if(!strpos($data, "\r\n\r\n"))
        {
            return 1;
        }
        
        // POST请求还要读包体
        if(strpos($data, "POST"))
        {
            // 找Content-Length
            $match = array();
            if(preg_match("/\r\nContent-Length: ?(\d?)\r\n/", $data, $match))
            {
                $content_lenght = $match[1];
            }
            else
            {
                return 0;
            }
            
            // 看包体长度是否符合
            $tmp = explode("\r\n\r\n", $data);
            if(strlen($tmp[1]) >= $content_lenght)
            {
                return 0;
            }
            return 1;
        }
        else 
        {
            return 0;
        }
        
        // var_export($header_data);
        return 0;
    }    
    
    /**
     * 解析http协议包，并设置相应环境变量
     * 
     * @param string $data
     * @return array
     */
    public static function decode($data)
    {
        $_SERVER = array(
                'REQUEST_URI'    => '/',
                'HTTP_HOST'      => '127.0.0.1',
                'HTTP_COOKIE'    => '',
                );
        
        $_POST = array();
        $_GET = array();
        $GLOBALS['HTTP_RAW_POST_DATA'] = array();
        
        // 将header分割成数组
        $header_data = explode("\r\n", $data);
        
        // 需要解析$_POST
        if(strpos($data, "POST") === 0)
        {
            $tmp = explode("\r\n\r\n", $data);
            parse_str($tmp[1], $_POST);
            
            // $GLOBALS['HTTP_RAW_POST_DATA']
            $GLOBALS['HTTP_RAW_POST_DATA'] = $tmp[1];
        }
        
        // REQUEST_URI
        $tmp = explode(' ', $header_data[0]);
        $_SERVER['REQUEST_URI'] = isset($tmp[1]) ? $tmp[1] : '/';
        
        // PHP_SELF
        $base_name = basename($_SERVER['REQUEST_URI']);
        $_SERVER['PHP_SELF'] = empty($base_name) ? 'index.php' : $base_name;
        
        unset($header_data[0]);
        foreach($header_data as $content)
        {
            // 解析HTTP_HOST
            if(strpos($content, 'Host') === 0)
            {
                $tmp = explode(':', $content);
                if(isset($tmp[1]))
                {
                    $_SERVER['HTTP_HOST'] = $tmp[1];
                }
                if(isset($tmp[2]))
                {
                    $_SERVER['SERVER_PORT'] = $tmp[2];
                }
            }
            // 解析Cookie
            elseif(strpos($content, 'Cookie') === 0)
            {
                $tmp = explode(' ', $content);
                if(isset($tmp[1]))
                {
                    $_SERVER['HTTP_COOKIE'] = $tmp[1];
                }
            }
        }
        
        // 'REQUEST_TIME_FLOAT' => 1375774613.237,
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['REQUEST_TIME'] = intval($_SERVER['REQUEST_TIME_FLOAT']);
        
        // GET
        parse_str(preg_replace('/^\/.*?\?/', '', $_SERVER['REQUEST_URI']), $_GET);
        unset($_GET['/']);
        
    }
    
    /**
     * 设置http头
     * @return bool
     */
    public static function header($content)
    {
        if(strpos($content, 'HTTP') === 0)
        {
            $key = 'Http-Code';
        }
        else
        {
            $key = strstr($content, ":", true);
            if(empty($key))
            {
                return false;
            }
        }
        self::$header[$key] = $content;
        return true;
    }
    
    /**
     * 
     * @param string $name
     * @param string/int $value
     * @param int $expire
     */
    public static function setcookie($name, $value='', $expire=0)
    {
        // 待完善
    }
    
    /**
     * 清除header
     * @return void
     */
    public static function clear()
    {
        self::$header = array();
    }
    
    /**
     * 打包http协议，用于返回数据给nginx
     * 
     * @param string $data
     * @return string
     */
    public static function encode($data)
    {
        // header
        $header = "Server: PHPServer/1.0\r\nContent-Length: ".strlen($data)."\r\n";
        
        // 没有Content-Type默认给个
        if(!isset(self::$header['Content-Type']))
        {
            $header = "Content-Type: text/html;charset=utf-8\r\n".$header;
        }
        
        // 没有http-code默认给个
        if(!isset(self::$header['Http-Code']))
        {
            $header = "HTTP/1.1 200 OK\r\n".$header;
        }
        else
        {
            $header = self::$header['Http-Code']."\r\n".$header;
            unset(self::$header['Http-Code']);
        }
        
        // 其它header
        foreach(self::$header as $content)
        {
            $header .= $content."\r\n";
        }
        
        $header .= "\r\n";
        
        self::clear();
        
        // 整个http包
        return $header.$data;
    }
}