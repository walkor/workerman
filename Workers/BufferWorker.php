<?php
/**
 * 
 * 测试worker
* @author walkor <worker-man@qq.com>
 */
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'Protocols/Buffer.php';

class BufferWorker extends WORKERMAN\Core\SocketWorker
{
    public function dealInput($recv_str)
    {
        $remian = \WORKERMAN\Protocols\Buffer::input($recv_str);
        return $remian;
    }

    public function dealProcess($recv_str)
    {
        $buf = new \WORKERMAN\Protocols\Buffer();
        $buf->header['code'] = 200;
        $buf->body = 'haha';
        $this->sendToClient($buf->getBuffer());
    }
}
