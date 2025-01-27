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
use Stringable;
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
class Request implements Stringable
{
    /**
     * Connection.
     *
     * @var ?TcpConnection
     */
    public ?TcpConnection $connection = null;

    /**
     * @var int
     */
    public static int $maxFileUploads = 1024;

    /**
     * Maximum string length for cache
     *
     * @var int
     */
    public const MAX_CACHE_STRING_LENGTH = 4096;

    /**
     * Maximum cache size.
     *
     * @var int
     */
    public const MAX_CACHE_SIZE = 256;

    /**
     * Properties.
     *
     * @var array
     */
    public array $properties = [];

    /**
     * Request data.
     *
     * @var array
     */
    protected array $data = [];

    /**
     * Is safe.
     *
     * @var bool
     */
    protected bool $isSafe = true;

    /**
     * Context.
     *
     * @var array
     */
    public array $context = [];

    /**
     * Request constructor.
     *
     */
    public function __construct(protected string $buffer) {}

    /**
     * Get query.
     *
     * @param string|null $name
     * @param mixed $default
     * @return mixed
     */
    public function get(?string $name = null, mixed $default = null): mixed
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
     * @param mixed $default
     * @return mixed
     */
    public function post(?string $name = null, mixed $default = null): mixed
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
     * @param mixed $default
     * @return mixed
     */
    public function header(?string $name = null, mixed $default = null): mixed
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
     * @param mixed $default
     * @return mixed
     */
    public function cookie(?string $name = null, mixed $default = null): mixed
    {
        if (!isset($this->data['cookie'])) {
            $cookies = explode(';', $this->header('cookie', ''));
            $mapped = array();

            foreach ($cookies as $cookie) {
                $cookie = explode('=', $cookie, 2);
                if (count($cookie) !== 2) {
                    continue;
                }
                $mapped[trim($cookie[0])] = $cookie[1];
            }
            $this->data['cookie'] = $mapped;
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
    public function file(?string $name = null): mixed
    {
        clearstatcache();
        if (!empty($this->data['files'])) {
            array_walk_recursive($this->data['files'], function ($value, $key) {
                if ($key === 'tmp_name' && !is_file($value)) {
                    $this->data['files'] = [];
                }
            });
        }
        if (empty($this->data['files'])) {
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
        if ($host && $withoutPort) {
            return preg_replace('/:\d{1,5}$/', '', $host);
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
        return $this->data['path'] ??= (string)parse_url($this->uri(), PHP_URL_PATH);
    }

    /**
     * Get query string.
     *
     * @return string
     */
    public function queryString(): string
    {
        return $this->data['query_string'] ??= (string)parse_url($this->uri(), PHP_URL_QUERY);
    }

    /**
     * Get session.
     *
     * @return Session
     * @throws Exception
     */
    public function session(): Session
    {
        return $this->context['session'] ??= new Session($this->sessionId());
    }

    /**
     * Get/Set session id.
     *
     * @param string|null $sessionId
     * @return string
     * @throws Exception
     */
    public function sessionId(?string $sessionId = null): string
    {
        if ($sessionId) {
            unset($this->context['sid']);
        }
        if (!isset($this->context['sid'])) {
            $sessionName = Session::$name;
            $sid = $sessionId ? '' : $this->cookie($sessionName);
            $sid = $this->isValidSessionId($sid) ? $sid : '';
            if ($sid === '') {
                if (!$this->connection) {
                    throw new RuntimeException('Request->session() fail, header already send');
                }
                $sid = $sessionId ?: static::createSessionId();
                $cookieParams = Session::getCookieParams();
                $this->setSidCookie($sessionName, $sid, $cookieParams);
            }
            $this->context['sid'] = $sid;
        }
        return $this->context['sid'];
    }

    /**
     * Check if session id is valid.
     *
     * @param mixed $sessionId
     * @return bool
     */
    public function isValidSessionId(mixed $sessionId): bool
    {
        return is_string($sessionId) && preg_match('/^[a-zA-Z0-9"]+$/', $sessionId);
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
        return $this->data['head'] ??= strstr($this->buffer, "\r\n\r\n", true);
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
        $httpStr = strstr($firstLine, 'HTTP/');
        $protocolVersion = $httpStr ? substr($httpStr, 5) : '1.0';
        $this->data['protocolVersion'] = $protocolVersion;
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
        $cacheable = !isset($headBuffer[static::MAX_CACHE_STRING_LENGTH]);
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
            if (count($cache) > static::MAX_CACHE_SIZE) {
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
        $cacheable = !isset($queryString[static::MAX_CACHE_STRING_LENGTH]);
        if ($cacheable && isset($cache[$queryString])) {
            $this->data['get'] = $cache[$queryString];
            return;
        }
        parse_str($queryString, $this->data['get']);
        if ($cacheable) {
            $cache[$queryString] = $this->data['get'];
            if (count($cache) > static::MAX_CACHE_SIZE) {
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
        $cacheable = !isset($bodyBuffer[static::MAX_CACHE_STRING_LENGTH]);
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
            if (count($cache) > static::MAX_CACHE_SIZE) {
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
     * @param string $boundary
     * @param int $sectionStartOffset
     * @param string $postEncodeString
     * @param string $filesEncodeStr
     * @param array $files
     * @return int
     */
    protected function parseUploadFile(string $boundary, int $sectionStartOffset, string &$postEncodeString, string &$filesEncodeStr, array &$files): int
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
                        $file = [...$file, 'name' => $match[2], 'tmp_name' => $tmpFile, 'size' => $size, 'error' => $error, 'full_path' => $match[2]];
                        $file['type'] ??= '';
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

                case "webkitrelativepath":
                    $file['full_path'] = trim($value);
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
    public function __toString(): string
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
    public function __set(string $name, mixed $value): void
    {
        $this->properties[$name] = $value;
    }

    /**
     * Getter.
     *
     * @param string $name
     * @return mixed
     */
    public function __get(string $name): mixed
    {
        return $this->properties[$name] ?? null;
    }

    /**
     * Isset.
     *
     * @param string $name
     * @return bool
     */
    public function __isset(string $name): bool
    {
        return isset($this->properties[$name]);
    }

    /**
     * Unset.
     *
     * @param string $name
     * @return void
     */
    public function __unset(string $name): void
    {
        unset($this->properties[$name]);
    }

    /**
     * __wakeup.
     *
     * @return void
     */
    public function __wakeup(): void
    {
        $this->isSafe = false;
    }

    /**
     * Destroy.
     *
     * @return void
     */
    public function destroy(): void
    {
        if ($this->context) {
            $this->context  = [];
        }
        if ($this->properties) {
            $this->properties = [];
        }
        if (isset($this->data['files']) && $this->isSafe) {
            clearstatcache();
            array_walk_recursive($this->data['files'], function ($value, $key) {
                if ($key === 'tmp_name' && is_file($value)) {
                    unlink($value);
                }
            });
        }
    }

}
