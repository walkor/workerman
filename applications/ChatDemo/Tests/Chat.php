<?php
require_once __DIR__ . '/../Protocols/JsonProtocol.php';
ini_set('display_errors', 'on');
error_reporting(E_ALL);

$ip = isset($argv[1]) ? $argv[1] : '127.0.0.1';
$port = isset($argv[2]) ? $argv[2] : 8480;
$sock = stream_socket_client("tcp://$ip:$port");
if(!$sock)exit("can not create sock\n");


fwrite($sock, JsonProtocol::encode('connect'));
$rsp_string = fgets($sock, 1024);
$ret = JsonProtocol::decode($rsp_string);
if(isset($ret['uid']))
{
    echo "chart room login success , your uid is [{$ret['uid']}]\n";
    echo "use uid:words send message to one user\n";
    echo "use words send message to all\n";
}
else
{
    exit("connet faild reponse:$rsp_string\n");
}

$MYUID = $ret['uid'];

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
              $ret = fgets($fd, 102400);
              if(!$ret){continue;exit("connection closed\n ");}
              
              // 是服务端发来的心跳，只是检测联通性，不用回复
              if("#ping#" == $ret)
              {
                  continue;
              }
              
              $ret = JsonProtocol::decode(trim($ret));
              
              if($ret['to_uid'] == $MYUID)
              {
                  echo $ret['from_uid'] , ' say to YOU:', $ret['message'], "\n";
              }
              else 
              {
                  echo $ret['from_uid'] , ' say to ALL:', $ret['message'], "\n";
              }
              continue;
          }
          
          // 向某个uid发送消息 格式为 uid:xxxxxxxx
          $ret = fgets(STDIN, 10240);
          if(!$ret)continue;
          if(preg_match("/(\d+):(.*)/", $ret, $match))
          {
             $uid = $match[1];
             $words = $match[2];
             fwrite($sock, JsonProtocol::encode(array('from_uid'=>$MYUID, 'to_uid'=>$uid, 'message'=>$words)));
             continue;
          }
          // 向所有用户发消息
          fwrite($sock, JsonProtocol::encode(array('from_uid'=>$MYUID, 'to_uid'=>'all', 'message'=>$ret)));
          continue;

       }
    }
}
