<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    爬山虎<blogdaren@163.com>
 * @protocol  http://www.mit.edu/~yandros/doc/specs/fcgi-spec.html  
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Protocols;

use Workerman\Worker;
use Workerman\Connection\TcpConnection;
use Workerman\Protocols\FastCGI\Request;
use Workerman\Protocols\FastCGI\Response;

class Fcgi
{
    /**
     * the version of fcgi protocol
     *
     * @var int
     */
    const FCGI_VERSION_1 = 1;

    /**
     * the fixed length of FCGI_Header: sizeof(FCGI_Header) === 8
     *
     * typedef struct {
     *     unsigned char version;
     *     unsigned char type;
     *     unsigned char requestIdB1;
     *     unsigned char requestIdB0;
     *     unsigned char contentLengthB1;
     *     unsigned char contentLengthB0;
     *     unsigned char paddingLength;
     *     unsigned char reserved;
     * } FCGI_Header;
     *
     * @var int
     */
    const FCGI_HEADER_LEN = 8;

    /**
     * the max length of payload 
     *
     * @var int
     */
    const FCGI_MAX_PAYLOAD_LEN = 65535;

    /**
     * the reserved bit FCGI_Header
     *
     * @var string
     */
    const FCGI_RESERVED = '';

    /**
     * the padding bit FCGI_Header
     *
     * @var string
     */
    const FCGI_PADDING = '';

    /**
     * the record type of FCGI_BEGIN_REQUEST
     *
     * @var int
     */
    const FCGI_BEGIN_REQUEST = 1;

    /**
     * the record type of FCGI_ABORT_REQUEST
     *
     * @var int
     */
    const FCGI_ABORT_REQUEST = 2;

    /**
     * the record type of FCGI_END_REQUEST
     *
     * @var int
     */
    const FCGI_END_REQUEST = 3;

    /**
     * the record type of FCGI_PARAMS
     *
     * @var int
     */
    const FCGI_PARAMS = 4;

    /**
     * -------------------------------------
     * the pseudo record type of FCGI_PARAMS
     * -------------------------------------
     *
     * @var int
     */
    const FCGI_PARAMS_END = 4 << 3;

    /**
     * the record type of FCGI_STDIN
     *
     * @var int
     */
    const FCGI_STDIN = 5;

    /**
     * -------------------------------------
     * the pseudo record type of FCGI_STDIN
     * -------------------------------------
     *
     * @var int
     */
    const FCGI_STDIN_END = 5 << 4;

    /**
     * the record type of FCGI_STDOUT
     *
     * @var int
     */
    const FCGI_STDOUT = 6;

    /**
     * the record type of FCGI_STDERR
     *
     * @var int
     */
    const FCGI_STDERR = 7;

    /**
     * the record type of FCGI_DATA
     *
     * @var int
     */
    const FCGI_DATA = 8;

    /**
     * the record type of FCGI_GET_VALUES
     *
     * @var int
     */
    const FCGI_GET_VALUES = 9;

    /**
     * the record type of FCGI_GET_VALUES_RESULT
     *
     * @var int
     */
    const FCGI_GET_VALUES_RESULT = 10;

    /**
     * the record type of FCGI_UNKNOWN_TYPE
     *
     * @var int
     */
    const FCGI_UNKNOWN_TYPE = 11;

    /**
     * the role type of FCGI_RESPONDER
     *
     * @var int
     */
    const FCGI_RESPONDER = 1;

    /**
     * the role type of FCGI_AUTHORIZER
     *
     * @var int
     */
    const FCGI_AUTHORIZER = 2;

    /**
     * the role type of FCGI_FILTER
     *
     * @var int
     */
    const FCGI_FILTER = 3;

    /**
     * the protocol status of FCGI_REQUEST_COMPLETE
     *
     * @var int
     */
    const FCGI_REQUEST_COMPLETE = 0; 

    /**
     * the protocol status of FCGI_CANT_MPX_CONN
     *
     * @var int
     */
    const FCGI_CANT_MPX_CONN = 1;  

    /**
     * the protocol status of FCGI_OVERLOADED
     *
     * @var int
     */
    const FCGI_OVERLOADED = 2;  

    /**
     * the protocol status of FCGI_UNKNOWN_ROLE
     *
     * @var int
     */
    const FCGI_UNKNOWN_ROLE = 3;  

    /**
     * the request object
     *
     * @var object
     */
    static private $_request = NULL;

