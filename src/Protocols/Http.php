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
use function explode;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function ftell;
use function in_array;
use function ini_get;
use function is_array;
use function is_object;
use function preg_match;
use function str_starts_with;
use function strlen;
use function strpos;
use function strstr;
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
                $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
            }
            return 0;
        }

        $length = $crlfPos + 4;
        $header = substr($buffer, 0, $crlfPos);

        if (
            !str_starts_with($header, 'GET ') &&
            !str_starts_with($header, 'POST ') &&
            !str_starts_with($header, 'OPTIONS ') &&
            !str_starts_with($header, 'HEAD ') &&
            !str_starts_with($header, 'DELETE ') &&
            !str_starts_with($header, 'PUT ') &&
            !str_starts_with($header, 'PATCH ')
        ) {
            $connection->close("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n", true);
            return 0;
        }

        if (preg_match('/\b(?:Transfer-Encoding\b.*)|(?:Content-Length:\s*(\d+)(?!.*\bTransfer-Encoding\b))/is', $header, $matches)) {
            if (!isset($matches[1])) {
                $connection->close("HTTP/1.1 400 Bad Request\r\nContent-Length: 0\r\n\r\n", true);
                return 0;
            }
            $length += (int)$matches[1];
        }

        if ($length > $connection->maxPackageSize) {
            $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
            return 0;
        }

        return $length;
    }


    /**
     * Http decode.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return Request
     */
    public static function decode(string $buffer, TcpConnection $connection): Request
    {
        static $requests = [];
        if (isset($requests[$buffer])) {
            $request = $requests[$buffer];
            $request->connection = $connection;
            $connection->request = $request;
            $request->destroy();
            return $request;
        }
        $request = new static::$requestClass($buffer);
        if (!isset($buffer[TcpConnection::MAX_CACHE_STRING_LENGTH])) {
            $requests[$buffer] = $request;
            if (count($requests) > TcpConnection::MAX_CACHE_SIZE) {
                unset($requests[key($requests)]);
            }
            $request = clone $request;
        }
        $request->connection = $connection;
        $connection->request = $request;
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
        $request = null;
        if (isset($connection->request)) {
            $request = $connection->request;
            $request->connection = $connection->request = null;
        }

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
            $requestRange = [0, 0];
            if ($value = $request?->header('range')) {
                if (str_starts_with($value, 'bytes=')) {
                    $arr = explode('-', substr($value, 6));
                    if (count($arr) === 2) {
                        $requestRange = [(int)$arr[0], (int)$arr[1]];
                    }
                }
            }

            $file = $response->file['file'];
            $offset = $response->file['offset'] ?: $requestRange[0];
            $length = $response->file['length'] ?: $requestRange[1];
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
