<?php 
namespace  App\Common\Protocols;

class HttpCache 
{
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
    }


}

/**
 * 设置http头
 * @return bool
 */
function header($content)
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
    HttpCache::$header[$key] = $content;
    if('location' == strtolower($key))
    {
        header("HTTP/1.1 302 Moved Temporarily");
    }
    return true;
}

function http_deal_input($data)
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
    
    return 0;
}

function http_response_begin()
{
    $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = $GLOBALS['HTTP_RAW_POST_DATA'] = array();
    HttpCache::$header = array();
    HttpCache::$instance = new HttpCache();
}

function http_requset_parse($data)
{
    $_SERVER = array(
            'REQUEST_URI'    => '/',
            'HTTP_HOST'      => '127.0.0.1',
            'HTTP_COOKIE'    => '',
    );
    
    
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
        elseif(stripos($content, 'Cookie') === 0)
        {
            $_SERVER['HTTP_COOKIE'] = trim(substr($content, strlen('Cookie:')));
            if($_SERVER['HTTP_COOKIE'])
            {
                parse_str(str_replace('; ', '&', $_SERVER['HTTP_COOKIE']), $_COOKIE);
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

function http_encode($content)
{
    // 没有http-code默认给个
    if(!isset(HttpCache::$header['Http-Code']))
    {
        $header = "HTTP/1.1 200 OK\r\n";
    }
    else
    {
        $header = HttpCache::$header['Http-Code']."\r\n";
        unset(Header::$header['Http-Code']);
    }

    // 没有Content-Type默认给个
    if(!isset(HttpCache::$header['Content-Type']))
    {
        $header .= "Content-Type: text/html;charset=utf-8\r\n";
    }

    // 其它header
    foreach(HttpCache::$header as $item)
    {
        $header .= $item."\r\n";
    }
   
    // header
    $header .= "Server: WorkerMan/2.1\r\nContent-Length: ".strlen($content)."\r\n";
 
    $header .= "\r\n";
    
    HttpCache::$header = array();
    
    // 整个http包
    return $header.$content;
}

function http_response_finish()
{
    // 保存cookie
    session_write_close(); 
    $_POST = $_GET = $_COOKIE = $_REQUEST = $_SESSION = array();
    $GLOBALS['HTTP_RAW_POST_DATA'] = '';
    HttpCache::$instance = null;
}

function setcookie($name, $value = '', $maxage = 0, $path = '', $domain = '', $secure = false, $HTTPOnly = false) {
    header(
            'Set-Cookie: ' . $name . '=' . rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . (empty($maxage) ? '' : '; Max-Age=' . $maxage)
            . (empty($path) ? '' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$HTTPOnly ? '' : '; HttpOnly'), false);
}


/**
 * http session 相关
 * @author walkor <worker-man@qq.com>
 * */

function session_start()
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
            $_SESSION = session_unserialize($raw);
        }
    }
}

function session_unserialize($raw) {
    $return_data = array();
    $offset     = 0;

    while ($offset < strlen($raw)) {
        if (!strstr(substr($raw, $offset), "|")) {
            return false;
        }

        $pos     = strpos($raw, "|", $offset);
        $num     = $pos - $offset;
        $varname = substr($raw, $offset, $num);
        $offset += $num + 1;
        $data    = unserialize(substr($raw, $offset));

        $return_data[$varname] = $data;
        $offset += strlen(serialize($data));
    }

    return $return_data;
}

function session_serialize($session)
{ 
  $session_str = '';
  if(is_array($session))
  {
    foreach($session as $key => $value)
    {
        $session_str .= "$key|".serialize($value);
    }
  }
  return $session_str;
}

function session_write_close()
{
    if(HttpCache::$instance->sessionStarted && !empty($_SESSION))
    {
       $session_str = session_serialize($_SESSION);
       if($session_str && HttpCache::$instance->sessionFile)
       {
           return file_put_contents(HttpCache::$instance->sessionFile, $session_str);
       }
    }
    return empty($_SESSION);
}
