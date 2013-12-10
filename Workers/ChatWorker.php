<?php
/**
 * 
 * 处理具体聊天逻辑
 * 1、查询某用户内网通信gateway ip及端口
 * 2、向某用户对应内网gateway ip及端口发送数据
 * 
 * @author walkor <worker-man@qq.com>
 * 
 */
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';

class ChatWorker extends WORKERMAN\Core\SocketWorker
{
    public function dealInput($recv_str)
    {
        return 0; 
    }

    public function dealProcess($recv_str)
    {
        $this->sendToClient($recv_str);
    }
}
