<?php 
namespace WORKERMAN\Protocols;
/**
 * fastcgi 协议解析 相关
 * 简单实现，测试时使用，可能会有bug，不要用到生产环境
 * @author walkor <worker-man@qq.com>
 * */
class FastCGI{
    
    const VERSION_1            = 1;

    const BEGIN_REQUEST        = 1;
    const ABORT_REQUEST        = 2;
    const END_REQUEST          = 3;
    const PARAMS               = 4;
    const STDIN                = 5;
    const STDOUT               = 6;
    const STDERR               = 7;
    const DATA                 = 8;
    const GET_VALUES           = 9;
    const GET_VALUES_RESULT    = 10;
    const UNKNOWN_TYPE         = 11;
    const MAXTYPE              = self::UNKNOWN_TYPE;
    
    const HEAD_LENGTH      = 8;
    
    
    private  function __construct(){}
    
    /**
     * 判断数据包是否全部接收完成
     * 
     * @param string $data
     * @return int 0:完成 >0:还要接收int字节
     */
    public static function input($data)
    {
        while(1)
        {
            $data_length = strlen($data);
            // 长度小于包头长度，继续读
            if($data_length < self::HEAD_LENGTH)
            {
                return self::HEAD_LENGTH - $data_length;
            }
        
            $headers = unpack(
                    "Cversion/".
                    "Ctype/".
                    "nrequestId/".
                    "ncontentLength/".
                    "CpaddingLength/".
                    "Creserved/"
                    , $data);
        
            $total_length = self::HEAD_LENGTH + $headers['contentLength'] + $headers['paddingLength'];
            
            // 全部接收完毕
            if($data_length == $total_length)
            {
                return 0;
            }
            // 数据长度不够一个包长
            else if($data_length < $total_length)
            {
                return $total_length - $data_length;
            }
            // 数据长度大于一个包长，还有后续包
            else
            {
                $data = substr($data, $total_length);
            }
        }
        return 0;
    }    
    
    /**
     * 解析全部fastcgi协议包，并设置相应环境变量
     * 
     * @param string $data
     * @return array
     */
    public static function decode($data)
    {
        $params = array();
        $_GET = $_POST = $GLOBALS['HTTP_RAW_POST_DATA'] = array();
        
        while(1)
        {
            if(!$data)
            {
                break;
            }
            $headers = unpack(
                    "Cversion/".
                    "Ctype/".
                    "nrequestId/".
                    "ncontentLength/".
                    "CpaddingLength/".
                    "Creserved/"
                    , $data);
            
            // 获得环境变量等
            if($headers['type'] == self::PARAMS)
            {
                // 解析名-值
                $offset = self::HEAD_LENGTH;
                while($offset + $headers['paddingLength'] < $headers['contentLength'])
                {
                    $namelen = ord($data[$offset++]);
                    // 127字节或更少的长度能在一字节中编码，而更长的长度总是在四字节中编码
                    if($namelen > 127)
                    {
                        $namelen = (($namelen & 0x7f) << 24) +
                        (ord($data[$offset++]) << 16) +
                        (ord($data[$offset++]) << 8) +
                        ord($data[$offset++]);
                    }
            
                    // 值的长度
                    $valuelen = ord($data[$offset++]);
                    if($valuelen > 127)
                    {
                        $valuelen = (($valuelen & 0x7f) << 24) +
                        (ord($data[$offset++]) << 16) +
                        (ord($data[$offset++]) << 8) +
                        ord($data[$offset++]);
                    }
            
                    // 名
                    $name = substr($data, $offset, $namelen);
                    $offset += $namelen;
                    $value = substr($data, $offset, $valuelen);
                    $offset += $valuelen;
                    $params[$name] = $value;
                }
                
                // 解析$_SERVER
                foreach($params as $key=>$value)
                {
                    $_SERVER[$key]=$value;
                }
                if(array_key_exists('HTTP_COOKIE', $params))
                {
                    foreach(explode(';', $params['HTTP_COOKIE']) as $coo)
                    {
                        $nameval = explode('=', trim($coo));
                        $_COOKIE[$nameval[0]] = urldecode($nameval[1]);
                    }
                }
            }
            elseif($headers['type'] == self::STDIN)
            {
                // 为啥是8，还要研究下
                $data = substr($data, 8, $headers['contentLength']);
                
                // 解析$GLOBALS['HTTP_RAW_POST_DATA']
                $GLOBALS['HTTP_RAW_POST_DATA'] = $data;
                // 解析POST
                parse_str($data, $_POST);
            }
            
            $total_length = self::HEAD_LENGTH + $headers['contentLength'] + $headers['paddingLength'];
            
            $data = substr($data, $total_length);
        }
        
        // 解析GET
        parse_str(preg_replace('/^\/.*?\?/', '', $_SERVER['REQUEST_URI']), $_GET);
        
        return array('header' => $headers, 'data' => '');
    }
    
    /**
     * 打包fastcgi协议，用于返回数据给nginx
     * 
     * @param array $header
     * @param string $data
     * @return string
     */
    public static function encode($header, $data)
    {
        $data = "Content-type: text/html\r\n\r\n" . $data;
        $contentLength = strlen($data);
        $head_data = pack("CCnnxx",
                self::VERSION_1,
                self::STDOUT,
                $header['requestId'],
                $contentLength
        );
        
        return $head_data.$data;
    }
}