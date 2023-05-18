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

declare(strict_types=1);

namespace Workerman\Protocols\Http;

use Exception;
use RuntimeException;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http;
use function array_walk_recursive;
use function bin2hex;
use function clearstatcache;
use function count;
use function explode;
use function file_put_contents;
use function is_file;
use function json_decode;
use function ltrim;
use function microtime;
use function pack;
use function parse_str;
use function parse_url;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strstr;
use function strtolower;
use function substr;
use function tempnam;
use function trim;
use function unlink;
use function urlencode;

/**
 * Class Request
 * @package Workerman\Protocols\Http
 */
class Request
{
    /**
     * Connection.
     *
     * @var ?TcpConnection
     */
    public ?TcpConnection $connection = null;

    /**
     * Session instance.
     *
     * @var ?Session
     */
    public ?Session $session = null;

    /**
     * @var int
     */
    public static int $maxFileUploads = 1024;

    /**
     * Properties.
     *
     * @var array
     */
    public array $properties = [];

    /**
     * Http buffer.
     *
     * @var string
     */
    protected string $buffer;

    /**
     * Request data.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Enable cache.
     *
     * @var bool
     */
    protected static bool $enableCache = true;

    /**
     * Request constructor.
     *
     * @param string $buffer
     */
    public function __construct(string $buffer)
    {
        $this->buffer = $buffer;
    }

