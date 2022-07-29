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
namespace Workerman\Protocols\Http;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Session;
use Workerman\Protocols\Http;
use Workerman\Worker;

/**
 * Class Request
 * @package Workerman\Protocols\Http
 */
class Request
{
    /**
     * Connection.
     *
     * @var TcpConnection
     */
    public $connection = null;

    /**
     * Session instance.
     *
     * @var Session
     */
    public $session = null;

    /**
     * Properties.
     *
     * @var array
     */
    public $properties = array();

    /**
     * @var int 
     */
    public static $maxFileUploads = 1024;

    /**
     * Http buffer.
     *
     * @var string
     */
    protected $_buffer = null;

    /**
     * Request data.
     *
     * @var array
     */
    protected $_data = null;

    /**
     * Enable cache.
     *
     * @var bool
     */
    protected static $_enableCache = true;


    /**
     * Request constructor.
     *
     * @param string $buffer
     */
    public function __construct($buffer)
    {
        $this->_buffer = $buffer;
    }

    /**
     * $_GET.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function get($name = null, $default = null)
    {
        if (!isset($this->_data['get'])) {
            $this->parseGet();
        }
        if (null === $name) {
            return $this->_data['get'];
        }
        return isset($this->_data['get'][$name]) ? $this->_data['get'][$name] : $default;
    }

    /**
     * $_POST.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function post($name = null, $default = null)
    {
        if (!isset($this->_data['post'])) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->_data['post'];
        }
        return isset($this->_data['post'][$name]) ? $this->_data['post'][$name] : $default;
    }

    /**
     * Get header item by name.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return array|string|null
     */
    public function header($name = null, $default = null)
    {
        if (!isset($this->_data['headers'])) {
            $this->parseHeaders();
        }
        if (null === $name) {
            return $this->_data['headers'];
        }
        $name = \strtolower($name);
        return isset($this->_data['headers'][$name]) ? $this->_data['headers'][$name] : $default;
    }

    /**
     * Get cookie item by name.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return array|string|null
     */
    public function cookie($name = null, $default = null)
    {
        if (!isset($this->_data['cookie'])) {
            $this->_data['cookie'] = array();
            \parse_str(\preg_replace('/; ?/', '&', $this->header('cookie', '')), $this->_data['cookie']);
        }
        if ($name === null) {
            return $this->_data['cookie'];
        }
        return isset($this->_data['cookie'][$name]) ? $this->_data['cookie'][$name] : $default;
    }