    /**
     * check the integrity of the package
     *
     * @param   string              $buffer
     * @param   TcpConnection       $connection
     *
     * @return  int
     */
    public static function input($buffer, TcpConnection $connection)
    {
        $recv_len = \strlen($buffer);

        if($recv_len < static::FCGI_HEADER_LEN) return 0;

        if(!isset($connection->packetLength)) $connection->packetLength = 0;

        $data = \unpack("Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved", $buffer);
        if(false === $data) return 0;

        $chunk_len = static::FCGI_HEADER_LEN + $data['contentLength'] + $data['paddingLength'];
        if($recv_len < $chunk_len) return 0;

        if(static::FCGI_END_REQUEST != $data['type'])
        {
            $connection->packetLength += $chunk_len;
            $next_chunk_len = static::input(\substr($buffer, $chunk_len), $connection);

            if(0 == $next_chunk_len) 
            {
                //important!! don't forget to reset to zero byte!!
                $connection->packetLength = 0;
                return 0;
            }
        }
        else
        {
            $connection->packetLength += $chunk_len;
        }

        //check package length exceeds the max package length or not
        if($connection->packetLength > $connection->maxPackageSize) 
        {
            $msg  = "Exception: recv error package. package_length = {$connection->packetLength} ";
            $msg .= "exceeds the limit {$connection->maxPackageSize}" . PHP_EOL;
            Worker::safeEcho($msg);
            $connection->close();
            return 0;
        }

        return $connection->packetLength;
    }

    /**
     * @brief   decode package
     *
     * @param   string              $buffer
     * @param   TcpConnection       $connection
     *
     * @return  array
     */
    public static function decode($buffer, TcpConnection $connection)
    {
        $offset = 0;
        $stdout = $stderr = '';

        do
        {
            $header_buffer = \substr($buffer, $offset, static::FCGI_HEADER_LEN);
            $data = \unpack("Cversion/Ctype/nrequestId/ncontentLength/CpaddingLength/Creserved", $header_buffer);

            //we are not going to throw new \Exception("Failed to unpack header data from the binary buffer.");
            //but just break out of the loop to avoid bring much unnecessary TCP connections with TIME_WAIT status
            if(false === $data) 
            {
                $stderr = "Failed to unpack header data from the binary buffer";
                Worker::safeEcho($stderr);
                $connection->close();
                break;
            }

            $chunk_len = static::FCGI_HEADER_LEN + $data['contentLength'] + $data['paddingLength'];
            $body_buffer = \substr($buffer, $offset + static::FCGI_HEADER_LEN, $chunk_len - static::FCGI_HEADER_LEN);


            switch($data['type'])
            {
                case static::FCGI_STDOUT:
                    $payload = \unpack("a{$data['contentLength']}contentData/a{$data['paddingLength']}paddingData", $body_buffer);
                    $stdout .= $payload['contentData']; 
                    break;
                case static::FCGI_STDERR:
                    $payload = \unpack("a{$data['contentLength']}contentData/a{$data['paddingLength']}paddingData", $body_buffer);
                    $stderr .= $payload['contentData']; 
                    break;
                case static::FCGI_END_REQUEST:
                    $payload = \unpack("NappStatus/CprotocolStatus/a3reserved", $body_buffer);
                    $result = static::checkProtocolStatus($payload['protocolStatus']);

                    if(0 <> $result['code']) 
                    {
                        $stderr = $result['msg']; 
                        Worker::safeEcho($stderr);
                        $connection->close();
                    }
                    break;
                default:
                    //not support yet
                    $payload = '';
                    break;
            }

            $offset += $chunk_len;
        }while($offset < $connection->packetLength);

        //important!! don't forget to reset to zero byte!! 
        $connection->packetLength = 0;

        //build response
        $response = new Response();
        $output = $response->setRequestId($data['requestId'] ?? -1)
            ->setStdout($stdout)
            ->setStderr($stderr)
            ->formatOutput();

        //trigger user callback as onResponse
        if(!empty($connection->onResponse) && is_callable($connection->onResponse)) 
        {
            try {
                \call_user_func($connection->onResponse, $connection, $response);
            } catch (\Exception $e) {
                $msg = "Exception: onResponse: " . $e->getMessage();
                Worker::safeEcho($msg);
                $connection->close();
            } catch (\Error $e) {
                $msg = "Exception: onResponse: " . $e->getMessage();
                Worker::safeEcho($msg);
                $connection->close();
            }
        }

        return $output;
    }

