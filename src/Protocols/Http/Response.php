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
    protected $header = null;

    /**
     * Http status.
     *
     * @var int
     */
    protected $status = null;

    /**
     * Http reason.
     *
     * @var string
     */
    protected $reason = null;

    /**
     * Http version.
     *
     * @var string
     */
    protected $version = '1.1';

    /**
     * Http body.
     *
     * @var string
     */
    protected $body = null;

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
    protected static $mimeTypeMap = null;

    /**
     * Phrases.
     *
     * @var array<int,string>
     * 
     * @link https://en.wikipedia.org/wiki/List_of_HTTP_status_codes
     */
    const PHRASES = [
        100 => 'Continue',
        101 => 'Switching Protocols',
        102 => 'Processing', // WebDAV; RFC 2518
        103 => 'Early Hints', // RFC 8297

        200 => 'OK',
        201 => 'Created',
        202 => 'Accepted',
        203 => 'Non-Authoritative Information', // since HTTP/1.1
        204 => 'No Content',
        205 => 'Reset Content',
        206 => 'Partial Content', // RFC 7233
        207 => 'Multi-Status', // WebDAV; RFC 4918
        208 => 'Already Reported', // WebDAV; RFC 5842
        226 => 'IM Used', // RFC 3229

        300 => 'Multiple Choices',
        301 => 'Moved Permanently',
        302 => 'Found', // Previously "Moved temporarily"
        303 => 'See Other', // since HTTP/1.1
        304 => 'Not Modified', // RFC 7232
        305 => 'Use Proxy', // since HTTP/1.1
        306 => 'Switch Proxy',
        307 => 'Temporary Redirect', // since HTTP/1.1
        308 => 'Permanent Redirect', // RFC 7538

        400 => 'Bad Request',
        401 => 'Unauthorized', // RFC 7235
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required', // RFC 7235
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed', // RFC 7232
        413 => 'Payload Too Large', // RFC 7231
        414 => 'URI Too Long', // RFC 7231
        415 => 'Unsupported Media Type', // RFC 7231
        416 => 'Range Not Satisfiable', // RFC 7233
        417 => 'Expectation Failed',
        418 => 'I\'m a teapot', // RFC 2324, RFC 7168
        421 => 'Misdirected Request', // RFC 7540
        422 => 'Unprocessable Entity', // WebDAV; RFC 4918
        423 => 'Locked', // WebDAV; RFC 4918
        424 => 'Failed Dependency', // WebDAV; RFC 4918
        425 => 'Too Early', // RFC 8470
        426 => 'Upgrade Required',
        428 => 'Precondition Required', // RFC 6585
        429 => 'Too Many Requests', // RFC 6585
        431 => 'Request Header Fields Too Large', // RFC 6585
        451 => 'Unavailable For Legal Reasons', // RFC 7725
        
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates', // RFC 2295
        507 => 'Insufficient Storage', // WebDAV; RFC 4918
        508 => 'Loop Detected', // WebDAV; RFC 5842
        510 => 'Not Extended', // RFC 2774
        511 => 'Network Authentication Required', // RFC 6585
    ];

    /**
     * Init.
     *
     * @return void
     */
    public static function init()
    {
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
        $headers = [],
        $body = ''
    )
    {
        $this->status = $status;
        $this->header = $headers;
        $this->body = (string)$body;
    }

    /**
     * Set header.
     *
     * @param string $name
     * @param string $value
     * @return $this
     */
    public function header($name, $value)
    {
        $this->header[$name] = $value;
        return $this;
    }

    /**
     * Set header.
     *
     * @param string $name
     * @param string $value
     * @return Response
     */
    public function withHeader($name, $value)
    {
        return $this->header($name, $value);
    }

    /**
     * Set headers.
     *
     * @param array $headers
     * @return $this
     */
    public function withHeaders($headers)
    {
        $this->header = \array_merge_recursive($this->header, $headers);
        return $this;
    }

    /**
     * Remove header.
     *
     * @param string $name
     * @return $this
     */
    public function withoutHeader($name)
    {
        unset($this->header[$name]);
        return $this;
    }

    /**
     * Get header.
     *
     * @param string $name
     * @return null|array|string
     */
    public function getHeader($name)
    {

        return $this->header[$name] ?? null;
    }

    /**
     * Get headers.
     *
     * @return array
     */
    public function getHeaders()
    {
        return $this->header;
    }

    /**
     * Set status.
     *
     * @param int $code
     * @param string|null $reason_phrase
     * @return $this
     */
    public function withStatus($code, $reason_phrase = null)
    {
        $this->status = $code;
        $this->reason = $reason_phrase;
        return $this;
    }

    /**
     * Get status code.
     *
     * @return int
     */
    public function getStatusCode()
    {
        return $this->status;
    }

    /**
     * Get reason phrase.
     *
     * @return string
     */
    public function getReasonPhrase()
    {
        return $this->reason;
    }

    /**
     * Set protocol version.
     *
     * @param int $version
     * @return $this
     */
    public function withProtocolVersion($version)
    {
        $this->version = $version;
        return $this;
    }

    /**
     * Set http body.
     *
     * @param string $body
     * @return $this
     */
    public function withBody($body)
    {
        $this->body = (string)$body;
        return $this;
    }

    /**
     * Get http raw body.
     *
     * @return string
     */
    public function rawBody()
    {
        return $this->body;
    }

    /**
     * Send file.
     *
     * @param string $file
     * @param int $offset
     * @param int $length
     * @return $this
     */
    public function withFile($file, $offset = 0, $length = 0)
    {
        if (!\is_file($file)) {
            return $this->withStatus(404)->withBody('<h3>404 Not Found</h3>');
        }
        $this->file = ['file' => $file, 'offset' => $offset, 'length' => $length];
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
        $this->header['Set-Cookie'][] = $name . '=' . \rawurlencode($value)
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
        $reason = $this->reason ?: self::PHRASES[$this->status];
        $head = "HTTP/{$this->version} {$this->status} $reason\r\n";
        $headers = $this->header;
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
        $extension = $file_info['extension'] ?? '';
        $base_name = $file_info['basename'] ?? 'unknown';
        if (!isset($headers['Content-Type'])) {
            if (isset(self::$mimeTypeMap[$extension])) {
                $head .= "Content-Type: " . self::$mimeTypeMap[$extension] . "\r\n";
            } else {
                $head .= "Content-Type: application/octet-stream\r\n";
            }
        }

        if (!isset($headers['Content-Disposition']) && !isset(self::$mimeTypeMap[$extension])) {
            $head .= "Content-Disposition: attachment; filename=\"$base_name\"\r\n";
        }

        if (!isset($headers['Last-Modified'])) {
            if ($mtime = \filemtime($file)) {
                $head .= 'Last-Modified: ' . \gmdate('D, d M Y H:i:s', $mtime) . ' GMT' . "\r\n";
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

        $reason = $this->reason ?: self::PHRASES[$this->status] ?? '';
        $body_len = \strlen($this->body);
        if (empty($this->header)) {
            return "HTTP/{$this->version} {$this->status} $reason\r\nServer: workerman\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $body_len\r\nConnection: keep-alive\r\n\r\n{$this->body}";
        }

        $head = "HTTP/{$this->version} {$this->status} $reason\r\n";
        $headers = $this->header;
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
            return $head . $this->body;
        }

        if (!isset($headers['Transfer-Encoding'])) {
            $head .= "Content-Length: $body_len\r\n\r\n";
        } else {
            return "$head\r\n" . dechex($body_len) . "\r\n{$this->body}\r\n";
        }

        // The whole http package
        return $head . $this->body;
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
                $mime_type = $match[1];
                $extension_var = $match[2];
                $extension_array = \explode(' ', \substr($extension_var, 0, -1));
                foreach ($extension_array as $file_extension) {
                    static::$mimeTypeMap[$file_extension] = $mime_type;
                }
            }
        }
    }
}

Response::init();
