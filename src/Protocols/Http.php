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
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function ini_get;
use function is_array;
use function is_object;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strpos;
use function substr;
use function sys_get_temp_dir;

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
        $crlfPos = strpos($buffer, "\r\n\r\n");
        if (false === $crlfPos) {
            // Judge whether the package length exceeds the limit.
            if (strlen($buffer) >= 16384) {
                $connection->end("HTTP/1.1 413 Payload Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", true);
            }
            return 0;
        }

        $length = $crlfPos + 4;
        // Only slice when necessary (avoid extra string copy).
        // Keep the trailing "\r\n\r\n" in $header for simpler/faster validation patterns.
        $header = isset($buffer[$length]) ? substr($buffer, 0, $length) : $buffer;

        // Validate request line: METHOD SP request-target SP HTTP/1.0|1.1 CRLF
        // Request-target validation:
        // - Allow origin-form only (must start with "/") for all methods below.
        // - Do NOT support asterisk-form ("*") for OPTIONS.
        // - For compatibility, allow any bytes except ASCII control characters, spaces and DEL in request-target.
        //   (Strictly speaking, URI should be ASCII and non-ASCII should be percent-encoded; but many clients send UTF-8.)
        // - Disallow "Transfer-Encoding" header (case-insensitive; line-start must be "\r\n" to avoid matching "x-Transfer-Encoding").
        // - Optionally capture Content-Length (case-insensitive; line-start must be "\r\n" to avoid matching "x-Content-Length").
        // - If Content-Length exists, it must be a valid decimal number and the whole field-value must be digits + optional OWS.
        // - Disallow duplicate Content-Length headers.
        // Note: All lookaheads are placed at \A so they can scan the entire header including the request line.
        //       Use [ \t]* instead of \s* to avoid matching across lines.
        //       The pattern uses case-insensitive modifier (~i) for header name matching.
        $headerValidatePattern = '~\A'
            // Optional: capture Content-Length value (must be at \A to scan entire header)
            . '(?:(?=[\s\S]*\r\nContent-Length[ \t]*:[ \t]*(\d+)[ \t]*\r\n))?'
            // Disallow Transfer-Encoding header
            . '(?![\s\S]*\r\nTransfer-Encoding[ \t]*:)'
            // If Content-Length header exists, its value must be pure digits + optional OWS
            . '(?![\s\S]*\r\nContent-Length[ \t]*:(?![ \t]*\d+[ \t]*\r\n)[^\r]*\r\n)'
            // Disallow duplicate Content-Length headers (adjacent or separated)
            . '(?![\s\S]*\r\nContent-Length[ \t]*:[^\r\n]*\r\n(?:[\s\S]*?\r\n)?Content-Length[ \t]*:)'
            // Match request line: METHOD SP request-target SP HTTP-version CRLF
            . '(?:GET|POST|OPTIONS|HEAD|DELETE|PUT|PATCH) +\/[^\x00-\x20\x7f]* +HTTP\/1\.[01]\r\n~i';

        if (!preg_match($headerValidatePattern, $header, $matches)) {
            $connection->end("HTTP/1.1 400 Bad Request\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", true);
            return 0;
        }

        if (isset($matches[1])) {
            $length += (int)$matches[1];
        }

        if ($length > $connection->maxPackageSize) {
            $connection->end("HTTP/1.1 413 Payload Too Large\r\nConnection: close\r\nContent-Length: 0\r\n\r\n", true);
            return 0;
        }

        return $length;
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
        $request = new static::$requestClass($buffer);
        $request->connection = $connection;
        return $request;
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
            return "HTTP/1.1 200 OK\r\nServer: workerman\r\n{$extHeader}Connection: keep-alive\r\nContent-Type: $contentType\r\nContent-Length: $bodyLen\r\n\r\n$response";
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
