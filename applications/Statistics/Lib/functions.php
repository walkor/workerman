<?php

/**
 * 批量请求
 * @param array $request_buffer_array ['ip:port'=>req_buf, 'ip:port'=>req_buf, ...]
 * @return multitype:unknown string
 */
function multiRequest($request_buffer_array)
{
    \Statistics\Lib\Cache::$lastSuccessIpArray = array();
    $client_array = $sock_to_ip = $ip_list = array();
    foreach($request_buffer_array as $address => $buffer)
    {
        list($ip, $port) = explode(':', $address);
        $ip_list[$ip] = $ip;
        $client = stream_socket_client("tcp://$address", $errno, $errmsg, 1);
        if(!$client)
        {
            continue;
        }
        $client_array[$address] = $client;
        stream_set_timeout($client_array[$address], 0, 100000);
        fwrite($client_array[$address], $buffer);
        stream_set_blocking($client_array[$address], 0);
        $sock_to_address[(int)$client] = $address;
    }
    $read = $client_array;
    $write = $except = $read_buffer = array();
    $time_start = microtime(true);
    $timeout = 0.99;
    // 轮询处理数据
    while(count($read) > 0)
    {
        if(stream_select($read, $write, $except, 0, 200000))
        {
            foreach($read as $socket)
            {
                $address = $sock_to_address[(int)$socket];
                $buf = fread($socket, 8192);
                if(!$buf)
                {
                    if(feof($socket))
                    {
                        unset($client_array[$address]);
                    }
                    continue;
                }
                if(!isset($read_buffer[$address]))
                {
                    $read_buffer[$address] = $buf;
                }
                else
                {
                    $read_buffer[$address] .= $buf;
                }
                // 数据接收完毕
                if(($len = strlen($read_buffer[$address])) && $read_buffer[$address][$len-1] === "\n")
                {
                    unset($client_array[$address]);
                }
            }
        }
        // 超时了
        if(microtime(true) - $time_start > $timeout)
        {
            break;
        }
        $read = $client_array;
    }

    foreach($read_buffer as $address => $buf)
    {
        list($ip, $port) = explode(':', $address);
        \Statistics\Lib\Cache::$lastSuccessIpArray[$ip] = $ip;
    }

     \Statistics\Lib\Cache::$lastFailedIpArray = array_diff($ip_list,  \Statistics\Lib\Cache::$lastSuccessIpArray);

    ksort($read_buffer);

    return $read_buffer;
}