    /**
     * Get query.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed
     */
    public function get(string $name = null, mixed $default = null): mixed
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
     * Get post.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed
     */
    public function post(string $name = null, mixed $default = null): mixed
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
     * @return mixed
     */
    public function header(string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['headers'])) {
            $this->parseHeaders();
        }
        if (null === $name) {
            return $this->data['headers'];
        }
        $name = strtolower($name);
        return $this->data['headers'][$name] ?? $default;
    }

    /**
     * Get cookie item by name.
     *
     * @param string|null $name
     * @param mixed|null $default
     * @return mixed
     */
    public function cookie(string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['cookie'])) {
            $this->data['cookie'] = [];
            parse_str(preg_replace('/; ?/', '&', $this->header('cookie', '')), $this->data['cookie']);
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
    public function file(string $name = null)
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
    public function method(): string
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
    public function protocolVersion(): string
    {
        if (!isset($this->data['protocolVersion'])) {
            $this->parseProtocolVersion();
        }
        return $this->data['protocolVersion'];
    }

    /**
     * Get host.
     *
     * @param bool $withoutPort
     * @return string|null
     */
    public function host(bool $withoutPort = false): ?string
    {
        $host = $this->header('host');
        if ($host && $withoutPort && $pos = strpos($host, ':')) {
            return substr($host, 0, $pos);
        }
        return $host;
    }

    /**
     * Get uri.
     *
     * @return string
     */
    public function uri(): string
    {
        if (!isset($this->data['uri'])) {
            $this->parseHeadFirstLine();
        }
        return $this->data['uri'];
    }

    /**
     * Get path.
     *
     * @return string
     */
    public function path(): string
    {
        if (!isset($this->data['path'])) {
            $this->data['path'] = (string)parse_url($this->uri(), PHP_URL_PATH);
        }
        return $this->data['path'];
    }

    /**
     * Get query string.
     *
     * @return string
     */
    public function queryString(): string
    {
        if (!isset($this->data['query_string'])) {
            $this->data['query_string'] = (string)parse_url($this->uri(), PHP_URL_QUERY);
        }
        return $this->data['query_string'];
    }

    /**
     * Get session.
     *
     * @return Session
     * @throws Exception
     */
    public function session(): Session
    {
        if ($this->session === null) {
            $this->session = new Session($this->sessionId());
        }
        return $this->session;
    }

    /**
     * Get/Set session id.
     *
     * @param null $sessionId
     * @return string
     * @throws Exception
     */
    public function sessionId($sessionId = null): string
    {
        if ($sessionId) {
            unset($this->sid);
        }
        if (!isset($this->sid)) {
            $sessionName = Session::$name;
            $sid = $sessionId ? '' : $this->cookie($sessionName);
            if ($sid === '' || $sid === null) {
                if (!$this->connection) {
                    throw new RuntimeException('Request->session() fail, header already send');
                }
                $sid = $sessionId ?: static::createSessionId();
                $cookieParams = Session::getCookieParams();
                $this->setSidCookie($sessionName, $sid, $cookieParams);
            }
            $this->sid = $sid;
        }
        return $this->sid;
    }

    /**
     * Session regenerate id.
     *
     * @param bool $deleteOldSession
     * @return string
     * @throws Exception
     */
    public function sessionRegenerateId(bool $deleteOldSession = false): string
    {
        $session = $this->session();
        $sessionData = $session->all();
        if ($deleteOldSession) {
            $session->flush();
        }
        $newSid = static::createSessionId();
        $session = new Session($newSid);
        $session->put($sessionData);
        $cookieParams = Session::getCookieParams();
        $sessionName = Session::$name;
        $this->setSidCookie($sessionName, $newSid, $cookieParams);
        return $newSid;
    }

    /**
     * Get http raw head.
     *
     * @return string
     */
    public function rawHead(): string
    {
        if (!isset($this->data['head'])) {
            $this->data['head'] = strstr($this->buffer, "\r\n\r\n", true);
        }
        return $this->data['head'];
    }

    /**
     * Get http raw body.
     *
     * @return string
     */
    public function rawBody(): string
    {
        return substr($this->buffer, strpos($this->buffer, "\r\n\r\n") + 4);
    }

    /**
     * Get raw buffer.
     *
     * @return string
     */
    public function rawBuffer(): string
    {
        return $this->buffer;
    }

    /**
     * Enable or disable cache.
     *
     * @param bool $value
     */
    public static function enableCache(bool $value): void
    {
        static::$enableCache = $value;
    }

    /**
     * Parse first line of http header buffer.
     *
     * @return void
     */
    protected function parseHeadFirstLine(): void
    {
        $firstLine = strstr($this->buffer, "\r\n", true);
        $tmp = explode(' ', $firstLine, 3);
        $this->data['method'] = $tmp[0];
        $this->data['uri'] = $tmp[1] ?? '/';
    }

    /**
     * Parse protocol version.
     *
     * @return void
     */
    protected function parseProtocolVersion(): void
    {
        $firstLine = strstr($this->buffer, "\r\n", true);
        $protocolVersion = substr(strstr($firstLine, 'HTTP/'), 5);
        $this->data['protocolVersion'] = $protocolVersion ?: '1.0';
    }

    /**
     * Parse headers.
     *
     * @return void
     */
    protected function parseHeaders(): void
    {
        static $cache = [];
        $this->data['headers'] = [];
        $rawHead = $this->rawHead();
        $endLinePosition = strpos($rawHead, "\r\n");
        if ($endLinePosition === false) {
            return;
        }
        $headBuffer = substr($rawHead, $endLinePosition + 2);
        $cacheable = static::$enableCache && !isset($headBuffer[4096]);
        if ($cacheable && isset($cache[$headBuffer])) {
            $this->data['headers'] = $cache[$headBuffer];
            return;
        }
        $headData = explode("\r\n", $headBuffer);
        foreach ($headData as $content) {
            if (str_contains($content, ':')) {
                [$key, $value] = explode(':', $content, 2);
                $key = strtolower($key);
                $value = ltrim($value);
            } else {
                $key = strtolower($content);
                $value = '';
            }
            if (isset($this->data['headers'][$key])) {
                $this->data['headers'][$key] = "{$this->data['headers'][$key]},$value";
            } else {
                $this->data['headers'][$key] = $value;
            }
        }
        if ($cacheable) {
            $cache[$headBuffer] = $this->data['headers'];
            if (count($cache) > 128) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse head.
     *
     * @return void
     */
    protected function parseGet(): void
    {
        static $cache = [];
        $queryString = $this->queryString();
        $this->data['get'] = [];
        if ($queryString === '') {
            return;
        }
        $cacheable = static::$enableCache && !isset($queryString[1024]);
        if ($cacheable && isset($cache[$queryString])) {
            $this->data['get'] = $cache[$queryString];
            return;
        }
        parse_str($queryString, $this->data['get']);
        if ($cacheable) {
            $cache[$queryString] = $this->data['get'];
            if (count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse post.
     *
     * @return void
     */
    protected function parsePost(): void
    {
        static $cache = [];
        $this->data['post'] = $this->data['files'] = [];
        $contentType = $this->header('content-type', '');
        if (preg_match('/boundary="?(\S+)"?/', $contentType, $match)) {
            $httpPostBoundary = '--' . $match[1];
            $this->parseUploadFiles($httpPostBoundary);
            return;
        }
        $bodyBuffer = $this->rawBody();
        if ($bodyBuffer === '') {
            return;
        }
        $cacheable = static::$enableCache && !isset($bodyBuffer[1024]);
        if ($cacheable && isset($cache[$bodyBuffer])) {
            $this->data['post'] = $cache[$bodyBuffer];
            return;
        }
        if (preg_match('/\bjson\b/i', $contentType)) {
            $this->data['post'] = (array)json_decode($bodyBuffer, true);
        } else {
            parse_str($bodyBuffer, $this->data['post']);
        }
        if ($cacheable) {
            $cache[$bodyBuffer] = $this->data['post'];
            if (count($cache) > 256) {
                unset($cache[key($cache)]);
            }
        }
    }

    /**
     * Parse upload files.
     *
     * @param string $httpPostBoundary
     * @return void
     */
    protected function parseUploadFiles(string $httpPostBoundary): void
    {
        $httpPostBoundary = trim($httpPostBoundary, '"');
        $buffer = $this->buffer;
        $postEncodeString = '';
        $filesEncodeString = '';
        $files = [];
        $bodyPosition = strpos($buffer, "\r\n\r\n") + 4;
        $offset = $bodyPosition + strlen($httpPostBoundary) + 2;
        $maxCount = static::$maxFileUploads;
        while ($maxCount-- > 0 && $offset) {
            $offset = $this->parseUploadFile($httpPostBoundary, $offset, $postEncodeString, $filesEncodeString, $files);
        }
        if ($postEncodeString) {
            parse_str($postEncodeString, $this->data['post']);
        }

        if ($filesEncodeString) {
            parse_str($filesEncodeString, $this->data['files']);
            array_walk_recursive($this->data['files'], function (&$value) use ($files) {
                $value = $files[$value];
            });
        }
    }

    /**
     * Parse upload file.
     *
     * @param $boundary
     * @param $sectionStartOffset
     * @param $postEncodeString
     * @param $filesEncodeStr
     * @param $files
     * @return int
     */
    protected function parseUploadFile($boundary, $sectionStartOffset, &$postEncodeString, &$filesEncodeStr, &$files): int
    {
        $file = [];
        $boundary = "\r\n$boundary";
        if (strlen($this->buffer) < $sectionStartOffset) {
            return 0;
        }
        $sectionEndOffset = strpos($this->buffer, $boundary, $sectionStartOffset);
        if (!$sectionEndOffset) {
            return 0;
        }
        $contentLinesEndOffset = strpos($this->buffer, "\r\n\r\n", $sectionStartOffset);
        if (!$contentLinesEndOffset || $contentLinesEndOffset + 4 > $sectionEndOffset) {
            return 0;
        }
        $contentLinesStr = substr($this->buffer, $sectionStartOffset, $contentLinesEndOffset - $sectionStartOffset);
        $contentLines = explode("\r\n", trim($contentLinesStr . "\r\n"));
        $boundaryValue = substr($this->buffer, $contentLinesEndOffset + 4, $sectionEndOffset - $contentLinesEndOffset - 4);
        $uploadKey = false;
        foreach ($contentLines as $contentLine) {
            if (!strpos($contentLine, ': ')) {
                return 0;
            }
            [$key, $value] = explode(': ', $contentLine);
            switch (strtolower($key)) {
                case "content-disposition":
                    // Is file data.
                    if (preg_match('/name="(.*?)"; filename="(.*?)"/i', $value, $match)) {
                        $error = 0;
                        $tmpFile = '';
                        $fileName = $match[1];
                        $size = strlen($boundaryValue);
                        $tmpUploadDir = HTTP::uploadTmpDir();
                        if (!$tmpUploadDir) {
                            $error = UPLOAD_ERR_NO_TMP_DIR;
                        } else if ($boundaryValue === '' && $fileName === '') {
                            $error = UPLOAD_ERR_NO_FILE;
                        } else {
                            $tmpFile = tempnam($tmpUploadDir, 'workerman.upload.');
                            if ($tmpFile === false || false === file_put_contents($tmpFile, $boundaryValue)) {
                                $error = UPLOAD_ERR_CANT_WRITE;
                            }
                        }
                        $uploadKey = $fileName;
                        // Parse upload files.
                        $file = [...$file, 'name' => $match[2], 'tmp_name' => $tmpFile, 'size' => $size, 'error' => $error];
                        if (!isset($file['type'])) {
                            $file['type'] = '';
                        }
                        break;
                    }
                    // Is post field.
                    // Parse $POST.
                    if (preg_match('/name="(.*?)"$/', $value, $match)) {
                        $k = $match[1];
                        $postEncodeString .= urlencode($k) . "=" . urlencode($boundaryValue) . '&';
                    }
                    return $sectionEndOffset + strlen($boundary) + 2;
                case "content-type":
                    $file['type'] = trim($value);
                    break;
            }
        }
        if ($uploadKey === false) {
            return 0;
        }
        $filesEncodeStr .= urlencode($uploadKey) . '=' . count($files) . '&';
        $files[] = $file;

        return $sectionEndOffset + strlen($boundary) + 2;
    }

    /**
     * Create session id.
     *
     * @return string
     * @throws Exception
     */
    public static function createSessionId(): string
    {
        return bin2hex(pack('d', microtime(true)) . random_bytes(8));
    }

    /**
     * @param string $sessionName
     * @param string $sid
     * @param array $cookieParams
     * @return void
     */
    protected function setSidCookie(string $sessionName, string $sid, array $cookieParams): void
    {
        if (!$this->connection) {
            throw new RuntimeException('Request->setSidCookie() fail, header already send');
        }
        $this->connection->headers['Set-Cookie'] = [$sessionName . '=' . $sid
            . (empty($cookieParams['domain']) ? '' : '; Domain=' . $cookieParams['domain'])
            . (empty($cookieParams['lifetime']) ? '' : '; Max-Age=' . $cookieParams['lifetime'])
            . (empty($cookieParams['path']) ? '' : '; Path=' . $cookieParams['path'])
            . (empty($cookieParams['samesite']) ? '' : '; SameSite=' . $cookieParams['samesite'])
            . (!$cookieParams['secure'] ? '' : '; Secure')
            . (!$cookieParams['httponly'] ? '' : '; HttpOnly')];
    }

    /**
     * __toString.
     */
    public function __toString()
    {
        return $this->buffer;
    }

    /**
     * Setter.
     *
     * @param string $name
     * @param mixed $value
     * @return void
     */
    public function __set(string $name, mixed $value)
    {
        $this->properties[$name] = $value;
    }

    /**
     * Getter.
     *
     * @param string $name
     * @return mixed|null
     */
    public function __get(string $name)
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Isset.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name)
    {
        return isset($this->properties[$name]);
    }

    /**
     * Unset.
     *
     * @param string $name
     * @return void
     */
    public function __unset(string $name)
    {
        unset($this->properties[$name]);
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        if (isset($this->data['files'])) {
            clearstatcache();
            array_walk_recursive($this->data['files'], function ($value, $key) {
                if ($key === 'tmp_name' && is_file($value)) {
                    unlink($value);
                }
            });
        }
    }
}
