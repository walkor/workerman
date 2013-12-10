<?php
/**
 * 
 * 聊天服务器中心节点 负责
 * 1、查询用户在线状态
 * 2、保存用户登录的服务器及通信端口
 * 3、查询用户连接的服务器及通信端口
 * 
 * @author walkor <worker-man@qq.com>
 * 
 */
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';

class ChatCenter extends WORKERMAN\Core\SocketWorker
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
