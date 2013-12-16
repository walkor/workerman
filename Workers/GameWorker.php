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
require_once WORKERMAN_ROOT_DIR . 'Applications/Game/GameBuffer.php';
require_once WORKERMAN_ROOT_DIR . 'Applications/Game/Event.php';

class GameWorker extends WORKERMAN\Core\SocketWorker
{
    protected $data = array();
    public function dealInput($recv_str)
    {
        return GameBuffer::input($recv_str, $this->data); 
    }

    public function dealProcess($recv_str)
    {
        if(!isset(GameBuffer::$cmdMap[$this->data['cmd']]) || !isset(GameBuffer::$scmdMap[$this->data['sub_cmd']]))
        {
            $this->notice('cmd err ' . serialize($this->data) );
            return;
        }
        $class = GameBuffer::$cmdMap[$this->data['cmd']];
        $method = GameBuffer::$scmdMap[$this->data['sub_cmd']];
        if(!method_exists($class, $method))
        {
            if($class == 'System')
            {
                switch($this->data['sub_cmd'])
                {
                    case GameBuffer::SCMD_ON_CONNECT:
                        call_user_func_array(array('Event', 'onConnect'), array('udp://'.$this->getRemoteIp().':'.$this->data['to_uid'], $this->data['from_uid'], $this->data['body']));
                        return; 
                }
            }
            $this->notice("cmd err $class::$method not exists");
            return;
        }
        call_user_func_array(array($class, $method),  $this->data);
    }
}
