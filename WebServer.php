<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman;

use Jasny\HttpMessage\ServerRequest;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Swoole\Server;
use Workerman\Protocols\Http;
use Workerman\Protocols\HttpCache;

/**
 *  WebServer.
 */
class WebServer extends Worker
{
    /**
     * Null when workerman use its built in web server, else it will contains a swoole server instance
     *
     * @var \Swoole\Server
     */
    public $service = null;
    /**
     * When set true, workerman will use swoole built-in web server
     *
     * @var null
     */
    public $useSwooleWebServer = false;
    /**
     * Virtual host to path mapping.
     *
     * @var array ['workerman.net'=>'/home', 'www.workerman.net'=>'home/www']
     */
    protected $serverRoot = array();

    /**
     * Mime mapping.
     *
     * @var array
     */
    protected static $mimeTypeMap = array();


    /**
     * Used to save user OnWorkerStart callback settings.
     *
     * @var callback
     */
    protected $_onWorkerStart = null;

    /**
     * Add virtual host.
     *
     * @param string $domain
     * @param string $config
     * @return void
     */
    public function addRoot($domain, $config)
    {
	if (is_string($config)) {
            $config = array('root' => $config);
	}
        $this->serverRoot[$domain] = $config;
    }

    /**
     * Construct.
     *
     * @param string $socket_name
     * @param array  $context_option
     */
    public function __construct($socket_name, $context_option = array(), $callback = null, $useSwoole = null)
    {
        $this->externalCallback = $callback;
        $this->useSwooleWebServer = $useSwoole === null ? (extension_loaded("swoole")) : $useSwoole;
        if (extension_loaded("swoole") && $this->useSwooleWebServer) {
            $this->workerId                    = spl_object_hash($this);
            static::$_workers[$this->workerId] = $this;
            static::$_pidMap[$this->workerId]  = array();
            $this->status = '<g> [OK] </g>';
            $this->user = get_current_user();
            $this->_socketName = $socket_name;
            $this->name  = "SwooleWeb";
            $this->socket = $socket_name;
            $this->count = 4;
            $this->contextOptions = $context_option;
            parent::__construct($socket_name, array(),$this->useSwooleWebServer);
        } else {
            list(, $address) = explode(':', $socket_name, 2);
            parent::__construct('http:' . $address, $context_option);
        }
    }

    /**
     * @throws \Exception
     */
    public function listen()
    {
        if(!$this->useSwooleWebServer) {
            $this->name = "WorkermanWeb";
            return parent::listen();
        } else {
            $pid = pcntl_fork();
            if ($pid == -1) {
                die('could not fork');
            }
            // if we are the child
            if (!$pid) {
                $this->initSwoole();
            }
        }
    }

    public function initSwoole()  {

        $this->initMimeTypeMap();
        $ip = explode(":", $this->_socketName);
        $port = $ip[2];
        $ip = $ip[count($ip) - 2];
        $ip = explode("//", $ip);
        $ip = $ip[1];
        $this->service = new \Swoole\Http\Server($ip, intval($port));
        $self = $this;
        $this->service->on('request', function (\Swoole\Http\Request $request, \Swoole\Http\Response &$response) use ($self) {
            $extension = explode(".", $request->server["request_uri"]);
            $extension = $extension[count($extension) - 1];
            if ($self->getStaticFile($request, $response, WebServer::$mimeTypeMap)) {
                return;
            }
            $response->status(404);
            if (!is_null($self->externalCallback)) {
                $request = $self->convertSwooleToPsrRequest($request);
                $func = $self->externalCallback;
                $newResponse = call_user_func($func, $request);
                $self->replyUsingSwooleResponse($response, $newResponse);
            } else {
                $html404 = '<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>';
                $response->end($html404);
                return;
            }
        });
        $options = array();
        $swooleOptionsToWorkerman = array(
            'ssl_cert_file' => 'local_cert',
            'ssl_key_file' => 'local_pk',
        );
        foreach ($swooleOptionsToWorkerman as $k => $v) {
            if (isset($this->contextOptions[$v])) {
                $options[$k] = $this->contextOptions[$v];
            }
        }
        $options["worker_num"] = $this->count;
        if (!empty($options)) {
            $this->service->set($options);
        }
        $this->service->start();
    }

    /**
     * @param \Swoole\Http\Request $request
     * @return RequestInterface
     */
    public function convertSwooleToPsrRequest($request) {
        //  return new R
        $_SERVER = $GLOBALS["_SERVER"] = is_null($request->server) ? array() : $request->server;
        $_REQUEST = $GLOBALS["_REQUEST"] = is_null($request->request) ?  array() : $request->request;
        $_COOKIE = $GLOBALS["_COOKIE"] = is_null($request->cookie) ?  array() : $request->cookie;
        $_GET = $GLOBALS["_GET"] = is_null($request->get) ? array() : $request->get;
        $_FILES = $GLOBALS["_FILES"] = is_null($request->files) ?  array() : $request->files;
        $_POST = $GLOBALS["_POST"] = is_null($request->post) ?  array() : $request->post;
        $serverRequest = (new ServerRequest());
        $request = $serverRequest->withGlobalEnvironment(true);
        return $request;
    }

