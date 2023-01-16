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
     * @param string|null $class_name
     * @return string
     */
    public static function requestClass($class_name = null)
    {
        if ($class_name) {
            static::$requestClass = $class_name;
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
     * @param string $recv_buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input(string $recv_buffer, TcpConnection $connection)
    {
        static $input = [];
        if (!isset($recv_buffer[512]) && isset($input[$recv_buffer])) {
            return $input[$recv_buffer];
        }
        $crlf_pos = \strpos($recv_buffer, "\r\n\r\n");
        if (false === $crlf_pos) {
            // Judge whether the package length exceeds the limit.
            if (\strlen($recv_buffer) >= 16384) {
                $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
                return 0;
            }
            return 0;
        }

        $length = $crlf_pos + 4;
        $firstLine = \explode(" ", \strstr($recv_buffer, "\r\n", true), 3);

        if (!\in_array($firstLine[0], ['GET', 'POST', 'OPTIONS', 'HEAD', 'DELETE', 'PUT', 'PATCH'])) {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }

        $header = \substr($recv_buffer, 0, $crlf_pos);
        $hostHeaderPosition = \strpos($header, "\r\nHost: ");

        if (false === $hostHeaderPosition && $firstLine[2] === "HTTP/1.1") {
            $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
            return 0;
        }

        if ($pos = \strpos($header, "\r\nContent-Length: ")) {
            $length = $length + (int)\substr($header, $pos + 18, 10);
            $has_content_length = true;
        } else if (\preg_match("/\r\ncontent-length: ?(\d+)/i", $header, $match)) {
            $length = $length + $match[1];
            $has_content_length = true;
        } else {
            $has_content_length = false;
            if (false !== stripos($header, "\r\nTransfer-Encoding:")) {
                $connection->close("HTTP/1.1 400 Bad Request\r\n\r\n", true);
                return 0;
            }
        }

        if ($has_content_length) {
            if ($length > $connection->maxPackageSize) {
                $connection->close("HTTP/1.1 413 Payload Too Large\r\n\r\n", true);
                return 0;
            }
        }

        if (!isset($recv_buffer[512])) {
            $input[$recv_buffer] = $length;
            if (\count($input) > 512) {
                unset($input[key($input)]);
            }
        }

        return $length;
    }

    /**
     * Http decode.
     *
     * @param string $recv_buffer
     * @param TcpConnection $connection
     * @return \Workerman\Protocols\Http\Request
     */
    public static function decode($recv_buffer, TcpConnection $connection)
    {
        static $requests = [];
        $cacheable = static::$enableCache && !isset($recv_buffer[512]);
        if (true === $cacheable && isset($requests[$recv_buffer])) {
            $request = clone $requests[$recv_buffer];
            $request->connection = $connection;
            $connection->request = $request;
            $request->properties = [];
            return $request;
        }
        $request = new static::$requestClass($recv_buffer);
        $request->connection = $connection;
        $connection->request = $request;
        if (true === $cacheable) {
            $requests[$recv_buffer] = $request;
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
            $ext_header = '';
            if (isset($connection->header)) {
                foreach ($connection->header as $name => $value) {
                    if (\is_array($value)) {
                        foreach ($value as $item) {
                            $ext_header = "$name: $item\r\n";
                        }
                    } else {
                        $ext_header = "$name: $value\r\n";
                    }
                }
                unset($connection->header);
            }
            $body_len = \strlen((string)$response);
            return "HTTP/1.1 200 OK\r\nServer: workerman\r\n{$ext_header}Connection: keep-alive\r\nContent-Type: text/html;charset=utf-8\r\nContent-Length: $body_len\r\n\r\n$response";
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
            $file_size = (int)\filesize($file);
            $body_len = $length > 0 ? $length : $file_size - $offset;
            $response->withHeaders([
                'Content-Length' => $body_len,
                'Accept-Ranges' => 'bytes',
            ]);
            if ($offset || $length) {
                $offset_end = $offset + $body_len - 1;
                $response->header('Content-Range', "bytes $offset-$offset_end/$file_size");
            }
            if ($body_len < 2 * 1024 * 1024) {
                $connection->send((string)$response . file_get_contents($file, false, null, $offset, $body_len), true);
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
        $connection->bufferFull = false;
        $connection->context->streamSending = true;
        if ($offset !== 0) {
            \fseek($handler, $offset);
        }
        $offset_end = $offset + $length;
        // Read file content from disk piece by piece and send to client.
        $do_write = function () use ($connection, $handler, $length, $offset_end) {
            // Send buffer not full.
            while ($connection->bufferFull === false) {
                // Read from disk.
                $size = 1024 * 1024;
                if ($length !== 0) {
                    $tell = \ftell($handler);
                    $remain_size = $offset_end - $tell;
                    if ($remain_size <= 0) {
                        fclose($handler);
                        $connection->onBufferDrain = null;
                        return;
                    }
                    $size = $remain_size > $size ? $size : $remain_size;
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
            $connection->bufferFull = true;
        };
        // Send buffer drain.
        $connection->onBufferDrain = function ($connection) use ($do_write) {
            $connection->bufferFull = false;
            $do_write();
        };
        $do_write();
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
            if ($upload_tmp_dir = \ini_get('upload_tmp_dir')) {
                static::$uploadTmpDir = $upload_tmp_dir;
            } else if ($upload_tmp_dir = \sys_get_temp_dir()) {
                static::$uploadTmpDir = $upload_tmp_dir;
            }
        }
        return static::$uploadTmpDir;
    }
}
