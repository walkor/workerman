<?php 
error_reporting(E_ALL);
ini_set('display_errors', 'on');
include '../../man/Protocols/Buffer.php';

$sock = stream_socket_client("tcp://127.0.0.1:20305");
if(!$sock)exit("can not create sock\n");

$code = 0;
while(1)
{
    $buf = new \Man\Protocols\Buffer();
    $buf->body = 'HELLO YAOYAO';
    $buf->header['code'] = $code++;
    fwrite($sock, $buf->getBuffer());
    $ret = fread($sock, 10240);
    var_export(\Man\Protocols\Buffer::decode($ret));
}