    /**
     * @return RequestInterface
     */
    public function convertWorkermanToPsrRequest() {
        $serverRequest = (new ServerRequest());
        $request = $serverRequest->withGlobalEnvironment(true);
        return $request;
    }

    /**
     * @param \Swoole\Http\Response $swooleResponse
     * @param ResponseInterface $psrResponse
     * @return void
     */
    public function replyUsingSwooleResponse(&$swooleResponse, $psrResponse) {
        foreach ($psrResponse->getHeaders() as $key => $header) {
            $swooleResponse->header($key, join(",", $header));
        }
        $swooleResponse->end($psrResponse->getBody()->getContents());
    }

    /**
     * @param Connection\ConnectionInterface $connection
     * @param ResponseInterface $psrResponse
     * @return void
     */
    public function replyUsingWorkermanResponse($psrResponse) {
        foreach ($psrResponse->getHeaders() as $key => $header) {
            Http::header($key . ": " . join(",", $header));
        }
        return $psrResponse->getBody()->getContents();
    }

    /**
     * Will send a static file
     * @param \Swoole\Http\Request $request
     * @param \Swoole\Http\Response $response
     * @param array $static
     * @return bool
     */
    public function getStaticFile( $request,  &$response, $static){
        foreach ($this->serverRoot as $domain => $path) {
            $path = $path["root"];
            if ($domain !== $request->server['remote_addr']) {
                break;
            }
            $staticFile = $path . ($request->server['request_uri'] === "/" ? "/index.html" : $request->server['request_uri']) ;
            if (! file_exists($staticFile)) {
                return false;
            }
            $type = pathinfo($staticFile, PATHINFO_EXTENSION);
            if (! isset($static[$type])) {
                return false;
            }
            $response->header('Content-Type', $static[$type]);
            $response->sendfile($staticFile);
            return true;
        }
        return false;
    }

    /**
     * Run webserver instance.
     *
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart  = array($this, 'onWorkerStart');
        $this->onMessage      = array($this, 'onMessage');
        parent::run();
    }

    /**
     * Emit when process start.
     *
     * @throws \Exception
     */
    public function onWorkerStart()
    {
        if (empty($this->serverRoot)) {
            Worker::safeEcho(new \Exception('server root not set, please use WebServer::addRoot($domain, $root_path) to set server root path'));
            exit(250);
        }

        // Init mimeMap.
        $this->initMimeTypeMap();

        // Try to emit onWorkerStart callback.
        if ($this->_onWorkerStart) {
            try {
                call_user_func($this->_onWorkerStart, $this);
            } catch (\Exception $e) {
                self::log($e);
                exit(250);
            } catch (\Error $e) {
                self::log($e);
                exit(250);
            }
        }
    }

    /**
     * Init mime map.
     *
     * @return void
     */
    public function initMimeTypeMap()
    {
        $mime_file = Http::getMimeTypesFile();
        if (!is_file($mime_file)) {
            $this->log("$mime_file mime.type file not fond");
            return;
        }
        $items = file($mime_file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($items)) {
            $this->log("get $mime_file mime.type content fail");
            return;
        }
        foreach ($items as $content) {
            if (preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type                      = $match[1];
                $workerman_file_extension_var   = $match[2];
                $workerman_file_extension_array = explode(' ', substr($workerman_file_extension_var, 0, -1));
                foreach ($workerman_file_extension_array as $workerman_file_extension) {
                    self::$mimeTypeMap[$workerman_file_extension] = $mime_type;
                }
            }
        }
    }

