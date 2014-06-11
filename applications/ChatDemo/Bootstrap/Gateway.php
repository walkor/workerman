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
define('ROOT_DIR', realpath(__DIR__.'/../'));
require_once ROOT_DIR . '/Protocols/GatewayProtocol.php';
require_once ROOT_DIR . '/Lib/Store.php';

class Gateway extends Man\Core\SocketWorker
{
    /**
     * 内部通信socket
     * @var resouce
     */
    protected $innerMainSocket = null;
    
    /**
     * 内网ip
     * @var string
     */
    protected $lanIp = '127.0.0.1';
    
    
    /**
     * 内部通信端口
     * @var int
     */
    protected $lanPort = 0;
    
    /**
     * uid到连接的映射
     * @var array
     */
    protected $uidConnMap = array();
    
    /**
     * 连接到uid的映射
     * @var array
     */
    protected $connUidMap = array();
    
    
    /**
     * 到Worker的通信地址
     * @var array
     */ 
    protected $workerAddresses = array();
    
    /**
     * 进程启动
     */
    public function start()
    {
        // 安装信号处理函数
        $this->installSignal();
        
        // 添加accept事件
        $ret = $this->event->add($this->mainSocket,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'accept'));
        
        // 创建内部通信套接字
        $start_port = Man\Core\Lib\Config::get($this->workerName.'.lan_port_start');
        $this->lanPort = $start_port - posix_getppid() + posix_getpid();
        $this->lanIp = Man\Core\Lib\Config::get($this->workerName.'.lan_ip');
        if(!$this->lanIp)
        {
            $this->notice($this->workerName.'.lan_ip not set');
            $this->lanIp = '127.0.0.1';
        }
        $error_no = 0;
        $error_msg = '';
        $this->innerMainSocket = stream_socket_server("udp://".$this->lanIp.':'.$this->lanPort, $error_no, $error_msg, STREAM_SERVER_BIND);
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
        
        $this->registerAddress("udp://".$this->lanIp.':'.$this->lanPort);
        
        // 添加读udp事件
        $this->event->add($this->innerMainSocket,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'recvUdp'));
        
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
     */
    protected function registerAddress($address)
    {
        \Man\Core\Lib\Mutex::get();
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        
        $addresses_list[$address] = $address;
        Store::set($key, $addresses_list);
        \Man\Core\Lib\Mutex::release();
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
        $this->workerAddresses = Man\Core\Lib\Config::get($this->workerName.'.game_worker');
        if(!$this->workerAddresses)
        {
            $this->notice($this->workerName.'game_worker not set');
        }
    }
    
    public function dealInput($recv_str)
    {
        return 0;
    }

    public function innerDealProcess($recv_str)
    {
        $pack = new GatewayProtocol($recv_str);
        
        switch($pack->header['cmd'])
        {
            case GatewayProtocol::CMD_SEND_TO_ONE:
                return $this->sendToSocketId($pack->header['socket_id'], $pack->body);
            case GatewayProtocol::CMD_KICK:
                if($pack->body)
                {
                    $this->sendToSocketId($pack->header['socket_id'], $pack->body);
                }
                return $this->closeClientBySocketId($pack->header['socket_id']);
            case GatewayProtocol::CMD_SEND_TO_ALL:
                return $this->broadCast($pack->body);
            case GatewayProtocol::CMD_CONNECT_SUCCESS:
                return $this->connectSuccess($pack->header['socket_id'], $pack->header['uid']);
            default :
                $this->notice('gateway inner pack cmd err data:' .$recv_str );
        }
    }
    
    protected function broadCast($bin_data)
    {
        foreach($this->uidConnMap as $uid=>$conn)
        {
            $this->sendToSocketId($conn, $bin_data);
        }
    }
    
    protected function closeClientBySocketId($socket_id)
    {
        if($uid = $this->getUidByFd($socket_id))
        {
            unset($this->uidConnMap[$uid], $this->connUidMap[$socket_id]);
        }
        parent::closeClient($socket_id);
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
    
    protected function connectSuccess($socket_id, $uid)
    {
        $binded_uid = $this->getUidByFd($socket_id);
        if($binded_uid)
        {
            $this->notice('notify connection success fail ' . $socket_id . ' already binded data:'.serialize($data));
            return;
        }
        $this->uidConnMap[$uid] = $socket_id;
        $this->connUidMap[$socket_id] = $uid;
    }
    
    public function sendToSocketId($socket_id, $bin_data)
    {
        if(!isset($this->connections[$socket_id]))
        {
            return false;
        }
        $this->currentDealFd = $socket_id;
        return $this->sendToClient($bin_data);
    }

    protected function closeClient($fd)
    {
        if($uid = $this->getUidByFd($fd))
        {
            $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $fd);
            unset($this->uidConnMap[$uid], $this->connUidMap[$fd]);
        }
        parent::closeClient($fd);
    }
    
    public function dealProcess($recv_str)
    {
        // 判断用户是否认证过
        $from_uid = $this->getUidByFd($this->currentDealFd);
        // 触发ON_CONNECTION
        if(!$from_uid)
        {
            return $this->sendToWorker(GatewayProtocol::CMD_ON_CONNECTION, $this->currentDealFd, $recv_str);
        }
        
        // 认证过, 触发ON_MESSAGE
        $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $this->currentDealFd, $recv_str);
    }
    
    protected function sendToWorker($cmd, $socket_id, $body = '')
    {
        $address= $this->getRemoteAddress($socket_id);
        list($client_ip, $client_port) = explode(':', $address, 2);
        $pack = new GatewayProtocol();
        $pack->header['cmd'] = $cmd;
        $pack->header['local_ip'] = $this->lanIp;
        $pack->header['local_port'] = $this->lanPort;
        $pack->header['socket_id'] = $socket_id;
        $pack->header['client_ip'] = $client_ip;
        $pack->header['client_port'] = $client_ip;
        $pack->header['uid'] = $this->getUidByFd($socket_id);
        $pack->body = $body;
        return $this->sendBufferToWorker($pack->getBuffer());
    }
    
    protected function sendBufferToWorker($bin_data)
    {
        $client = stream_socket_client($this->workerAddresses[array_rand($this->workerAddresses)]);
        $len = stream_socket_sendto($client, $bin_data);
        return $len == strlen($bin_data);
    }
    
    protected function notice($str, $display=true)
    {
        $str = 'Worker['.get_class($this).']:'."$str ip:".$this->getRemoteIp();
        Man\Core\Lib\Log::add($str);
        if($display && Man\Core\Lib\Config::get('workerman.debug') == 1)
        {
            echo $str."\n";
        }
    }
    
    public function onStop()
    {
        Store::deleteAll();
    }
}
