<?php
/**
 * 
 *  压力测试worker，可以telnet测试
 * @author walkor <worker-man@qq.com>
 */
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
class RpcWorker extends Man\Core\SocketWorker
{
    /**
     * 确定数据是否接收完整，这里每次收到包都认为数据完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return 0; 
    }

    /**
     * 数据接收完整后处理业务逻辑，只是发送接收到的数据给客户端
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $this->sendToClient($recv_str);
    }
}
