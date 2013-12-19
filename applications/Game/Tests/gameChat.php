<?php
ini_set('display_errors', 'on');
error_reporting(E_ALL);

$sock = stream_socket_client("tcp://115.28.44.100:8282");
if(!$sock)exit("can not create sock\n");

$buf = new GameBuffer();
$buf->body = rand(1,100000000); 

fwrite($sock, $buf->getBuffer());
$ret = fread($sock, 1024);
$ret = GameBuffer::decode($ret);
if(isset($ret['to_uid']))
{
    echo "chart room login success , your uid is [{$ret['to_uid']}]\n";
    echo "use uid:words send message to one user\n";
    echo "use words send message to all\n";
}

stream_set_blocking($sock, 0);
stream_set_blocking(STDIN, 0);

$read = array(STDIN, $sock);

$write = $ex = array();
while(1)
{
    $read_copy = $read;
    if($ret = stream_select($read_copy, $write, $ex, 1000))
    {
       foreach($read as $fd)
       {
          // 接收消息
          if((int)$fd === (int)$sock)
          {
              $ret = fread($fd, 102400);
              if(!$ret){continue;exit("connection closed\n ");}
              $ret = GameBuffer::decode($ret);
              echo $ret['from_uid'] , ':', $ret['body'], "\n";
              continue;
          }
          // 向某个uid发送消息 格式为 uid:xxxxxxxx
          $ret = fgets(STDIN, 10240);
          if(!$ret)continue;
          if(preg_match("/(\d+):(.*)/", $ret, $match))
          {
             $uid = $match[1];
             $words = $match[2];
             $buf = new GameBuffer();
             $buf->header['cmd'] = GameBuffer::CMD_USER;
             $buf->header['sub_cmd'] = GameBuffer::SCMD_SAY;
             $buf->header['to_uid'] = $uid;
             $buf->body = $words;
             fwrite($sock, $buf->getBuffer());
             continue;
          }
          // 向所有用户发消息
          $buf = new GameBuffer();
          $buf->header['cmd'] = GameBuffer::CMD_USER;
          $buf->header['sub_cmd'] = GameBuffer::SCMD_BROADCAST;
          $buf->body = trim($ret);
          fwrite($sock, $buf->getBuffer());
          continue;

       }
    }
  
}


/**
 * 二进制协议
 *
 * struct BufferProtocol
 * {
 *     unsigned char     version,//版本
 *     unsigned short    series_id,//序列号 udp协议使用
 *     unsigned short    cmd,//主命令字
 *     unsigned short    sub_cmd,//子命令字
 *     int                         code,//返回码
 *     unsigned int        from_uid,//来自用户uid
 *     unsigned int        to_uid,//发往的uid
 *     unsigned int       pack_len,//包长
 *     char[pack_length-HEAD_LEN] body//包体
 * }
 *
 * @author walkor <worker-man@qq.com>
 */

class Buffer
{
    /**
     * 版本
     * @var integer
     */
    const VERSION = 0x01;

    /**
     * 包头长度
     * @var integer
     */
    const HEAD_LEN = 23;

    /**
     * 序列号，防止串包
     * @var integer
     */
    protected static $seriesId = 0;

    /**
     * 协议头
     * @var array
     */
    public $header = array(
        'version'        => self::VERSION,
        'series_id'      => 0,
        'cmd'            => 0,
        'sub_cmd'     => 0,
        'code'           => 0,
        'from_uid'    => 0,
        'to_uid'         => 0,
        'pack_len'    => self::HEAD_LEN
    );
/**
     * 包体
     * @var string
     */
    public $body = '';

    /**
     * 初始化
     * @return void
     */
    public function __construct($buffer = null)
    {
        if($buffer)
        {
            $data = self::bufferToData($buffer);
            $this->body = $data['body'];
            unset($data['body']);
            $this->header = $data;
        }
        else
        {
            if(self::$seriesId>=65535)
            {
                self::$seriesId = 0;
            }
            else
            {
                $this->header['series_id'] = self::$seriesId++;
            }
        }
    }

    /**
     * 判断数据包是否都到了
     * @param string $buffer
     * @return int int=0数据是完整的 int>0数据不完整，还要继续接收int字节
     */
    public static function input($buffer, &$data = null)
    {
        $len = strlen($buffer);
        if($len < self::HEAD_LEN)
        {
            return self::HEAD_LEN - $len;
        }

        $data = unpack("Cversion/Sseries_id/Scmd/Ssub_cmd/icode/Ifrom_uid/Ito_uid/Ipack_len", $buffer);
        if($data['pack_len'] > $len)
        {
            return $data['pack_len'] - $len;
        }
        $data['body'] = '';
        $body_len = $data['pack_len'] - self::HEAD_LEN;
        if($body_len > 0)
        {
            $data['body'] = substr($buffer, self::HEAD_LEN, $body_len);
 }
        return 0;
    }


    /**
     * 设置包体
     * @param string $body_str
     * @return void
     */
    public function setBody($body_str)
    {
        $this->body = (string) $body_str;
    }

    /**
     * 获取整个包的buffer
     * @param string $data
     * @return string
     */
    public function getBuffer()
    {
        $this->header['pack_len'] = self::HEAD_LEN + strlen($this->body);
        return pack("CSSSiIII", $this->header['version'],  $this->header['series_id'], $this->header['cmd'], $this->header['sub_cmd'], $this->header['code'], $this->header['from_uid'], $this->header['to_uid'], $this->header['pack_len']).$this->body;
    }

    /**
     * 从二进制数据转换为数组
     * @param string $buffer
     * @return array
     */
    public static function decode($buffer)
    {
        $data = unpack("Cversion/Sseries_id/Scmd/Ssub_cmd/icode/Ifrom_uid/Ito_uid/Ipack_len", $buffer);
        $data['body'] = '';
        $body_len = $data['pack_len'] - self::HEAD_LEN;
        if($body_len > 0)
        {
            $data['body'] = substr($buffer, self::HEAD_LEN, $body_len);
        }
        return $data;
    }

}





/**
 *
 * 命令字相关
* @author walkor <worker-man@qq.com>
*
 */

class GameBuffer extends Buffer
{
    // 系统命令
    const CMD_SYSTEM = 128;
    // 连接事件
    const SCMD_ON_CONNECT = 1;
    // 关闭事件
    const SCMD_ON_CLOSE = 2;

    // 发送给网关的命令
    const CMD_GATEWAY = 129;
    // 给用户发送数据包
    const SCMD_SEND_DATA = 3;
    // 根据uid踢人
    const SCMD_KICK_UID = 4;
    // 根据地址和socket编号踢人
    const SCMD_KICK_ADDRESS = 5;
    // 广播内容
    const SCMD_BROADCAST = 6;
    // 通知连接成功
    const SCMD_CONNECT_SUCCESS = 7;

    // 用户中心
    const CMD_USER = 1;
    // 登录
    const SCMD_LOGIN = 8;
    // 发言
    const SCMD_SAY = 9;

}



