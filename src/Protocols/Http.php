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

namespace Workerman\Protocols;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;
use function clearstatcache;
use function count;
use function ctype_digit;
use function ctype_xdigit;
use function explode;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function hexdec;
use function ini_get;
use function is_array;
use function is_object;
use function ltrim;
use function preg_match;
use function preg_replace;
use function strlen;
use function strpos;
use function strtolower;
use function substr;
use function sys_get_temp_dir;
use function trim;

/**
 * Class Http.
 * @package Workerman\Protocols
 */
class Http
{
    /**
     * Request class name.
     *
     * @var string
     */
    protected static string $requestClass = Request::class;

    /**
     * Upload tmp dir.
     *
     * @var string
     */
    protected static string $uploadTmpDir = '';

    /**
     * Bad request.
     *
     * @var string
     */
    protected const HTTP_400 = "HTTP/1.1 400 Bad Request\r\nConnection: close\r\n\r\n";

    /**
     * Payload too large.
     *
     * @var string
     */
    protected const HTTP_413 = "HTTP/1.1 413 Payload Too Large\r\nConnection: close\r\n\r\n";

    /**
     * Max bytes buffered while waiting for end of headers, and max offset of "\r\n\r\n" (header block size limit).
     */
    protected const MAX_HEADER_LENGTH = 16384;

    /**
     * Get or set the request class name.
     *
     * @param class-string|null $className
     * @return string
     */
    public static function requestClass(?string $className = null): string
    {
        if ($className !== null) {
            static::$requestClass = $className;
        }
        return static::$requestClass;
    }

    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input(string $buffer, TcpConnection $connection): int
    {
        static $cache = [];

        $crlfPos = strpos($buffer, "\r\n\r\n");
        if (false === $crlfPos) {
            if (strlen($buffer) >= static::MAX_HEADER_LENGTH) {
                $connection->end(static::HTTP_413, true);
            }
            return 0;
        }

        $length = $crlfPos + 4;
        if ($crlfPos >= static::MAX_HEADER_LENGTH) {
            $connection->end(static::HTTP_413, true);
            return 0;
        }
        $header = isset($buffer[$length]) ? substr($buffer, 0, $length) : $buffer;

        if ($length <= TcpConnection::MAX_CACHE_STRING_LENGTH && isset($cache[$header])) {
            return $cache[$header];
        }

        // Validate request line: METHOD SP origin-form SP HTTP/1.x
        $firstLineEnd = strpos($header, "\r\n");
        if (!preg_match(
            '~^(?-i:GET|POST|OPTIONS|HEAD|DELETE|PUT|PATCH) /[^\x00-\x20\x7f]* (?-i:HTTP)/1\.(?<minor>[01])$~',
            substr($header, 0, $firstLineEnd),
            $matches
        )) {
            $connection->end(static::HTTP_400, true);
            return 0;
        }

        // Parse headers
        $headers = [];
        $headerBody = substr($header, $firstLineEnd + 2, $crlfPos - $firstLineEnd - 2);
        foreach (explode("\r\n", $headerBody) as $line) {
            if ($line === '') {
                continue;
            }
            $parts = explode(':', $line, 2);
            // field-name must be a token: 1*tchar (RFC 7230 §3.2.6)
            if (!isset($parts[1]) || !preg_match('/^[a-zA-Z0-9!#$%&\'*+\-.^_`|~]+$/', $parts[0])) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $headers[strtolower($parts[0])][] = trim($parts[1], " \t");
        }

        // Host: required for HTTP/1.1, must not be duplicated for any version (RFC 7230 §5.4)
        $hostCount = count($headers['host'] ?? []);
        if ($hostCount > 1 || ($matches['minor'] === '1' && $hostCount === 0)) {
            $connection->end(static::HTTP_400, true);
            return 0;
        }

        // Transfer-Encoding: must be sole header with value "chunked", no Content-Length
        if (isset($headers['transfer-encoding'])) {
            if (isset($headers['content-length'])
                || count($headers['transfer-encoding']) !== 1
                || strtolower($headers['transfer-encoding'][0]) !== 'chunked') {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            return static::inputChunked($buffer, $connection, $length);
        }

        // Content-Length: must be single header with pure-digit value
        if (isset($headers['content-length'])) {
            if (count($headers['content-length']) !== 1 || !ctype_digit($headers['content-length'][0])) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $length += (int)$headers['content-length'][0];
        }

        if ($length > $connection->maxPackageSize) {
            $connection->end(static::HTTP_413, true);
            return 0;
        }

        if ($length <= TcpConnection::MAX_CACHE_STRING_LENGTH) {
            $cache[$header] = $length;
            if (count($cache) > TcpConnection::MAX_CACHE_SIZE) {
                unset($cache[key($cache)]);
            }
        }
        return $length;
    }


    /**
     * Check the integrity of a chunked transfer-encoded request body.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @param int $headerLength
     * @return int
     */
    protected static function inputChunked(string $buffer, TcpConnection $connection, int $headerLength): int
    {
        $connection->context ??= new \stdClass();
        $connection->context->chunked = true;

        $pos = $headerLength;
        $bufLen = strlen($buffer);
        $maxSize = $connection->maxPackageSize;

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $pos);
            if ($lineEnd === false) {
                return 0;
            }

            $semiPos = strpos($buffer, ';', $pos);
            $hexEnd = ($semiPos !== false && $semiPos < $lineEnd) ? $semiPos : $lineEnd;
            $hexStr = substr($buffer, $pos, $hexEnd - $pos);

            if ($hexStr === '' || !ctype_xdigit($hexStr) || isset($hexStr[16])) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }

