<?php
/**
 * 
 * 暴露给客户端的连接网关 只负责网络io
 * 1、监听客户端连接
 * 2、监听后端回应并转发回应给前端
 * 
 * @author walkor <worker-man@qq.com>
 * 
 */
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';

class ChatGateway extends WORKERMAN\Core\SocketWorker
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
