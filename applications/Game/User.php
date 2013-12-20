<?php
require_once WORKERMAN_ROOT_DIR . 'applications/Game/Protocols/GameBuffer.php';
class User
{
    public static function broadcast($data)
    {
        $buf = new GameBuffer();
        $buf->header['cmd'] = GameBuffer::CMD_GATEWAY;
        $buf->header['sub_cmd'] = GameBuffer::SCMD_BROADCAST;
        $buf->header['from_uid'] = $data['from_uid'];
        $buf->body = $data['body'];
        GameBuffer::sendToAll($buf->getBuffer());
    }


    public static function say($data)
    {
        $buf = new GameBuffer();
        $buf->header['cmd'] = GameBuffer::CMD_GATEWAY;
        $buf->header['sub_cmd'] = GameBuffer::SCMD_SEND_DATA;
        $buf->header['from_uid'] = $data['from_uid'];
        $buf->header['to_uid'] = $data['to_uid'];
        $buf->body = $data['body'];
        GameBuffer::sendToUid($data['to_uid'], $buf->getBuffer());
    }

}
