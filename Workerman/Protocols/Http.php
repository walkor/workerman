<?php 
namespace  Workerman\Protocols;

use Workerman\Connection\ConnectionInterface;

/**
 * http protocol
 * @author walkor<walkor@workerman.net>
 */
class Http implements \Workerman\Protocols\ProtocolInterface
{
    public static function input($recv_buffer, ConnectionInterface $connection)
    {
        if(!strpos($recv_buffer, "\r\n\r\n"))
        {
            return 0;
        }
        
        list($header, $body) = explode("\r\n\r\n", $recv_buffer, 2);
        if(0 === strpos($recv_buffer, "POST"))
        {
            // find Content-Length
            $match = array();
            if(preg_match("/\r\nContent-Length: ?(\d*)\r\n/", $header, $match))
            {
                $content_lenght = $match[1];
            }
            else
            {
                return 0;
            }
            if($content_lenght <= strlen($body))
            {
                return strlen($header)+4+$content_lenght;
            }
            return 0;
        }
        else
        {
            return strlen($header)+4;
        }
        return;
    }
    
    public static function decode($recv_buffer, ConnectionInterface $connection)
    {
        // 初始化
        $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION =  array();
        $GLOBALS['HTTP_RAW_POST_DATA'] = '';
        // 清空上次的数据
        HttpCache::$header = array();
        HttpCache::$instance = new HttpCache();
        // 需要设置的变量名
        $_SERVER = array (
              'QUERY_STRING' => '',
              'REQUEST_METHOD' => '',
              'REQUEST_URI' => '',
              'SERVER_PROTOCOL' => '',
              'SERVER_SOFTWARE' => 'workerman/3.0',
              'SERVER_NAME' => '', 
              'HTTP_HOST' => '',
              'HTTP_USER_AGENT' => '',
              'HTTP_ACCEPT' => '',
              'HTTP_ACCEPT_LANGUAGE' => '',
              'HTTP_ACCEPT_ENCODING' => '',
              'HTTP_COOKIE' => '',
              'HTTP_CONNECTION' => '',
              'REQUEST_TIME' => 0,
              'REMOTE_ADDR' => '',
              'REMOTE_PORT' => '0',
           );
        
        // 将header分割成数组
        $header_data = explode("\r\n", $recv_buffer);
        
        list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header_data[0]);
        // 需要解析$_POST
        if($_SERVER['REQUEST_METHOD'] == 'POST')
        {
            $tmp = explode("\r\n\r\n", $recv_buffer, 2);
            parse_str($tmp[1], $_POST);
        
            // $GLOBALS['HTTP_RAW_POST_DATA']
            $GLOBALS['HTTP_RAW_POST_DATA'] = $tmp[1];
            unset($header_data[count($header_data) - 1]);
        }
        
        unset($header_data[0]);
        foreach($header_data as $content)
        {
            // \r\n\r\n
            if(empty($content))
            {
                continue;
            }
            list($key, $value) = explode(':', $content, 2);
            $key = strtolower($key);
            $value = trim($value);
            switch($key)
            {
                // HTTP_HOST
                case 'host':
                    $_SERVER['HTTP_HOST'] = $value;
                    $tmp = explode(':', $value);
                    $_SERVER['SERVER_NAME'] = $tmp[0];
                    if(isset($tmp[1]))
                    {
                        $_SERVER['SERVER_PORT'] = (int)$tmp[1];
                    }
                    break;
                // cookie
                case 'cookie':
                    {
                        $_SERVER['HTTP_COOKIE'] = $value;
                        parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
                    }
                    break;
                // user-agent
                case 'user-agent':
                    $_SERVER['HTTP_USER_AGENT'] = $value;
                    break;
                // accept
                case 'accept':
                    $_SERVER['HTTP_ACCEPT'] = $value;
                    break;
                // accept-language
                case 'accept-language':
                    $_SERVER['HTTP_ACCEPT_LANGUAGE'] = $value;
                    break;
                // accept-encoding
                case 'accept-encoding':
                    $_SERVER['HTTP_ACCEPT_ENCODING'] = $value;
                    break;
                // connection
                case 'connection':
                    $_SERVER['HTTP_CONNECTION'] = $value;
                    if(strtolower($value) === 'keep-alive')
                    {
                        HttpCache::$header['Connection'] = 'Connection: Keep-Alive';
                    }
                    else
                    {
                        HttpCache::$header['Connection'] = 'Connection: Closed';
                    }
                    break;
                case 'referer':
                    $_SERVER['HTTP_REFERER'] = $value;
                    break;
                case 'if-modified-since':
                    $_SERVER['HTTP_IF_MODIFIED_SINCE'] = $value;
                    break;
                case 'if-none-match':
                    $_SERVER['HTTP_IF_NONE_MATCH'] = $value;
                    break;
            }
        }
        
