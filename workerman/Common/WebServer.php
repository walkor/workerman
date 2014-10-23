<?php
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'Common/Protocols/Http/Http.php';

/**
 * 
 *  WebServer
 *  HTTP协议
 *  
 * @author walkor <walkor@workerman.net>
 */
class WebServer extends Man\Core\SocketWorker
{
    /**
     * 缓存最多多少静态文件
     * @var integer
     */
    const MAX_CACHE_FILE_COUNT = 100;
    
    /**
     * 大于这个值则文件不缓存
     * @var integer
     */
    const MAX_CACHE_FILE_SIZE = 300000;
    
    /**
     * 缓存静态文件内容
     * @var array
     */
    public static $fileCache = array();
    
    /**
     * 默认mime类型
     * @var string
     */
    protected static $defaultMimeType = 'text/html; charset=utf-8';
    
    /**
     * 服务器名到文件路径的转换
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected static $serverRoot = array();
    
    /**
     * 默认访问日志目录
     * @var string
     */
    protected static $defaultAccessLog = './logs/access.log';
    
    /**
     * 访问日志存储路径
     * @var array
     */
    protected static $accessLog = array();
    
    /**
     * mime类型映射关系
     * @var array
     */
    protected static $mimeTypeMap = array();
    
    
    /**
     * 进程启动的时候一些初始化工作
     * @see Man\Core.SocketWorker::onStart()
     */
    public function onStart()
    {
        // 初始化HttpCache
        Man\Common\Protocols\Http\HttpCache::init();
        // 初始化mimeMap
        $this->initMimeTypeMap();
        // 初始化ServerRoot
        $this->initServerRoot();
        // 初始化访问路径
        $this->initAccessLog();
    }
    
    /**
     * 初始化mimeType
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = \Man\Core\Lib\Config::get($this->workerName.'.include');
        if(!is_file($mime_file))
        {
            $this->notice("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if(!is_array($items))
        {
            $this->notice("get $mime_file mime.type content fail");
            return;
        }
        foreach($items as $content)
        {
            if(preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match))
            {
                $mime_type = $match[1];
                $extension_var = $match[2];
                $extension_array = explode(' ', substr($extension_var, 0, -1));
                foreach($extension_array as $extension)
                {
                    self::$mimeTypeMap[$extension] = $mime_type;
                } 
            }
        }
    }
    
    /**
     * 初始化ServerRoot
     * @return void
     */
    public  function initServerRoot()
    {
        self::$serverRoot = \Man\Core\Lib\Config::get($this->workerName.'.root');
    }
    
    /**
     * 初始化AccessLog
     * @return void 
     */
    public function initAccessLog()
    {
        // 虚拟机访问日志目录
        self::$accessLog = \Man\Core\Lib\Config::get($this->workerName.'.access_log');
        // 默认访问日志目录
        if($default_access_log =  \Man\Core\Lib\Config::get($this->workerName.'.default_access_log'))
        {
            self::$defaultAccessLog = $default_access_log;
        }
    }

