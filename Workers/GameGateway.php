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
require_once WORKERMAN_ROOT_DIR . 'Applications/GameBuffer.php';
require_once WORKERMAN_ROOT_DIR . 'Applications/Store.php';

class GameGateway extends WORKERMAN\Core\SocketWorker
{
    // 内部通信socket
    protected $innerMainSocket = null;
    // uid到连接的映射
    protected $uidConnMap = array();
    // 连接到uid的映射
    protected $connUidMap = array();
    
    // 到GameWorker的通信地址
    protected $workerAddresses = array();
    
    // 当前处理的包数据
    protected $data = array();
    
    public function start()
    {
        // 安装信号处理函数
        $this->installSignal();
        
        // 添加accept事件
        $ret = $this->event->add($this->mainSocket,  WORKERMAN\Core\Events\BaseEvent::EV_READ, array($this, 'accept'));
        
        // 创建内部通信套接字
        $inner_port = posix_getpid();
        $lan_ip = WORKERMAN\Core\Lib\Config::get('workers.'.$this->workerName.'.lan_ip');
        if(!$lan_ip)
        {
            $this->notice($this->workerName.'.lan_ip not set');
            $lan_ip = '127.0.0.1';
        }
        $error_no = 0;
        $error_msg = '';
        $this->innerMainSocket = stream_socket_server("udp://$lan_ip:$inner_port", $error_no, $error_msg, STREAM_SERVER_BIND);
        if(!$this->innerMainSocket)
        {
            $this->notice('create innerMainSocket fail and exit '.$error_no . ':'.$error_msg);
            sleep(1);
            exit(0);
        }
        else
        {
            stream_set_blocking($this->innerMainSocket , 0);
        }
        
        $this->registerAddress("udp://$lan_ip:$inner_port");
        
        // 添加读udp事件
        $this->event->add($this->innerMainSocket,  WORKERMAN\Core\Events\BaseEvent::EV_READ, array($this, 'recvUdp'));
        
        // 初始化到worker的通信地址
        $this->initWorkerAddresses();
        
        // 主体循环,整个子进程会阻塞在这个函数上
        $ret = $this->event->loop();
        $this->notice('worker loop exit');
        exit(0);
    }
    
    /**
     * 存储全局的通信地址
     * @param string $address
     * @todo 用锁机制等保证数据完整性
     */
    protected function registerAddress($address)
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses = Store::get($key);
        if(empty($addresses))
        {
            $addresses = array($address);
        }
        else
        {
            $addresses[] = $address;
        }
        Store::set($key, $addresses);
    }
    
    /**
     * 接收Udp数据
     * 如果数据超过一个udp包长，需要业务自己解析包体，判断数据是否全部到达
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function recvUdp($socket, $null_one = null, $null_two = null)
    {
        $data = stream_socket_recvfrom($socket , self::MAX_UDP_PACKEG_SIZE, 0, $address);
        // 惊群效应
        if(false === $data || empty($address))
        {
            return false;
        }
         
        $this->currentClientAddress = $address;
       
        $this->innerDealProcess($data);
    }
    
    protected function initWorkerAddresses()
    {
        $this->workerAddresses = WORKERMAN\Core\Lib\Config::get('workers.'.$this->workerName.'.game_worker');
        if(!$this->workerAddresses)
        {
            $this->notice($this->workerName.'game_worker not set');
        }
    }
    
    public function dealInput($recv_str)
    {
        return GameBuffer::input($recv_str, $this->data);
    }

    public function innerDealProcess($recv_str)
    {
        $data = GameBuffer::decode($recv_str);
        if($data['cmd'] != GameBuffer::CMD_GATEWAY)
        {
            $this->notice('gateway inner pack err data:' .$recv_str . ' serialize:' . serialize($data) );
            return;
        }
        switch($data['sub_cmd'])
        {
            case GameBuffer::SCMD_SEND_DATA:
                return $this->sendToUid($data['to_uid'], $recv_str);
               
            case GameBuffer::SCMD_KICK_UID:
                return $this->closeClientByUid($data['to_uid'] );
                
            case GameBuffer::SCMD_KICK_ADDRESS:
                $fd = (int)trim($data['body']);
                $uid = $this->getUidByFd($fd);
                if($uid)
                {
                    return $this->closeClientByUid($uid);
                }
                return;
            case GameBuffer::SCMD_BROADCAST:
                return $this->broadCast($recv_str);
        }
    }
    
    protected function broadCast($bin_data)
    {
        foreach($this->uidConnMap as $uid=>$conn)
        {
            $this->sendToUid($uid, $bin_data);
        }
    }
    
    public function closeClientByUid($uid)
    {
        $fd = $this->getFdByUid($uid);
        if($fd)
        {
            unset($this->uidConnMap[$uid], $this->connUidMap[$fd]);
            parent::closeClient($fd);
        }
    }
    
    protected function getFdByUid($uid)
    {
        if(isset($this->uidConnMap[$uid]))
        {
            return $this->uidConnMap[$uid];
        }
        return 0;
    }
    
    protected function getUidByFd($fd)
    {
        if(isset($this->connUidMap[$fd]))
        {
            return $this->connUidMap[$fd];
        }
        return 0;
    }
    
    public function sendToUid($uid, $bin_data)
    {
        if(!isset($this->uidConnMap[$uid]))
        {
            return false;
        }
        $send_len = fwrite($this->connections[$this->uidConnMap[$uid]], $bin_data);
        return $send_len == strlen($bin_data);
    }
    
    public function dealProcess($recv_str)
    {
        // 判断用户是否认证过
        $from_uid = $this->getUidByFd($this->currentDealFd);
        if(!$from_uid)
        {
            // 没传sid
            if(empty($this->data['body']))
            {
                $this->notice("onConnect miss sid ip:".$this->getRemoteIp(). " data[".serialize($this->data)."]");
                $this->closeClient($this->currentDealFd);
                return;
            }
            // 发送onconnet事件包,包体是sid
            $on_buffer = new GameBuffer();
            $on_buffer->header['cmd'] = GameBuffer::CMD_SYSTEM;
            $on_buffer->head['sub_cmd'] = GameBuffer::SCMD_ON_CONNECT;
            // 用from_uid来临时存储socketid
            $on_buffer->head['from_uid'] = $this->currentDealFd;
            $on_buffer->body = $this->data['body'];
            $this->sendToWorker($on_buffer->getBuffer());
            return;
        }
        
        // 认证过
        $this->fillFromUid($recv_str, $from_uid);
        $this->sendToWorker($recv_str);
    }
    
    // 讲协议的from_uid填充为正确的值
    protected function fillFromUid(&$bin_data, $from_uid)
    {
        // from_uid在包头的12-15字节
        $bin_data = substr_replace($bin_data, pack('I', $from_uid), 11, 4);
    }
    
    protected function sendToWorker($bin_data)
    {
        $client = stream_socket_client($this->workerAddresses[array_rand($this->workerAddresses)]);
        $len = stream_socket_sendto($client, $bin_data);
        return $len == strlen($bin_data);
    }
    
    protected function notice($str, $display=true)
    {
        $str = 'Worker['.get_class($this).']:'."$str ip:".$this->getRemoteIp();
        WORKERMAN\Core\Lib\Log::add($str);
        if($display && WORKERMAN\Core\Lib\Config::get('debug') == 1)
        {
            echo $str."\n";
        }
    }
}