            $chunkSize = hexdec($hexStr);
            if (is_float($chunkSize)) {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $pos = $lineEnd + 2;

            if ($chunkSize === 0) {
                while (true) {
                    $lineEnd = strpos($buffer, "\r\n", $pos);
                    if ($lineEnd === false) {
                        return 0;
                    }
                    if ($lineEnd === $pos) {
                        $totalLength = $pos + 2;
                        if ($totalLength > $maxSize) {
                            $connection->end(static::HTTP_413, true);
                            return 0;
                        }
                        return $totalLength;
                    }
                    $pos = $lineEnd + 2;
                }
            }

            if ($pos + $chunkSize + 2 > $bufLen) {
                return 0;
            }
            if (substr($buffer, $pos + $chunkSize, 2) !== "\r\n") {
                $connection->end(static::HTTP_400, true);
                return 0;
            }
            $pos += $chunkSize + 2;

            if ($pos > $maxSize) {
                $connection->end(static::HTTP_413, true);
                return 0;
            }
        }
    }

    /**
     * Http decode.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return mixed
     */
    public static function decode(string $buffer, TcpConnection $connection): mixed
    {
        $trailers = [];
        if (isset($connection->context->chunked)) {
            unset($connection->context->chunked);
            [$buffer, $trailers] = static::decodeChunked($buffer, strpos($buffer, "\r\n\r\n"));
        }

        $request = new static::$requestClass($buffer);
        if ($trailers !== []) {
            $request->setChunkTrailers($trailers);
        }
        $request->connection = $connection;
        return $request;
    }

    /**
     * Decode a chunked transfer-encoded request into a normalized buffer.
     *
     * @param string $buffer
     * @param int $headerEnd
     * @return array{string, array}
     */
    protected static function decodeChunked(string $buffer, int $headerEnd): array
    {
        $header = preg_replace('~\r\nTransfer-Encoding[ \t]*:[^\r]*~i', '', substr($buffer, 0, $headerEnd), 1);
        $body = '';
        $trailers = [];
        $pos = $headerEnd + 4;
        $bufLen = strlen($buffer);

        while (true) {
            $lineEnd = strpos($buffer, "\r\n", $pos);
            if ($lineEnd === false) {
                break;
            }

            $semiPos = strpos($buffer, ';', $pos);
            $hexEnd = ($semiPos !== false && $semiPos < $lineEnd) ? $semiPos : $lineEnd;
            $hexStr = substr($buffer, $pos, $hexEnd - $pos);
            if ($hexStr === '' || !ctype_xdigit($hexStr) || isset($hexStr[16])) {
                break;
            }

            $chunkSize = hexdec($hexStr);
            if (is_float($chunkSize)) {
                break;
            }
            $pos = $lineEnd + 2;

            if ($chunkSize === 0) {
                while (true) {
                    $lineEnd = strpos($buffer, "\r\n", $pos);
                    if ($lineEnd === false) {
                        break 2;
                    }
                    if ($lineEnd === $pos) {
                        $pos += 2;
                        break;
                    }
                    $colonPos = strpos($buffer, ':', $pos);
                    if ($colonPos !== false && $colonPos < $lineEnd) {
                        $trailers[strtolower(substr($buffer, $pos, $colonPos - $pos))] = ltrim(substr($buffer, $colonPos + 1, $lineEnd - $colonPos - 1));
                    }
                    $pos = $lineEnd + 2;
                }
                break;
            }

            if ($pos + $chunkSize + 2 > $bufLen) {
                break;
            }
            if (substr($buffer, $pos + $chunkSize, 2) !== "\r\n") {
                break;
            }
            $body .= substr($buffer, $pos, $chunkSize);
            $pos += $chunkSize + 2;
        }

        return [$header . "\r\nContent-Length: " . strlen($body) . "\r\n\r\n" . $body, $trailers];
    }

    /**
     * Http encode.
     *
     * @param string|Response $response
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode(mixed $response, TcpConnection $connection): string
    {
        if (!is_object($response)) {
            $extHeader = '';
            $contentType = 'text/html;charset=utf-8';
            foreach ($connection->headers as $name => $value) {
                if ($name === 'Content-Type') {
                    $contentType = $value;
                    continue;
                }
                if (is_array($value)) {
                    foreach ($value as $item) {
                        $extHeader .= "$name: $item\r\n";
                    }
                } else {
                    $extHeader .= "$name: $value\r\n";
                }
            }
            $connection->headers = [];
            $response = (string)$response;
            $bodyLen = strlen($response);
            return "HTTP/1.1 200 OK\r\n{$extHeader}Connection: keep-alive\r\nContent-Type: $contentType\r\nContent-Length: $bodyLen\r\n\r\n$response";
        }

        if ($connection->headers) {
            $response->withHeaders($connection->headers);
            $connection->headers = [];
        }

        if (isset($response->file)) {
            $file = $response->file['file'];
            $offset = $response->file['offset'] ?: 0;
            $length = $response->file['length'] ?: 0;
            clearstatcache();
            $fileSize = (int)filesize($file);
            $bodyLen = $length > 0 ? $length : $fileSize - $offset;
            $response->withHeaders([
                'Content-Length' => $bodyLen,
                'Accept-Ranges' => 'bytes',
            ]);

            if ($offset || $length) {
                $offsetEnd = $offset + $bodyLen - 1;
                $response->header('Content-Range', "bytes $offset-$offsetEnd/$fileSize");
                $response->withStatus(206);
            }
            if ($bodyLen < 2 * 1024 * 1024) {
                $connection->send($response . file_get_contents($file, false, null, $offset, $bodyLen), true);
                return '';
            }
            $handler = fopen($file, 'r');
            if (false === $handler) {
                $connection->close(new Response(403, [], '403 Forbidden'));
                return '';
            }
            $connection->send((string)$response, true);
            static::sendStream($connection, $handler, $offset, $length);
            return '';
        }

        return (string)$response;
    }

    /**
     * Send remainder of a stream to client.
     *
     * @param TcpConnection $connection
     * @param resource $handler
     * @param int $offset
     * @param int $length
     */
    protected static function sendStream(TcpConnection $connection, $handler, int $offset = 0, int $length = 0): void
    {
        $connection->context->bufferFull = false;
        $connection->context->streamSending = true;
        if ($offset !== 0) {
            fseek($handler, $offset);
        }
        $offsetEnd = $offset + $length;
        // Read file content from disk piece by piece and send to client.
        $doWrite = function () use ($connection, $handler, $length, $offsetEnd) {
            // Send buffer not full.
            /** @phpstan-ignore-next-line */
            while ($connection->context->bufferFull === false) {
                // Read from disk.
                $size = 1024 * 1024;
                if ($length !== 0) {
                    $tell = ftell($handler);
                    $remainSize = $offsetEnd - $tell;
                    if ($remainSize <= 0) {
                        fclose($handler);
                        $connection->onBufferDrain = null;
                        return;
                    }
                    $size = min($remainSize, $size);
                }

                $buffer = fread($handler, $size);
                // Read eof.
                if ($buffer === '' || $buffer === false) {
                    fclose($handler);
                    $connection->onBufferDrain = null;
                    $connection->context->streamSending = false;
                    return;
                }
                $connection->send($buffer, true);
            }
        };
        // Send buffer full.
        $connection->onBufferFull = function ($connection) {
            $connection->context->bufferFull = true;
        };
        // Send buffer drain.
        $connection->onBufferDrain = function ($connection) use ($doWrite) {
            $connection->context->bufferFull = false;
            $doWrite();
        };
        $doWrite();
    }

    /**
     * Set or get uploadTmpDir.
     *
     * @param string|null $dir
     * @return string
     */
    public static function uploadTmpDir(string|null $dir = null): string
    {
        if (null !== $dir) {
            static::$uploadTmpDir = $dir;
        }
        if (static::$uploadTmpDir === '') {
            if ($uploadTmpDir = ini_get('upload_tmp_dir')) {
                static::$uploadTmpDir = $uploadTmpDir;
            } else if ($uploadTmpDir = sys_get_temp_dir()) {
                static::$uploadTmpDir = $uploadTmpDir;
            }
        }
        return static::$uploadTmpDir;
    }
}
