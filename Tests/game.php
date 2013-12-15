<?php 
error_reporting(E_ALL);
ini_set('display_errors', 'on');
include '../Applications/GameBuffer.php';

$sock = stream_socket_client("tcp://127.0.0.1:8282");
if(!$sock)exit("can not create sock\n");

$buf = new GameBuffer();
$buf->body = '123'';

fwrite($sock, $buf->getBuffer());
var_export($ret = fread($sock, 1024));
var_export(GameBuffer::decode($ret));