    /**
     * Get upload files.
     *
     * @param string|null $name
     * @return array|null
     */
    public function file($name = null)
    {
        if (!isset($this->_data['files'])) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->_data['files'];
        }
        return isset($this->_data['files'][$name]) ? $this->_data['files'][$name] : null;
    }

    /**
     * Get method.
     *
     * @return string
     */
    public function method()
    {
        if (!isset($this->_data['method'])) {
            $this->parseHeadFirstLine();
        }
        return $this->_data['method'];
    }

    /**
     * Get http protocol version.
     *
     * @return string
     */
    public function protocolVersion()
    {
        if (!isset($this->_data['protocolVersion'])) {
            $this->parseProtocolVersion();
        }
        return $this->_data['protocolVersion'];
    }

    /**
     * Get host.
     *
     * @param bool $without_port
     * @return string
     */
    public function host($without_port = false)
    {
        $host = $this->header('host');
        if ($host && $without_port && $pos = \strpos($host, ':')) {
            return \substr($host, 0, $pos);
        }
        return $host;
    }

    /**
     * Get uri.
     *
     * @return mixed
     */
    public function uri()
    {
        if (!isset($this->_data['uri'])) {
            $this->parseHeadFirstLine();
        }
        return $this->_data['uri'];
    }

    /**
     * Get path.
     *
     * @return mixed
     */
    public function path()
    {
        if (!isset($this->_data['path'])) {
            $this->_data['path'] = (string)\parse_url($this->uri(), PHP_URL_PATH);
        }
        return $this->_data['path'];
    }

    /**
     * Get query string.
     *
     * @return mixed
     */
    public function queryString()
    {
        if (!isset($this->_data['query_string'])) {
            $this->_data['query_string'] = (string)\parse_url($this->uri(), PHP_URL_QUERY);
        }
        return $this->_data['query_string'];
    }

    /**
     * Get session.
     *
     * @return bool|\Workerman\Protocols\Http\Session
     */
    public function session()
    {
        if ($this->session === null) {
            $session_id = $this->sessionId();
            if ($session_id === false) {
                return false;
            }
            $this->session = new Session($session_id);
        }
        return $this->session;
    }

    /**
     * Get/Set session id.
     *
     * @param $session_id
     * @return string
     */
    public function sessionId($session_id = null)
    {
        if ($session_id) {
            unset($this->sid);
        }
        if (!isset($this->sid)) {
            $session_name = Session::$name;
            $sid = $session_id ? '' : $this->cookie($session_name);
            if ($sid === '' || $sid === null) {
                if ($this->connection === null) {
                    Worker::safeEcho('Request->session() fail, header already send');
                    return false;
                }
                $sid = $session_id ? $session_id : static::createSessionId();
                $cookie_params = Session::getCookieParams();
                $this->connection->__header['Set-Cookie'] = array($session_name . '=' . $sid
                    . (empty($cookie_params['domain']) ? '' : '; Domain=' . $cookie_params['domain'])
                    . (empty($cookie_params['lifetime']) ? '' : '; Max-Age=' . $cookie_params['lifetime'])
                    . (empty($cookie_params['path']) ? '' : '; Path=' . $cookie_params['path'])
                    . (empty($cookie_params['samesite']) ? '' : '; SameSite=' . $cookie_params['samesite'])
                    . (!$cookie_params['secure'] ? '' : '; Secure')
                    . (!$cookie_params['httponly'] ? '' : '; HttpOnly'));
            }
            $this->sid = $sid;
        }
        return $this->sid;
    }

    /**
     * Get http raw head.
     *
     * @return string
     */
    public function rawHead()
    {
        if (!isset($this->_data['head'])) {
            $this->_data['head'] = \strstr($this->_buffer, "\r\n\r\n", true);
        }
        return $this->_data['head'];
    }

    /**
     * Get http raw body.
     *
     * @return string
     */
    public function rawBody()
    {
        return \substr($this->_buffer, \strpos($this->_buffer, "\r\n\r\n") + 4);
    }

    /**
     * Get raw buffer.
     *
     * @return string
     */
    public function rawBuffer()
    {
        return $this->_buffer;
    }

    /**
     * Enable or disable cache.
     *
     * @param mixed $value
     */
    public static function enableCache($value)
    {
        static::$_enableCache = (bool)$value;
    }

    /**
     * Parse first line of http header buffer.
     *
     * @return void
     */
    protected function parseHeadFirstLine()
    {
        $first_line = \strstr($this->_buffer, "\r\n", true);
        $tmp = \explode(' ', $first_line, 3);
        $this->_data['method'] = $tmp[0];
        $this->_data['uri'] = isset($tmp[1]) ? $tmp[1] : '/';
    }

    /**
     * Parse protocol version.
     *
     * @return void
     */
    protected function parseProtocolVersion()
    {
        $first_line = \strstr($this->_buffer, "\r\n", true);
        $protoco_version = substr(\strstr($first_line, 'HTTP/'), 5);
        $this->_data['protocolVersion'] = $protoco_version ? $protoco_version : '1.0';
    }

    /**
     * Parse headers.
     *
     * @return void
     */
    protected function parseHeaders()
    {
        static $cache = [];
        $this->_data['headers'] = array();
        $raw_head = $this->rawHead();
        $end_line_position = \strpos($raw_head, "\r\n");
        if ($end_line_position === false) {
            return;
        }
        $head_buffer = \substr($raw_head, $end_line_position + 2);
        $cacheable = static::$_enableCache && !isset($head_buffer[2048]);
        if ($cacheable && isset($cache[$head_buffer])) {
            $this->_data['headers'] = $cache[$head_buffer];
            return;
        }
        $head_data = \explode("\r\n", $head_buffer);
        foreach ($head_data as $content) {
            if (false !== \strpos($content, ':')) {
                list($key, $value) = \explode(':', $content, 2);
                $key = \strtolower($key);
                $value = \ltrim($value);
            } else {
                $key = \strtolower($content);
                $value = '';
            }
            if (isset($this->_data['headers'][$key])) {
                $this->_data['headers'][$key] = "{$this->_data['headers'][$key]},$value";
            } else {
                $this->_data['headers'][$key] = $value;
            }
        }
        if ($cacheable) {
            $cache[$head_buffer] = $this->_data['headers'];
            if (\count($cache) > 128) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse head.
     *
     * @return void
     */
    protected function parseGet()
    {
        static $cache = [];
        $query_string = $this->queryString();
        $this->_data['get'] = array();
        if ($query_string === '') {
            return;
        }
        $cacheable = static::$_enableCache && !isset($query_string[1024]);
        if ($cacheable && isset($cache[$query_string])) {
            $this->_data['get'] = $cache[$query_string];
            return;
        }
        \parse_str($query_string, $this->_data['get']);
        if ($cacheable) {
            $cache[$query_string] = $this->_data['get'];
            if (\count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse post.
     *
     * @return void
     */
    protected function parsePost()
    {
        static $cache = [];
        $this->_data['post'] = $this->_data['files'] = array();
        $content_type = $this->header('content-type', '');
        if (\preg_match('/boundary="?(\S+)"?/', $content_type, $match)) {
            $http_post_boundary = '--' . $match[1];
            $this->parseUploadFiles($http_post_boundary);
            return;
        }
        $body_buffer = $this->rawBody();
        if ($body_buffer === '') {
            return;
        }
        $cacheable = static::$_enableCache && !isset($body_buffer[1024]);
        if ($cacheable && isset($cache[$body_buffer])) {
            $this->_data['post'] = $cache[$body_buffer];
            return;
        }
        if (\preg_match('/\bjson\b/i', $content_type)) {
            $this->_data['post'] = (array) json_decode($body_buffer, true);
        } else {
            \parse_str($body_buffer, $this->_data['post']);
        }
        if ($cacheable) {
            $cache[$body_buffer] = $this->_data['post'];
            if (\count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse upload files.
     *
     * @param string $http_post_boundary
     * @return void
     */
    protected function parseUploadFiles($http_post_boundary)
    {
        $http_post_boundary = \trim($http_post_boundary, '"');
        $buffer = $this->_buffer;
        $post_encode_string = '';
        $files_encode_string = '';
        $files = [];
        $boday_position = strpos($buffer, "\r\n\r\n") + 4;
        $offset = $boday_position + strlen($http_post_boundary) + 2;
        $max_count = static::$maxFileUploads;
        while ($max_count-- > 0 && $offset) {
            $offset = $this->parseUploadFile($http_post_boundary, $offset, $post_encode_string, $files_encode_string, $files);
        }
        if ($post_encode_string) {
            parse_str($post_encode_string, $this->_data['post']);
        }

        if ($files_encode_string) {
            parse_str($files_encode_string, $this->_data['files']);
            \array_walk_recursive($this->_data['files'], function (&$value) use ($files) {
                $value = $files[$value];
            });
        }
    }

    /**
     * @param $boundary
     * @param $section_start_offset
     * @return int
     */
    protected function parseUploadFile($boundary, $section_start_offset, &$post_encode_string, &$files_encode_str, &$files)
    {
        $file = [];
        $boundary = "\r\n$boundary";
        $section_end_offset = \strpos($this->_buffer, $boundary, $section_start_offset);
        if (!$section_end_offset) {
            return 0;
        }
        $content_lines_end_offset = \strpos($this->_buffer, "\r\n\r\n", $section_start_offset);
        if (!$content_lines_end_offset || $content_lines_end_offset + 4 > $section_end_offset) {
            return 0;
        }
        $content_lines_str = \substr($this->_buffer, $section_start_offset, $content_lines_end_offset - $section_start_offset);
        $content_lines = \explode("\r\n", trim($content_lines_str . "\r\n"));
        $boundary_value = \substr($this->_buffer, $content_lines_end_offset + 4, $section_end_offset - $content_lines_end_offset - 4);
        $upload_key = false;
        foreach ($content_lines as $content_line) {
            if (!\strpos($content_line, ': ')) {
                return 0;
            }
            list($key, $value) = \explode(': ', $content_line);
            switch (strtolower($key)) {
                case "content-disposition":
                    // Is file data.
                    if (\preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
                        $error = 0;
                        $tmp_file = '';
                        $size = \strlen($boundary_value);
                        $tmp_upload_dir = HTTP::uploadTmpDir();
                        if (!$tmp_upload_dir) {
                            $error = UPLOAD_ERR_NO_TMP_DIR;
                        } else if ($boundary_value === '') {
                            $error = UPLOAD_ERR_NO_FILE;
                        } else {
                            $tmp_file = \tempnam($tmp_upload_dir, 'workerman.upload.');
                            if ($tmp_file === false || false == \file_put_contents($tmp_file, $boundary_value)) {
                                $error = UPLOAD_ERR_CANT_WRITE;
                            }
                        }
                        $upload_key = $match[1];
                        // Parse upload files.
                        $file = [
                            'name' => $match[2],
                            'tmp_name' => $tmp_file,
                            'size' => $size,
                            'error' => $error,
                            'type' => null,
                        ];
                        break;
                    } // Is post field.
                    else {
                        // Parse $_POST.
                        if (\preg_match('/name="(.*?)"$/', $value, $match)) {
                            $k = $match[1];
                            $post_encode_string .= \urlencode($k) . "=" . \urlencode($boundary_value) . '&';
                        }
                        return $section_end_offset + \strlen($boundary) + 2;
                    }
                    break;
                case "content-type":
                    $file['type'] = \trim($value);
                    break;
            }
        }
        if ($upload_key === false) {
            return 0;
        }
        $files_encode_str .= \urlencode($upload_key) . '=' . \count($files) . '&';
        $files[] = $file;

        return $section_end_offset + \strlen($boundary) + 2;
    }

    /**
     * Create session id.
     *
     * @return string
     */
    protected static function createSessionId()
    {
        return \bin2hex(\pack('d', \microtime(true)) . random_bytes(8));
    }

    /**
     * Setter.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set($name, $value)
    {
        $this->properties[$name] = $value;
    }

    /**
     * Getter.
     *
     * @param string $name
     * @return mixed|null
     */
    public function __get($name)
    {
        return isset($this->properties[$name]) ? $this->properties[$name] : null;
    }

    /**
     * Isset.
     *
     * @param string $name
     * @return bool
     */
    public function __isset($name)
    {
        return isset($this->properties[$name]);
    }

    /**
     * Unset.
     *
     * @param string $name
     * @return void
     */
    public function __unset($name)
    {
        unset($this->properties[$name]);
    }

    /**
     * __toString.
     */
    public function __toString()
    {
        return $this->_buffer;
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        if (isset($this->_data['files'])) {
            \clearstatcache();
            \array_walk_recursive($this->_data['files'], function($value, $key){
                if ($key === 'tmp_name') {
                    if (\is_file($value)) {
                        \unlink($value);
                    }
                }
            });
        }
    }
}
