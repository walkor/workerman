<?php
ini_set('display_errors', 'on');
error_reporting(E_ALL);

$ip = isset($argv[1]) ? $argv[1] : '127.0.0.1';
$port = isset($argv[2]) ? $argv[2] : 8480;
$sock = stream_socket_client("tcp://$ip:$port");
if(!$sock)exit("can not create sock\n");


fwrite($sock, 'connect');
$rsp_string = fgets($sock, 1024);
$ret = json_decode($rsp_string, true);
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
              $ret = json_decode(trim($ret),true);
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
             fwrite($sock, json_encode(array('from_uid'=>$MYUID, 'to_uid'=>$uid, 'message'=>$words))."\n");
             continue;
          }
          // 向所有用户发消息
          fwrite($sock, json_encode(array('from_uid'=>$MYUID, 'to_uid'=>'all', 'message'=>$ret))."\n");
          continue;

       }
    }
}
