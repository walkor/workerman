<?php
ini_set('display_errors', 'on');
error_reporting(E_ALL);
define('WORKERMAN_ROOT_DIR', __DIR__.'/../../../');
include '../GameBuffer.php';

$sock = stream_socket_client("tcp://115.28.44.100:8282");
if(!$sock)exit("can not create sock\n");

$buf = new GameBuffer();
$buf->body = rand(1,100000000); 

fwrite($sock, $buf->getBuffer());
$ret = fread($sock, 1024);
$ret = GameBuffer::decode($ret);
if(isset($ret['to_uid']))
{
    echo "login success , your uid is [{$ret['to_uid']}]\n";
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



die;
$buf->header['cmd'] = GameBuffer::CMD_USER;
$buf->header['sub_cmd'] = GameBuffer::SCMD_BROADCAST;
$buf->body = $argv[1];

fwrite($sock, $buf->getBuffer());

while(1)
{
   $ret = fread($sock, 1024);
   if($ret)
   {
      var_export(GameBuffer::decode($ret));
   }
}
