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
    public $properties = [];

    /**
     * @var int
     */
    public static $maxFileUploads = 1024;

    /**
     * Http buffer.
     *
     * @var string
     */
    protected $buffer = null;

    /**
     * Request data.
     *
     * @var array
     */
    protected $data = null;

    /**
     * Enable cache.
     *
     * @var bool
     */
    protected static $enableCache = true;


    /**
     * Request constructor.
     *
     * @param string $buffer
     */
    public function __construct($buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * $GET.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function get($name = null, $default = null)
    {
        if (!isset($this->data['get'])) {
            $this->parseGet();
        }
        if (null === $name) {
            return $this->data['get'];
        }
        return $this->data['get'][$name] ?? $default;
    }

    /**
     * $POST.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function post($name = null, $default = null)
    {
        if (!isset($this->data['post'])) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->data['post'];
        }
        return $this->data['post'][$name] ?? $default;
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
        if (!isset($this->data['headers'])) {
            $this->parseHeaders();
        }
        if (null === $name) {
            return $this->data['headers'];
        }
        $name = \strtolower($name);
        return $this->data['headers'][$name] ?? $default;
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
        if (!isset($this->data['cookie'])) {
            $this->data['cookie'] = [];
            \parse_str(\preg_replace('/; ?/', '&', $this->header('cookie', '')), $this->data['cookie']);
        }
        if ($name === null) {
            return $this->data['cookie'];
        }
        return $this->data['cookie'][$name] ?? $default;
    }

    /**
     * Get upload files.
     *
     * @param string|null $name
     * @return array|null
     */
    public function file($name = null)
    {
        if (!isset($this->data['files'])) {
            $this->parsePost();
        }
        if (null === $name) {
            return $this->data['files'];
        }
        return $this->data['files'][$name] ?? null;
    }

    /**
     * Get method.
     *
     * @return string
     */
    public function method()
    {
        if (!isset($this->data['method'])) {
            $this->parseHeadFirstLine();
        }
        return $this->data['method'];
    }

    /**
     * Get http protocol version.
     *
     * @return string
     */
    public function protocolVersion()
    {
        if (!isset($this->data['protocolVersion'])) {
            $this->parseProtocolVersion();
        }
        return $this->data['protocolVersion'];
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
        if (!isset($this->data['uri'])) {
            $this->parseHeadFirstLine();
        }
        return $this->data['uri'];
    }

    /**
     * Get path.
     *
     * @return mixed
     */
    public function path()
    {
        if (!isset($this->data['path'])) {
            $this->data['path'] = (string)\parse_url($this->uri(), PHP_URL_PATH);
        }
        return $this->data['path'];
    }

    /**
     * Get query string.
     *
     * @return mixed
     */
    public function queryString()
    {
        if (!isset($this->data['query_string'])) {
            $this->data['query_string'] = (string)\parse_url($this->uri(), PHP_URL_QUERY);
        }
        return $this->data['query_string'];
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
                $sid = $session_id ?: static::createSessionId();
                $cookie_params = Session::getCookieParams();
                $this->setSidCookie($session_name, $sid, $cookie_params);
            }
            $this->sid = $sid;
        }
        return $this->sid;
    }

    /**
     * Session regenerate id
     * @param bool $delete_old_session
     * @return void
     */
    public function sessionRegenerateId($delete_old_session = false)
    {
        $session = $this->session();
        $session_data = $session->all();
        if ($delete_old_session) {
            $session->flush();
        }
        $new_sid = static::createSessionId();
        $session = new Session($new_sid);
        $session->put($session_data);
        $cookie_params = Session::getCookieParams();
        $session_name = Session::$name;
        $this->setSidCookie($session_name, $new_sid, $cookie_params);
    }

    /**
     * Get http raw head.
     *
     * @return string
     */
    public function rawHead()
    {
        if (!isset($this->data['head'])) {
            $this->data['head'] = \strstr($this->buffer, "\r\n\r\n", true);
        }
        return $this->data['head'];
    }

    /**
     * Get http raw body.
     *
     * @return string
     */
    public function rawBody()
    {
        return \substr($this->buffer, \strpos($this->buffer, "\r\n\r\n") + 4);
    }

    /**
     * Get raw buffer.
     *
     * @return string
     */
    public function rawBuffer()
    {
        return $this->buffer;
    }

    /**
     * Enable or disable cache.
     *
     * @param mixed $value
     */
    public static function enableCache($value)
    {
        static::$enableCache = (bool)$value;
    }

    /**
     * Parse first line of http header buffer.
     *
     * @return void
     */
    protected function parseHeadFirstLine()
    {
        $first_line = \strstr($this->buffer, "\r\n", true);
        $tmp = \explode(' ', $first_line, 3);
        $this->data['method'] = $tmp[0];
        $this->data['uri'] = $tmp[1] ?? '/';
    }

    /**
     * Parse protocol version.
     *
     * @return void
     */
    protected function parseProtocolVersion()
    {
        $first_line = \strstr($this->buffer, "\r\n", true);
        $protoco_version = substr(\strstr($first_line, 'HTTP/'), 5);
        $this->data['protocolVersion'] = $protoco_version ? $protoco_version : '1.0';
    }

    /**
     * Parse headers.
     *
     * @return void
     */
    protected function parseHeaders()
    {
        static $cache = [];
        $this->data['headers'] = [];
        $raw_head = $this->rawHead();
        $end_line_position = \strpos($raw_head, "\r\n");
        if ($end_line_position === false) {
            return;
        }
        $head_buffer = \substr($raw_head, $end_line_position + 2);
        $cacheable = static::$enableCache && !isset($head_buffer[2048]);
        if ($cacheable && isset($cache[$head_buffer])) {
            $this->data['headers'] = $cache[$head_buffer];
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
            if (isset($this->data['headers'][$key])) {
                $this->data['headers'][$key] = "{$this->data['headers'][$key]},$value";
            } else {
                $this->data['headers'][$key] = $value;
            }
        }
        if ($cacheable) {
            $cache[$head_buffer] = $this->data['headers'];
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
        $this->data['get'] = [];
        if ($query_string === '') {
            return;
        }
        $cacheable = static::$enableCache && !isset($query_string[1024]);
        if ($cacheable && isset($cache[$query_string])) {
            $this->data['get'] = $cache[$query_string];
            return;
        }
        \parse_str($query_string, $this->data['get']);
        if ($cacheable) {
            $cache[$query_string] = $this->data['get'];
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
        $this->data['post'] = $this->data['files'] = [];
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
        $cacheable = static::$enableCache && !isset($body_buffer[1024]);
        if ($cacheable && isset($cache[$body_buffer])) {
            $this->data['post'] = $cache[$body_buffer];
            return;
        }
        if (\preg_match('/\bjson\b/i', $content_type)) {
            $this->data['post'] = (array)\json_decode($body_buffer, true);
        } else {
            \parse_str($body_buffer, $this->data['post']);
        }
        if ($cacheable) {
            $cache[$body_buffer] = $this->data['post'];
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
        $buffer = $this->buffer;
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
            parse_str($post_encode_string, $this->data['post']);
        }

        if ($files_encode_string) {
            parse_str($files_encode_string, $this->data['files']);
            \array_walk_recursive($this->data['files'], function (&$value) use ($files) {
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
        if (\strlen($this->buffer) < $section_start_offset) {
            return 0;
        }
        $section_end_offset = \strpos($this->buffer, $boundary, $section_start_offset);
        if (!$section_end_offset) {
            return 0;
        }
        $content_lines_end_offset = \strpos($this->buffer, "\r\n\r\n", $section_start_offset);
        if (!$content_lines_end_offset || $content_lines_end_offset + 4 > $section_end_offset) {
            return 0;
        }
        $content_lines_str = \substr($this->buffer, $section_start_offset, $content_lines_end_offset - $section_start_offset);
        $content_lines = \explode("\r\n", trim($content_lines_str . "\r\n"));
        $boundary_value = \substr($this->buffer, $content_lines_end_offset + 4, $section_end_offset - $content_lines_end_offset - 4);
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
                            'type' => '',
                        ];
                        break;
                    } // Is post field.
                    else {
                        // Parse $POST.
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
    public static function createSessionId()
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
        return $this->properties[$name] ?? null;
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
        return $this->buffer;
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        if (isset($this->data['files'])) {
            \clearstatcache();
            \array_walk_recursive($this->data['files'], function ($value, $key) {
                if ($key === 'tmp_name') {
                    if (\is_file($value)) {
                        \unlink($value);
                    }
                }
            });
        }
    }

    /**
     * @param string $session_name
     * @param string $sid
     * @param array $cookie_params
     * @return void
     */
    protected function setSidCookie(string $session_name, string $sid, array $cookie_params)
    {
        $this->connection->header['Set-Cookie'] = [$session_name . '=' . $sid
            . (empty($cookie_params['domain']) ? '' : '; Domain=' . $cookie_params['domain'])
            . (empty($cookie_params['lifetime']) ? '' : '; Max-Age=' . $cookie_params['lifetime'])
            . (empty($cookie_params['path']) ? '' : '; Path=' . $cookie_params['path'])
            . (empty($cookie_params['samesite']) ? '' : '; SameSite=' . $cookie_params['samesite'])
            . (!$cookie_params['secure'] ? '' : '; Secure')
            . (!$cookie_params['httponly'] ? '' : '; HttpOnly')];
    }
}