        // 'REQUEST_TIME_FLOAT' => 1375774613.237,
        $_SERVER['REQUEST_TIME_FLOAT'] = microtime(true);
        $_SERVER['REQUEST_TIME'] = intval($_SERVER['REQUEST_TIME_FLOAT']);
        
        // QUERY_STRING
        $_SERVER['QUERY_STRING'] = parse_url($_SERVER['REQUEST_URI'], PHP_URL_QUERY);
        
        // GET
        parse_str($_SERVER['QUERY_STRING'], $_GET);
        
        // REQUEST
        $_REQUEST = array_merge($_GET, $_POST);
        
        // REMOTE_ADDR REMOTE_PORT
        $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
        $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
    }
    
    public static function encode($content, ConnectionInterface $connection)
    {
        // 没有http-code默认给个
        if(!isset(HttpCache::$header['Http-Code']))
        {
            $header = "HTTP/1.1 200 OK\r\n";
        }
        else
        {
            $header = HttpCache::$header['Http-Code']."\r\n";
            unset(HttpCache::$header['Http-Code']);
        }
        
        // Content-Type
        if(!isset(HttpCache::$header['Content-Type']))
        {
            $header .= "Content-Type: text/html;charset=utf-8\r\n";
        }
        
        // other headers
        foreach(HttpCache::$header as $key=>$item)
        {
            if('Set-Cookie' == $key && is_array($item))
            {
                foreach($item as $it)
                {
                    $header .= $it."\r\n";
                }
            }
            else
            {
                $header .= $item."\r\n";
            }
        }
         
        // header
        $header .= "Server: WorkerMan/3.0\r\nContent-Length: ".strlen($content)."\r\n\r\n";
        
        // save session
        self::sessionWriteClose();
        
        // the whole http package
        return $header.$content;
    }
    
    /**
     * 设置http头
     * @return bool
     */
    public static function header($content, $replace = true, $http_response_code = 0)
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
    
        if('location' == strtolower($key) && !$http_response_code)
        {
            return header($content, true, 302);
        }
    
        if(isset(HttpCache::$codes[$http_response_code]))
        {
            HttpCache::$header['Http-Code'] = "HTTP/1.1 $http_response_code " .  HttpCache::$codes[$http_response_code];
            if($key == 'Http-Code')
            {
                return true;
            }
        }
    
        if($key == 'Set-Cookie')
        {
            HttpCache::$header[$key][] = $content;
        }
        else
        {
            HttpCache::$header[$key] = $content;
        }
    
        return true;
    }
    
    /**
     * 删除一个header
     * @param string $name
     * @return void
     */
    public static function headerRemove($name)
    {
        unset( HttpCache::$header[$name]);
    }
    
    /**
     * 设置cookie
     * @param string $name
     * @param string $value
     * @param integer $maxage
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $HTTPOnly
     */
    public static function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
        header(
                'Set-Cookie: ' . $name . '=' . rawurlencode($value)
                . (empty($domain) ? '' : '; Domain=' . $domain)
                . (empty($maxage) ? '' : '; Max-Age=' . $maxage)
                . (empty($path) ? '' : '; Path=' . $path)
                . (!$secure ? '' : '; Secure')
                . (!$HTTPOnly ? '' : '; HttpOnly'), false);
    }
    
    /**
     * sessionStart
     *
     */
    public static function sessionStart()
    {
        if(HttpCache::$instance->sessionStarted)
        {
            echo "already sessionStarted\nn";
            return true;
        }
        HttpCache::$instance->sessionStarted = true;
        // 没有sid，则创建一个session文件，生成一个sid
        if(!isset($_COOKIE[HttpCache::$sessionName]) || !is_file(HttpCache::$sessionPath . '/sess_' . $_COOKIE[HttpCache::$sessionName]))
        {
            $file_name = tempnam(HttpCache::$sessionPath, 'sess_');
            if(!$file_name)
            {
                return false;
            }
            HttpCache::$instance->sessionFile = $file_name;
            $session_id = substr(basename($file_name), strlen('sess_'));
            return setcookie(
                    HttpCache::$sessionName
                    , $session_id
                    , ini_get('session.cookie_lifetime')
                    , ini_get('session.cookie_path')
                    , ini_get('session.cookie_domain')
                    , ini_get('session.cookie_secure')
                    , ini_get('session.cookie_httponly')
            );
        }
        if(!HttpCache::$instance->sessionFile)
        {
            HttpCache::$instance->sessionFile = HttpCache::$sessionPath . '/sess_' . $_COOKIE[HttpCache::$sessionName];
        }
        // 有sid则打开文件，读取session值
        if(HttpCache::$instance->sessionFile)
        {
            $raw = file_get_contents(HttpCache::$instance->sessionFile);
            if($raw)
            {
                session_decode($raw);
            }
        }
    }
    
    /**
     * 保存session
     */
    public static function sessionWriteClose()
    {
        if(!empty(HttpCache::$instance->sessionStarted) && !empty($_SESSION))
        {
            $session_str = session_encode();
            if($session_str && HttpCache::$instance->sessionFile)
            {
                return file_put_contents(HttpCache::$instance->sessionFile, $session_str);
            }
        }
        return empty($_SESSION);
    }
    
    /**
     * 退出
     * @param string $msg
     * @throws \Exception
     */
    public static function end($msg = '')
    {
        if($msg)
        {
            echo $msg;
        }
        throw new \Exception('jump_exit');
    }
    
    /**
     * get mime types
     */
    public static function getMimeTypesFile()
    {
        return __DIR__.'/Http/mime.types';
    }
}