    /**
     * Emit when http message coming.
     *
     * @param Connection\TcpConnection $connection
     * @return void
     */
    public function onMessage($connection)
    {
        // REQUEST_URI.
        $workerman_url_info = parse_url('http://'.$_SERVER['HTTP_HOST'].$_SERVER['REQUEST_URI']);
        if (!$workerman_url_info) {
            Http::header('HTTP/1.1 400 Bad Request');
            $connection->close('<h1>400 Bad Request</h1>');
            return;
        }

        $workerman_path = isset($workerman_url_info['path']) ? $workerman_url_info['path'] : '/';

        $workerman_path_info      = pathinfo($workerman_path);
        $workerman_file_extension = isset($workerman_path_info['extension']) ? $workerman_path_info['extension'] : '';
        if ($workerman_file_extension === '') {
            $workerman_path           = ($len = strlen($workerman_path)) && $workerman_path[$len - 1] === '/' ? $workerman_path . 'index.php' : $workerman_path . '/index.php';
            $workerman_file_extension = 'php';
        }

        $workerman_siteConfig = isset($this->serverRoot[$_SERVER['SERVER_NAME']]) ? $this->serverRoot[$_SERVER['SERVER_NAME']] : current($this->serverRoot);
		$workerman_root_dir = $workerman_siteConfig['root'];
        $workerman_file = "$workerman_root_dir/$workerman_path";
		if(isset($workerman_siteConfig['additionHeader'])){
			Http::header($workerman_siteConfig['additionHeader']);
		}
        if ($workerman_file_extension === 'php' && !is_file($workerman_file)) {
            $workerman_file = "$workerman_root_dir/index.php";
            if (!is_file($workerman_file)) {
                $workerman_file           = "$workerman_root_dir/index.html";
                $workerman_file_extension = 'html';
            }
        }

        // File exsits.
        if (is_file($workerman_file)) {
            // Security check.
            if ((!($workerman_request_realpath = realpath($workerman_file)) || !($workerman_root_dir_realpath = realpath($workerman_root_dir))) || 0 !== strpos($workerman_request_realpath,
                    $workerman_root_dir_realpath)
            ) {
                Http::header('HTTP/1.1 400 Bad Request');
                $connection->close('<h1>400 Bad Request</h1>');
                return;
            }

            $workerman_file = realpath($workerman_file);

            // Request php file.
            if ($workerman_file_extension === 'php') {
                $workerman_cwd = getcwd();
                chdir($workerman_root_dir);
                ini_set('display_errors', 'off');
                ob_start();
                // Try to include php file.
                try {
                    // $_SERVER.
                    $_SERVER['REMOTE_ADDR'] = $connection->getRemoteIp();
                    $_SERVER['REMOTE_PORT'] = $connection->getRemotePort();
                    include $workerman_file;
                } catch (\Exception $e) {
                    // Jump_exit?
                    if ($e->getMessage() != 'jump_exit') {
                        Worker::safeEcho($e);
                    }
                }
                $content = ob_get_clean();
                ini_set('display_errors', 'on');
                if (strtolower($_SERVER['HTTP_CONNECTION']) === "keep-alive") {
                    $connection->send($content);
                } else {
                    $connection->close($content);
                }
                chdir($workerman_cwd);
                return;
            }

            // Send file to client.
            return self::sendFile($connection, $workerman_file);
        } else {
            // 404
            if (($this->externalCallback !== null) && is_callable($this->externalCallback)) {
                $request = $this->convertWorkermanToPsrRequest();
                /**
                 * @var callable $func
                 */
                $func = $this->externalCallback;
                $response = $func($request);
                $connection->close($this->replyUsingWorkermanResponse($response));
                return;
            } else {
                Http::header("HTTP/1.1 404 Not Found");
                $html404 = '<html><head><title>404 File not found</title></head><body><center><h3>404 Not Found</h3></center></body></html>';
                $connection->close($html404);
                return;
            }
        }
    }

    public static function sendFile($connection, $file_path)
    {
        // Check 304.
        $info = stat($file_path);
        $modified_time = $info ? date('D, d M Y H:i:s', $info['mtime']) . ' ' . date_default_timezone_get() : '';
        if (!empty($_SERVER['HTTP_IF_MODIFIED_SINCE']) && $info) {
            // Http 304.
            if ($modified_time === $_SERVER['HTTP_IF_MODIFIED_SINCE']) {
                // 304
                Http::header('HTTP/1.1 304 Not Modified');
                // Send nothing but http headers..
                $connection->close('');
                return;
            }
        }

        // Http header.
        if ($modified_time) {
            $modified_time = "Last-Modified: $modified_time\r\n";
        }
        $file_size = filesize($file_path);
        $file_info = pathinfo($file_path);
        $extension = isset($file_info['extension']) ? $file_info['extension'] : '';
        $file_name = isset($file_info['filename']) ? $file_info['filename'] : '';
        $header = "HTTP/1.1 200 OK\r\n";
        if (isset(self::$mimeTypeMap[$extension])) {
            $header .= "Content-Type: " . self::$mimeTypeMap[$extension] . "\r\n";
        } else {
            $header .= "Content-Type: application/octet-stream\r\n";
            $header .= "Content-Disposition: attachment; filename=\"$file_name\"\r\n";
        }
        $header .= "Connection: keep-alive\r\n";
        $header .= $modified_time;
        $header .= "Content-Length: $file_size\r\n\r\n";
        $trunk_limit_size = 1024*1024;
        if ($file_size < $trunk_limit_size) {
            return $connection->send($header.file_get_contents($file_path), true);
        }
        $connection->send($header, true);

        // Read file content from disk piece by piece and send to client.
        $connection->fileHandler = fopen($file_path, 'r');
        $do_write = function()use($connection)
        {
            // Send buffer not full.
            while(empty($connection->bufferFull))
            {
                // Read from disk.
                $buffer = fread($connection->fileHandler, 8192);
                // Read eof.
                if($buffer === '' || $buffer === false)
                {
                    return;
                }
                $connection->send($buffer, true);
            }
        };
        // Send buffer full.
        $connection->onBufferFull = function($connection)
        {
            $connection->bufferFull = true;
        };
        // Send buffer drain.
        $connection->onBufferDrain = function($connection)use($do_write)
        {
            $connection->bufferFull = false;
            $do_write();
        };
        $do_write();
    }
}
