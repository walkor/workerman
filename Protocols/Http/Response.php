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

/**
 * Class Response
 * @package Workerman\Protocols\Http
 */
class Response
{
    /**
     * Header data.
     *
     * @var array
     */
    protected $_header = null;

    /**
     * Http status.
     *
     * @var int
     */
    protected $_status = null;

    /**
     * Http reason.
     *
     * @var string
     */
    protected $_reason = null;

    /**
     * Http version.
     *
     * @var string
     */
    protected $_version = '1.1';

    /**
     * Http body.
     *
     * @var string
     */
    protected $_body = null;

    /**
     * Send file info
     *
     * @var array
     */
    public $file = null;

    /**
     * Mine type map.
     * @var array
     */
    protected static $_mimeTypeMap = null;

    /**
     * Phrases.
     *
     * @var array
     */
    protected static $_phrases = array(
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing',
        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information',
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content',
        207 => 'Multi-status',
        208 => 'Already Reported',
        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found',
        303 => 'See Other',
        304 => 'Not Modified',
        305 => 'Use Proxy',
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect',
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Time-out',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Large',
        415 => 'Unsupported Media Type',
        416 => 'Requested range not satisfiable',
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Unordered Collection',
        426 => 'Upgrade Required',
        428 => 'Precondition Required',
        429 => 'Too Many Requests',
        431 => 'Request Header Fields Too Large',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Time-out',
        505 => 'HTTP Version not supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        508 => 'Loop Detected',
        511 => 'Network Authentication Required',
    );

    /**
     * Init.
     *
     * @return void
     */
    public static function init() {
        static::initMimeTypeMap();
    }

    /**
     * Response constructor.
     *
     * @param int $status
     * @param array $headers
     * @param string $body
     */
    public function __construct(
        $status = 200,
        $headers = array(),
        $body = ''
    ) {
        $this->_status = $status;
        $this->_header = $headers;
        $this->_body = (string)$body;
    }

    /**
     * Set header.
     *
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function header($name, $value) {
        $this->_header[$name] = $value;
        return $this;
    }

    /**
     * Set header.
     *
     * @param string $name
     * @param string $value
     * @return Response
     */
    public function withHeader($name, $value) {
        return $this->header($name, $value);
    }

    /**
     * Set headers.
     *
     * @param array $headers
     * @return $this
     */
    public function withHeaders($headers) {
        $this->_header = \array_merge_recursive($this->_header, $headers);
        return $this;
    }
    
    /**
     * Remove header.
     *
     * @param string $name
     * @return $this
     */
    public function withoutHeader($name) {
        unset($this->_header[$name]);
        return $this;
    }

    /**
     * Get header.
     *
     * @param string $name
     * @return null|array|string
     */
    public function getHeader($name) {
        if (!isset($this->_header[$name])) {
            return null;
        }
        return $this->_header[$name];
    }

    /**
     * Get headers.
     *
     * @return array
     */
    public function getHeaders() {
        return $this->_header;
    }

    /**
     * Set status.
     *
     * @param int $code
     * @param string|null $reason_phrase
     * @return $this
     */
    public function withStatus($code, $reason_phrase = null) {
        $this->_status = $code;
        $this->_reason = $reason_phrase;
        return $this;
    }

    /**
     * Get status code.
     *
     * @return int
     */
    public function getStatusCode() {
        return $this->_status;
    }

    /**
     * Get reason phrase.
     *
     * @return string
     */
    public function getReasonPhrase() {
        return $this->_reason;
    }

    /**
     * Set protocol version.
     *
     * @param int $version
     * @return $this
     */
    public function withProtocolVersion($version) {
        $this->_version = $version;
        return $this;
    }

    /**
     * Set http body.
     *
     * @param string $body
     * @return $this
     */
    public function withBody($body) {
        $this->_body = $body;
        return $this;
    }

    /**
     * Get http raw body.
     * 
     * @return string
     */
    public function rawBody() {
        return $this->_body;
    }

    /**
     * Send file.
     *
     * @param string $file
     * @param int $offset
     * @param int $length
     * @return $this
     */
    public function withFile($file, $offset = 0, $length = 0) {
        if (!\is_file($file)) {
            return $this->withStatus(404)->withBody('<h3>404 Not Found</h3>');
        }
        $this->file = array('file' => $file, 'offset' => $offset, 'length' => $length);
        return $this;
    }

