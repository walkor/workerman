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

namespace Workerman\Protocols;

use Workerman\Connection\TcpConnection;
use Workerman\Protocols\Http\Request;
use Workerman\Protocols\Http\Response;

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
    protected static $requestClass = Request::class;

    /**
     * Upload tmp dir.
     *
     * @var string
     */
    protected static $uploadTmpDir = '';

    /**
     * Cache.
     *
     * @var bool.
     */
    protected static $enableCache = true;

    /**
     * Get or set the request class name.
     *
     * @param string|null $className
     * @return string
     */
    public static function requestClass($className = null)
    {
        if ($className) {
            static::$requestClass = $className;
        }
        return static::$requestClass;
    }

    /**
     * Enable or disable Cache.
     *
     * @param mixed $value
     */
    public static function enableCache($value)
    {
        static::$enableCache = (bool)$value;
    }

    /**
     * Check the integrity of the package.
     *
     * @param string $recvBuffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input(string $recvBuffer, TcpConnection $connection)
    {
        static $input = [];
        if (!isset($recvBuffer[512]) && isset($input[$recvBuffer])) {
            return $input[$recvBuffer];
        }
        $crlfPos = \strpos($recvBuffer, "\r\n\r\n");
        if (false === $crlfPos) {
            // Judge whether the package length exceeds the limit.
            if (\strlen($recvBuffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }

        $length = $crlfPos + 4;
        $firstLine = \explode(" ", \strstr($recvBuffer, "\r\n", true), 3);

        if (!\in_array($firstLine[0], ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }

        $header = \substr($recvBuffer, 0, $crlfPos);
        $hostHeaderPosition = \strpos($header, "\r\nHost: ");

        if (false === $hostHeaderPosition && $firstLine[2] === "HTTP/1.1") {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }

        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int)\substr($header, $pos + 18, 10);
            $hasContentLength = true;
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length = $length + $match[1];
            $hasContentLength = true;
        } else {
            $hasContentLength = false;
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
        }

        if ($hasContentLength) {
            if ($length > $connection->maxPackageSize) {
                $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
                return 0;
            }
        }

        if (!isset($recvBuffer[512])) {
            $input[$recvBuffer] = $length;
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * Http decode.
     *
     * @param string $recvBuffer
     * @param TcpConnection $connection
     * @return \Workerman\Protocols\Http\Request
     */
    public static function decode($recvBuffer, TcpConnection $connection)
    {
        static $requests = [];
        $cacheable = static::$enableCache && !isset($recvBuffer[512]);
        if (true === $cacheable && isset($requests[$recvBuffer])) {
            $request = clone $requests[$recvBuffer];
            $request->connection = $connection;
            $connection->request = $request;
            $request->properties = [];
            return $request;
        }
        $request = new static::$requestClass($recvBuffer);
        $request->connection = $connection;
        $connection->request = $request;
        if (true === $cacheable) {
            $requests[$recvBuffer] = $request;
            if (\count($requests) > 512) {
                unset($requests[\key($requests)]);
            }
        }
        return $request;
    }

    /**
     * Http encode.
     *
     * @param string|Response $response
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode($response, TcpConnection $connection)
    {
        if (isset($connection->request)) {
            $connection->request->session = null;
            $connection->request->connection = null;
            $connection->request = null;
        }
        if (!\is_object($response)) {
            $extHeader = '';
            if (isset($connection->header)) {
                foreach ($connection->header as $name => $value) {
                    if (\is_array($value)) {
                        foreach ($value as $item) {
                            $extHeader = "$name: $item\r\n";
                        }
                    } else {
                        $extHeader = "$name: $value\r\n";
                    }
                }
                unset($connection->header);
            }
            $bodyLen = \strlen((string)$response);
            return "HTTP/1.1 200 OK\r\nServer: workerman\r\n{$extHeader}Connection: keep-alive\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $bodyLen\r\n\r\n$response";
        }

        if (isset($connection->header)) {
            $response->withHeaders($connection->header);
            unset($connection->header);
        }

        if (isset($response->file)) {
            $file = $response->file['file'];
            $offset = $response->file['offset'];
            $length = $response->file['length'];
            \clearstatcache();
            $fileSize = (int)\filesize($file);
            $bodyLen = $length > 0 ? $length : $fileSize - $offset;
            $response->withHeaders([
                'Content-Length' => $bodyLen,
                'Accept-Ranges' => 'bytes',
            ]);
            if ($offset || $length) {
                $offsetEnd = $offset + $bodyLen - 1;
                $response->header('Content-Range', "bytes $offset-$offsetEnd/$fileSize");
            }
            if ($bodyLen < 2 * 1024 * 1024) {
                $connection->send((string)$response . file_get_contents($file, false, null, $offset, $bodyLen), true);
                return '';
            }
            $handler = \fopen($file, 'r');
            if (false === $handler) {
                $connection->close(new Response(403, null, '403 Forbidden'));
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
    protected static function sendStream(TcpConnection $connection, $handler, $offset = 0, $length = 0)
    {
        $connection->context->bufferFull = false;
        $connection->context->streamSending = true;
        if ($offset !== 0) {
            \fseek($handler, $offset);
        }
        $offsetEnd = $offset + $length;
        // Read file content from disk piece by piece and send to client.
        $doWrite = function () use ($connection, $handler, $length, $offsetEnd) {
            // Send buffer not full.
            while ($connection->context->bufferFull === false) {
                // Read from disk.
                $size = 1024 * 1024;
                if ($length !== 0) {
                    $tell = \ftell($handler);
                    $remainSize = $offsetEnd - $tell;
                    if ($remainSize <= 0) {
                        fclose($handler);
                        $connection->onBufferDrain = null;
                        return;
                    }
                    $size = $remainSize > $size ? $size : $remainSize;
                }

                $buffer = \fread($handler, $size);
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
     * @return bool|string
     */
    public static function uploadTmpDir($dir = null)
    {
        if (null !== $dir) {
            static::$uploadTmpDir = $dir;
        }
        if (static::$uploadTmpDir === '') {
            if ($uploadTmpDir = \ini_get('upload_tmp_dir')) {
                static::$uploadTmpDir = $uploadTmpDir;
            } else if ($uploadTmpDir = \sys_get_temp_dir()) {
                static::$uploadTmpDir = $uploadTmpDir;
            }
        }
        return static::$uploadTmpDir;
    }
}
