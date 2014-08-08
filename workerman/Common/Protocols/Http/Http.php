<?php 
namespace  Man\Common\Protocols\Http;

/**
 * 判断http协议的数据包是否完整
 * @param string $http_string
 * @return integer 0表示完整 否则还需要integer长度的数据
 */
function http_input($http_string)
{
    // 查找\r\n\r\n
    $data_length = strlen($http_string);
    
    if(!strpos($http_string, "\r\n\r\n"))
    {
        return 1;
    }
    
    // POST请求还要读包体
    if(strpos($http_string, "POST"))
    {
        // 找Content-Length
        $match = array();
        if(preg_match("/\r\nContent-Length: ?(\d*)\r\n/", $http_string, $match))
        {
            $content_lenght = $match[1];
        }
        else
        {
            return 0;
        }
        // 看包体长度是否符合
        $tmp = explode("\r\n\r\n", $http_string, 2);
        $remain_length = $content_lenght - strlen($tmp[1]);
        return $remain_length >= 0 ? $remain_length : 0;
    }
    
    return 0;
}

/**
 * 解析http协议，设置$_POST  $_GET  $_COOKIE  $_REQUEST
 * @param string $http_string
 */
function http_start($http_string, $SERVER = array())
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
          'SERVER_PROTOCOL' => 'HTTP/1.1',
          'GATEWAY_INTERFACE' => 'CGI/1.1',
          'SERVER_SOFTWARE' => 'workerman/2.1',
          'SERVER_NAME' => '', 
          'HTTP_HOST' => '',
          'HTTP_USER_AGENT' => '',
          'HTTP_ACCEPT' => '',
          'HTTP_ACCEPT_LANGUAGE' => '',
          'HTTP_ACCEPT_ENCODING' => '',
          'HTTP_COOKIE' => '',
          'HTTP_CONNECTION' => '',
          'REQUEST_TIME' => 0,
          'SCRIPT_NAME' => '',//$SERVER传递
          'REMOTE_ADDR' => '',// $SERVER传递
          'REMOTE_PORT' => '0',// $SERVER传递
          'SERVER_ADDR' => '', // $SERVER传递
          'DOCUMENT_ROOT' => '',//$SERVER传递
          'SCRIPT_FILENAME' => '',// $SERVER传递
          'SERVER_PORT' => '80',
          'PHP_SELF' => '', // 设置成SCRIPT_NAME
       );
    
    // 将header分割成数组
    $header_data = explode("\r\n", $http_string);
    
    list($_SERVER['REQUEST_METHOD'], $_SERVER['REQUEST_URI'], $_SERVER['SERVER_PROTOCOL']) = explode(' ', $header_data[0]);
    // 需要解析$_POST
    if($_SERVER['REQUEST_METHOD'] == 'POST')
    {
        $tmp = explode("\r\n\r\n", $http_string, 2);
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
                    $_SERVER['SERVER_PORT'] = $tmp[1];
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
    
    // 合并传递的值
    $_SERVER = array_merge($_SERVER, $SERVER);
    
    // PHP_SELF
    if($_SERVER['SCRIPT_NAME'] && !$_SERVER['PHP_SELF'])
    {
        $_SERVER['PHP_SELF'] = $_SERVER['SCRIPT_NAME'];
    }
}

function http_end($content)
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
    
    // 没有Content-Type默认给个
    if(!isset(HttpCache::$header['Content-Type']))
    {
        $header .= "Content-Type: text/html;charset=utf-8\r\n";
    }
    
    // 其它header
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
    $header .= "Server: WorkerMan/2.1\r\nContent-Length: ".strlen($content)."\r\n";
    
    $header .= "\r\n";
    
    HttpCache::$header = array();
    
    // 保存cookie
    session_write_close();
    $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = array();
    $GLOBALS['HTTP_RAW_POST_DATA'] = '';
    HttpCache::$instance = null;
    
    // 整个http包
    return $header.$content;
}

/**
 * 设置http头
 * @return bool
 */
function header($content, $replace = true, $http_response_code = 0)
{
    if(!defined('WORKERMAN_ROOT_DIR'))
    {
        if($http_response_code != 0)
        {
            return \header($content, $replace, $http_response_code);
        }
        else 
        {
            return \header($content, $replace);
        }
    }
    
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
function header_remove($name)
{
    if(!defined('WORKERMAN_ROOT_DIR'))
    {
        return \header_remove($name);
    }
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
function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
    if(!defined('WORKERMAN_ROOT_DIR'))
    {
        return \setcookie($name, $value, $maxage, $path, $domain, $secure, $HTTPOnly);
    }
    header(
            'Set-Cookie: ' . $name . '=' . rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . (empty($maxage) ? '' : '; Max-Age=' . $maxage)
            . (empty($path) ? '' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$HTTPOnly ? '' : '; HttpOnly'), false);
}

/**
 * session_start
 * 
 */
function session_start()
{
    if(!defined('WORKERMAN_ROOT_DIR'))
    {
        return \session_start();
    }
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
function session_write_close()
{
    if(!defined('WORKERMAN_ROOT_DIR'))
    {
        return \session_write_close();
    }
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
function jump_exit($msg = '')
{
    if(!defined('WORKERMAN_ROOT_DIR'))
    {
        return exit($msg);
    }
    if($msg)
    {
        echo $msg;
    }
    throw new \Exception('jump_exit');
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