    /**
     * Set cookie.
     *
     * @param $name
     * @param string $value
     * @param int $max_age
     * @param string $path
     * @param string $domain
     * @param bool $secure
     * @param bool $http_only
     * @param bool $same_site
     * @return $this
     */
    public function cookie($name, $value = '', $max_age = null, $path = '', $domain = '', $secure = false, $http_only = false, $same_site  = false)
    {
        $this->_header['Set-Cookie'][] = $name . '=' . \rawurlencode($value)
            . (empty($domain) ? '' : '; Domain=' . $domain)
            . ($max_age === null ? '' : '; Max-Age=' . $max_age)
            . (empty($path) ? '' : '; Path=' . $path)
            . (!$secure ? '' : '; Secure')
            . (!$http_only ? '' : '; HttpOnly')
            . (empty($same_site) ? '' : '; SameSite=' . $same_site);
        return $this;
    }

    /**
     * Create header for file.
     *
     * @param array $file_info
     * @return string
     */
    protected function createHeadForFile($file_info)
    {
        $file = $file_info['file'];
        $reason = $this->_reason ? $this->_reason : static::$_phrases[$this->_status];
        $head = "HTTP/{$this->_version} {$this->_status} $reason\r\n";
        $headers = $this->_header;
        if (!isset($headers['Server'])) {
            $head .= "Server: workerman\r\n";
        }
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $item) {
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            $head .= "$name: $value\r\n";
        }

        if (!isset($headers['Connection'])) {
            $head .= "Connection: keep-alive\r\n";
        }

        $file_info = \pathinfo($file);
        $extension = isset($file_info['extension']) ? $file_info['extension'] : '';
        $base_name = isset($file_info['basename']) ? $file_info['basename'] : 'unknown';
        if (!isset($headers['Content-Type'])) {
            if (isset(self::$_mimeTypeMap[$extension])) {
                $head .= "Content-Type: " . self::$_mimeTypeMap[$extension] . "\r\n";
            } else {
                $head .= "Content-Type: application/octet-stream\r\n";
            }
        }

        if (!isset($headers['Content-Disposition']) && !isset(self::$_mimeTypeMap[$extension])) {
            $head .= "Content-Disposition: attachment; filename=\"$base_name\"\r\n";
        }

        if (!isset($headers['Last-Modified'])) {
            if ($mtime = \filemtime($file)) {
                $head .= 'Last-Modified: '. \gmdate('D, d M Y H:i:s', $mtime) . ' GMT' . "\r\n";
            }
        }

        return "{$head}\r\n";
    }

    /**
     * __toString.
     *
     * @return string
     */
    public function __toString()
    {
        if (isset($this->file)) {
            return $this->createHeadForFile($this->file);
        }

        $reason = $this->_reason ? $this->_reason : static::$_phrases[$this->_status];
        $body_len = \strlen($this->_body);
        if (empty($this->_header)) {
            return "HTTP/{$this->_version} {$this->_status} $reason\r\nServer: workerman\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $body_len\r\nConnection: keep-alive\r\n\r\n{$this->_body}";
        }

        $head = "HTTP/{$this->_version} {$this->_status} $reason\r\n";
        $headers = $this->_header;
        if (!isset($headers['Server'])) {
            $head .= "Server: workerman\r\n";
        }
        foreach ($headers as $name => $value) {
            if (\is_array($value)) {
                foreach ($value as $item) {
                    $head .= "$name: $item\r\n";
                }
                continue;
            }
            $head .= "$name: $value\r\n";
        }

        if (!isset($headers['Connection'])) {
            $head .= "Connection: keep-alive\r\n";
        }

        if (!isset($headers['Content-Type'])) {
            $head .= "Content-Type: text/html;charset=utf-8\r\n";
        } else if ($headers['Content-Type'] === 'text/event-stream') {
            return $head . $this->_body;
        }

        if (!isset($headers['Transfer-Encoding'])) {
            $head .= "Content-Length: $body_len\r\n\r\n";
        } else {
            return "$head\r\n".dechex($body_len)."\r\n{$this->_body}\r\n";
        }

        // The whole http package
        return $head . $this->_body;
    }

    /**
     * Init mime map.
     *
     * @return void
     */
    public static function initMimeTypeMap()
    {
        $mime_file = __DIR__ . '/mime.types';
        $items = \file($mime_file, \FILE_IGNORE_NEW_LINES | \FILE_SKIP_EMPTY_LINES);
        foreach ($items as $content) {
            if (\preg_match("/\s*(\S+)\s+(\S.+)/", $content, $match)) {
                $mime_type       = $match[1];
                $extension_var   = $match[2];
                $extension_array = \explode(' ', \substr($extension_var, 0, -1));
                foreach ($extension_array as $file_extension) {
                    static::$_mimeTypeMap[$file_extension] = $mime_type;
                }
            }
        }
    }
}
Response::init();