/**
 * 解析http协议数据包 缓存先关
 * @author walkor
 */
class HttpCache
{
    public static $codes = array(
            100 => 'Continue',
            101 => 'Switching Protocols',
            200 => 'OK',
            201 => 'Created',
            202 => 'Accepted',
            203 => 'Non-Authoritative Information',
            204 => 'No Content',
            205 => 'Reset Content',
            206 => 'Partial Content',
            300 => 'Multiple Choices',
            301 => 'Moved Permanently',
            302 => 'Found',
            303 => 'See Other',
            304 => 'Not Modified',
            305 => 'Use Proxy',
            306 => '(Unused)',
            307 => 'Temporary Redirect',
            400 => 'Bad Request',
            401 => 'Unauthorized',
            402 => 'Payment Required',
            403 => 'Forbidden',
            404 => 'Not Found',
            405 => 'Method Not Allowed',
            406 => 'Not Acceptable',
            407 => 'Proxy Authentication Required',
            408 => 'Request Timeout',
            409 => 'Conflict',
            410 => 'Gone',
            411 => 'Length Required',
            412 => 'Precondition Failed',
            413 => 'Request Entity Too Large',
            414 => 'Request-URI Too Long',
            415 => 'Unsupported Media Type',
            416 => 'Requested Range Not Satisfiable',
            417 => 'Expectation Failed',
            422 => 'Unprocessable Entity',
            423 => 'Locked',
            500 => 'Internal Server Error',
            501 => 'Not Implemented',
            502 => 'Bad Gateway',
            503 => 'Service Unavailable',
            504 => 'Gateway Timeout',
            505 => 'HTTP Version Not Supported',
      );
    public static $instance = null;
    public static $header = array();
    public static $sessionPath = '';
    public static $sessionName = '';
    public $sessionStarted = false;
    public $sessionFile = '';

    public static function init()
    {
        self::$sessionName = ini_get('session.name');
        self::$sessionPath = session_save_path();
        if(!self::$sessionPath)
        {
            self::$sessionPath = sys_get_temp_dir();
        }
        @\session_start();
    }
}