    /**
     * 确定数据是否接收完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_buffer)
    {
        return Man\Common\Protocols\Http\http_input($recv_buffer);
    }

    /**
     * 数据接收完整后处理业务逻辑
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_buffer)
    {
         // http请求处理开始。解析http协议，生成$_POST $_GET $_COOKIE
        Man\Common\Protocols\Http\http_start($recv_buffer);
        
        // 记录访问日志
        $this->logAccess($recv_buffer);
        
        // 请求的文件
        $url_info = parse_url($_SERVER['REQUEST_URI']);
        if(!$url_info)
        {
            Man\Common\Protocols\Http\header('HTTP/1.1 400 Bad Request');
            return $this->sendToClient(Man\Common\Protocols\Http\http_end('<h1>400 Bad Request</h1>'));
        }
        
        $path = $url_info['path'];
        
        $path_info = pathinfo($path);
        $extension = isset($path_info['extension']) ? $path_info['extension'] : '' ;
        if($extension == '')
        {
            $path = ($len = strlen($path)) && $path[$len -1] == '/' ? $path.'index.php' : $path . '/index.php';
            $extension = 'php';
        }
        
        // 命中缓存，直接返回
        if(isset(self::$fileCache[$path]) )
        {
                $file_content = self::$fileCache[$path];
                // 发送给客户端
                return $this->sendToClient(Man\Common\Protocols\Http\http_end($file_content));
        }
        
        $root_dir = isset(self::$serverRoot[$_SERVER['HTTP_HOST']]) ? self::$serverRoot[$_SERVER['HTTP_HOST']] : current(self::$serverRoot);
        
        $file = "$root_dir/$path";
        
        // 对应的php文件不存在则直接使用根目录的index.php
        if($extension == 'php' && !is_file($file))
        {
            $file = "$root_dir/index.php";
        }
        
        // 请求的文件存在
        if(is_file($file))
        {
            // 判断是否是站点目录里的文件
            if((!($request_realpath = realpath($file)) || !($root_dir_realpath = realpath($root_dir))) || 0 !== strpos($request_realpath, $root_dir_realpath))
            {
                Man\Common\Protocols\Http\header('HTTP/1.1 400 Bad Request');
                return $this->sendToClient(Man\Common\Protocols\Http\http_end('<h1>400 Bad Request</h1>'));
            }
            
            $file = realpath($file);
            
            // 如果请求的是php文件
            if($extension == 'php')
            {
                $cwd = getcwd();
                chdir($root_dir);
                ini_set('display_errors', 'off');
                // 缓冲输出
                ob_start();
                // 载入php文件
                try 
                {
                    // $_SERVER变量
                    $_SERVER['SCRIPT_NAME'] = $path;
                    $_SERVER['REMOTE_ADDR'] = $this->getRemoteIp();
                    $_SERVER['REMOTE_PORT'] = $this->getRemotePort();
                    $_SERVER['SERVER_ADDR'] = $this->getLocalIp();
                    $_SERVER['DOCUMENT_ROOT'] = $root_dir;
                    $_SERVER['SCRIPT_FILENAME'] = $file;
                    include $file;
                }
                catch(\Exception $e) 
                {
                    // 如果不是exit
                    if($e->getMessage() != 'jump_exit')
                    {
                        echo $e;
                    }
                }
                $content = ob_get_clean();
                ini_set('display_errors', 'on');
                $buffer = Man\Common\Protocols\Http\http_end($content);
                $this->sendToClient($buffer);
                // 执行php每执行一次就退出(原因是有的业务使用了require_once类似的语句，不能重复加载业务逻辑)
                //return $this->stop();
                chdir($cwd);
                return ;
            }
            
            // 请求的是静态资源文件
            if(isset(self::$mimeTypeMap[$extension]))
            {
                Man\Common\Protocols\Http\header('Content-Type: '. self::$mimeTypeMap[$extension]);
            }
            else 
            {
                Man\Common\Protocols\Http\header('Content-Type: '. self::$defaultMimeType);
            }
            
            // 获取文件信息
            $info = stat($file);
            
            $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' GMT' : '';
            
            // 如果有$_SERVER['HTTP_IF_MODIFIED_SINCE']
            if(!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info)
            {
                // 文件没有更改则直接304
                if($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE'])
                {
                    // 304
                    Man\Common\Protocols\Http\header('HTTP/1.1 304 Not Modified');
                    // 发送给客户端
                    return $this->sendToClient(Man\Common\Protocols\Http\http_end(''));
                }
            }
            
            if(!isset(self::$fileCache[$file]) )
            {
                $file_content = file_get_contents($file);
                // 缓存文件
                if($info['size'] < self::MAX_CACHE_FILE_SIZE && $file_content)
                {
                    self::$fileCache[$file] = $file_content;
                    // 缓存满了删除一个文件
                    if(count(self::$fileCache) > self::MAX_CACHE_FILE_COUNT)
                    {
                        // 删除第一个缓存的文件
                        reset(self::$fileCache);
                        unset(self::$fileCache[key(self::$fileCache)]);
                    }
                }
            }
            else
            {
                $file_content = self::$fileCache[$file];
            }
            
            if($modified_time)
            {
                Man\Common\Protocols\Http\header("Last-Modified: $modified_time");
            }
            // 发送给客户端
           return $this->sendToClient(Man\Common\Protocols\Http\http_end($file_content));
        }
        else 
        {
            // 404
            Man\Common\Protocols\Http\header("HTTP/1.1 404 Not Found");
            return $this->sendToClient(Man\Common\Protocols\Http\http_end('<html><head><title>页面不存在</title></head><body><center><h3>页面不存在</h3></center></body></html>'));
        }
    }
    
    /**
     * 记录访问日志
     * @param unknown_type $recv_buffer
     */
    public function logAccess($recv_buffer)
    {
        // 记录访问日志
        $log_data = date('Y-m-d H:i:s') . "\t REMOTE:" . $this->getRemoteAddress()."\n$recv_buffer";
        if(isset(self::$accessLog[$_SERVER['HTTP_HOST']]))
        {
            file_put_contents(self::$accessLog[$_SERVER['HTTP_HOST']], $log_data, FILE_APPEND);
        }
        else
        {
            file_put_contents(self::$defaultAccessLog, $log_data, FILE_APPEND);
        }
    }
}
