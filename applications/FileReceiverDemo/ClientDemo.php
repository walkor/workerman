<?php
if(!isset($argv[1]))
{
    exit("usage php ClientDemo.php file_path file_type\n");
}

$file_path = $argv[1];
if(!is_file($file_path))
{
    exit("can not found $file_path\n");
}

// messge_type
$file_type = isset($argv[2]) && $argv[2] > 0 ? (int)$argv[2] : 0;

if(false === ($file_bin_data = file_get_contents($file_path)))
{
    exit("can not get contents of $file_path\n");
}

$address = "tcp://127.0.0.1:2015";
if(!($sock = stream_socket_client($address, $err_no, $err_str)))
{
    exit("can not connect to $address $err_str");
}

$head_len = 5;
$message_buffer = pack("NC", $head_len+strlen($file_bin_data), $file_type).$file_bin_data;

fwrite($sock, $message_buffer);

$recv_buffer = fread($sock, 65535);

$recv_buffer_data = unpack("Nmessage_len/Cmessage_type", $recv_buffer);
$recv_buffer_data['body']=substr($recv_buffer, $head_len);
var_export($recv_buffer_data);