    /**
     * @brief   encode package 
     *
     * @param   Request                 $request
     * @param   TcpConnection           $connection
     *
     * @return  string
     */
    public static function encode(Request $request, TcpConnection $connection)
    {
        if(!$request instanceof Request) return '';

        static::$_request = $request;

        $packet = '';
        $packet .= static::createPacket(static::FCGI_BEGIN_REQUEST);
        $packet .= static::createPacket(static::FCGI_PARAMS);
        $packet .= static::createPacket(static::FCGI_PARAMS_END);
        $packet .= static::createPacket(static::FCGI_STDIN);
        $packet .= static::createPacket(static::FCGI_STDIN_END);

        $connection->maxSendBufferSize = TcpConnection::$defaultMaxSendBufferSize * 10;
        $packet_len = \strlen($packet);

        if($packet_len > $connection->maxSendBufferSize) 
        {
            $msg  = "Exception: send error package. package_length = {$packet_len} ";
            $msg .= "exceeds the limit {$connection->maxSendBufferSize}" . PHP_EOL;
            Worker::safeEcho($msg);
            $connection->close();
            return '';
        }

        return $packet;
    }

    /**
     * @brief    pack payload 
     *
     * @param    string  $type
     *
     * @return   string
     */
    static private function packPayload($type = '')
    {
        $payload = '';

        switch($type)
        {
            case static::FCGI_BEGIN_REQUEST:
                $payload = \pack(
                    "nCa5",
                    static::$_request->getRole(),
                    static::$_request->getKeepAlive(),
                    static::FCGI_RESERVED
                );  
                break;
            case static::FCGI_PARAMS:
            case static::FCGI_PARAMS_END:
                $payload = '';
                $params = (static::FCGI_PARAMS == $type) ? static::$_request->getParams() : [];
                foreach($params as $name => $value) 
                {
                    $name_len  = \strlen($name);
                    $value_len = \strlen($value);
                    $format = [
                        $name_len  > 127 ? 'N' : 'C',
                        $value_len > 127 ? 'N' : 'C',
                        "a{$name_len}",
                        "a{$value_len}",
                    ];
                    $format = implode ('', $format);
                    $payload .= \pack(
                        $format,
                        $name_len  > 127 ? ($name_len  | 0x80000000) : $name_len,
                        $value_len > 127 ? ($value_len | 0x80000000) : $value_len,
                        $name,
                        $value
                    );
                }
                break;
            case static::FCGI_STDIN:
            case static::FCGI_ABORT_REQUEST:
            case static::FCGI_DATA:
                $payload = \pack("a" . static::$_request->getContentLength(), static::$_request->getContent());
                break;
            case static::FCGI_STDIN_END:
                $payload = '';
                break;
            case static::FCGI_UNKNOWN_TYPE:
                $payload = \pack("Ca7", static::FCGI_UNKNOWN_TYPE, static::FCGI_RESERVED);
                break;
            default:
                $payload = '';
                break;
        }

        return $payload;
    }

    /**
     * @brief    create request packet
     *
     * @param    string  $type
     *
     * @return   string
     */
    static public function createPacket($type = '')
    {
        $packet = '';
        $offset = 0;
        $payload = static::packPayload($type);
        $total_len = \strlen($payload);

        //don't forget to reset pseudo record type to normal
        $type == static::FCGI_PARAMS_END && $type = static::FCGI_PARAMS;
        $type == static::FCGI_STDIN_END  && $type = static::FCGI_STDIN;

        //maybe need to split payload into many chunks 
        do
        {
            $chunk = \substr($payload, $offset, static::FCGI_MAX_PAYLOAD_LEN);
            $chunk_len = \strlen($chunk);
            $remainder = \abs($chunk_len % 8);
            $padding_len = $remainder > 0 ? 8 - $remainder : 0;

            $header = \pack(
                "CCnnCC",
                static::FCGI_VERSION_1,
                $type,
                static::$_request->getRequestId(),
                $chunk_len, 
                $padding_len,
                static::FCGI_RESERVED
            );

            $padding = \pack("a{$padding_len}", static::FCGI_PADDING);
            $packet .= $header . $chunk . $padding;
            $offset += $chunk_len;
        }while($offset < $total_len);

        return $packet;
    }

    /**
     * @brief    check the protocol status from FCGI_END_REQUEST body
     *
     * @param    int    $status
     *
     * @return   array
     */
    static public function checkProtocolStatus($status = 0)
    {
        switch($status)
        {
            case static::FCGI_REQUEST_COMPLETE:
                $msg = 'Accepted: request completed ok';
                break;
            case static::FCGI_CANT_MPX_CONN:
                $msg = 'Rejected: FastCGI server does not support concurrent processing';
                break;
            case static::FCGI_OVERLOADED:
                $msg = 'Rejected: FastCGI server run out of resources or reached the limit';
                break;
            case static::FCGI_UNKNOWN_ROLE:
                $msg = 'Rejected: FastCGI server not support the specified role';
                break;
            default:
                $msg = 'Rejected: FastCGI server does not know what happened';
                break;
        }

        return [
            'code' => $status,
            'msg'  => $msg,
        ];
    }

}
