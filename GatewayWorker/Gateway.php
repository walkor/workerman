<?php 
namespace GatewayWorker;

use \Workerman\Worker;
use \Workerman\Lib\Timer;
use \Workerman\Protocols\GatewayProtocol;
use \GatewayWorker\Lib\Lock;
use \GatewayWorker\Lib\Store;

class Gateway extends Worker
{
    public $lanIp = '127.0.0.1';
    
    public $startPort = 2000;
    
    public $reloadable = false;
    
    public $pingInterval = 0;

    public $pingNotResponseLimit = 0;
    
    public $pingData = '';
    
    protected $_clientConnections = array();
    
    protected $_workerConnections = array();
    
    protected $_innerTcpWorker = null;
    
    protected $_innerUdpWorker = null;
    
    public function __construct($socket_name, $context_option = array())
    {
        $this->onWorkerStart = array($this, 'onWorkerStart');
        $this->onConnect = array($this, 'onClientConnect');
        $this->onMessage = array($this, 'onClientMessage');
        $this->onClose = array($this, 'onClientClose');
        $this->onWorkerStop = array($this, 'onWorkerStop');
        parent::__construct($socket_name, $context_option);
    }
    
    public function onClientMessage($connection, $data)
    {
        $connection->pingNotResponseCount = 0;
        $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $connection, $data);
    }
    
    public function onClientConnect($connection)
    {
        $connection->globalClientId = $this->createGlobalClientId();
        $connection->gatewayHeader = array(
            'local_ip' => $this->lanIp,
            'local_port' => $this->lanPort,
            'client_ip'=>$connection->getRemoteIp(),
            'client_port'=>$connection->getRemotePort(),
            'client_id'=>$connection->globalClientId,
        );
        $connection->session = '';
        $connection->pingNotResponseCount = 0;
        $this->_clientConnections[$connection->globalClientId] = $connection;
        $address = $this->lanIp.':'.$this->lanPort;
        $this->storeClientAddress($connection->globalClientId, $address);
        if(method_exists('Event','onConnect'))
        {
            $this->sendToWorker(GatewayProtocol::CMD_ON_CONNECTION, $connection);
        }
    }
    
    protected function sendToWorker($cmd, $connection, $body = '')
    {
        $gateway_data = $connection->gatewayHeader;
        $gateway_data['cmd'] = $cmd;
        $gateway_data['body'] = $body;
        $gateway_data['ext_data'] = $connection->session;
        $key = array_rand($this->_workerConnections);
        if($key)
        {
            if(false === $this->_workerConnections[$key]->send($gateway_data))
            {
                $msg = "sendBufferToWorker fail. May be the send buffer are overflow";
                $this->log($msg);
                return false;
            }
        }
        else
        {
            $msg = "endBufferToWorker fail. the connections between Gateway and BusinessWorker are not ready";
            $this->log($msg);
            return false;
        }
        return true;
    }
    
    /**
     * @param int $global_client_id
     * @param string $address
     */
    protected function storeClientAddress($global_client_id, $address)
    {
        if(!Store::instance('gateway')->set('gateway-'.$global_client_id, $address))
        {
            $msg = 'storeClientAddress fail.';
            if(get_class(Store::instance('gateway')) == 'Memcached')
            {
                $msg .= " reason :".Store::instance('gateway')->getResultMessage();
            }
            $this->log($msg);
            return false;
        }
        return true;
    }
    
    protected function delClientAddress($global_client_id)
    {
        Store::instance('gateway')->delete('gateway-'.$global_client_id);
    }
    
    public function onClientClose($connection)
    {
        $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $connection);
        $this->delClientAddress($connection->globalClientId);
        unset($this->_clientConnections[$connection->globalClientId]);
    }
    
    protected function createGlobalClientId()
    {
        $global_socket_key = 'GLOBAL_SOCKET_ID_KEY';
        $store = Store::instance('gateway');
        $global_client_id = $store->increment($global_socket_key);
        if(!$global_client_id || $global_client_id > 2147483646)
        {
            $store->set($global_socket_key, 0);
            $global_client_id = $store->increment($global_socket_key);
        }
    
        if(!$global_client_id)
        {
            $msg .= "createGlobalClientId fail :";
            if(get_class($store) == 'Memcached')
            {
                $msg .= $store->getResultMessage();
            }
            $this->log($msg);
        }
        
        return $global_client_id;
    }
    
    public function onWorkerStart()
    {
        $this->lanPort = $this->startPort - posix_getppid() + posix_getpid();
    
        if($this->lanPort<0 || $this->lanPort >=65535)
        {
            $this->lanPort = rand($this->startPort, 65535);
        }
        
        if($this->pingInterval > 0)
        {
            Timer::add($this->pingInterval, array($this, 'ping'));
        }
    
        $this->_innerTcpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $this->_innerTcpWorker->listen();
        $this->_innerUdpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $this->_innerUdpWorker->transport = 'udp';
        $this->_innerUdpWorker->listen();
    
        $this->_innerTcpWorker->onMessage = array($this, 'onWorkerMessage');
        $this->_innerUdpWorker->onMessage = array($this, 'onWorkerMessage');
        
        $this->_innerTcpWorker->onConnect = array($this, 'onWorkerConnect');
        $this->_innerTcpWorker->onClose = array($this, 'onWorkerClose');
        
        if(!$this->registerAddress())
        {
            $this->log('registerAddress fail and exit');
            Worker::stopAll();
        }
    }
    
    public function onWorkerConnect($connection)
    {
        $connection->remoteAddress = $connection->getRemoteIp().':'.$connection->getRemotePort();
        $this->_workerConnections[$connection->remoteAddress] = $connection;
    }
    
    public function onWorkerMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        switch($cmd)
        {
            // 向某客户端发送数据
            case GatewayProtocol::CMD_SEND_TO_ONE:
                if(isset($this->_clientConnections[$data['client_id']]))
                {
                    $this->_clientConnections[$data['client_id']]->send($data['body']);
                }
                break;
            case GatewayProtocol::CMD_KICK:
                if(isset($this->_clientConnections[$data['client_id']]))
                {
                    $this->_clientConnections[$data['client_id']]->close();
                }
                break;
            case GatewayProtocol::CMD_SEND_TO_ALL:
                if($data['ext_data'])
                {
                    $client_id_array = unpack('N*', $data['ext_data']);
                    foreach($client_id_array as $client_id)
                    {
                        if(isset($this->_clientConnections[$client_id]))
                        {
                            $this->_clientConnections[$client_id]->send($data['body']);
                        }
                    }
                }
                else
                {
                    foreach($this->_clientConnections as $client_connection)
                    {
                        $client_connection->send($data['body']);
                    }
                }
                break;
            case GatewayProtocol::CMD_UPDATE_SESSION:
                if(isset($this->_clientConnections[$data['client_id']]))
                {
                    $this->_clientConnections[$data['client_id']]->session = $data['ext_data'];
                }
                break;
            case GatewayProtocol::CMD_GET_ONLINE_STATUS:
                $online_status = json_encode(array_keys($this->_clientConnections));
                $connection->send($online_status);
                break;
            case GatewayProtocol::CMD_IS_ONLINE:
                $connection->send((int)isset($this->_clientConnections[$data['client_id']]));
                break;
            default :
                $err_msg = "gateway inner pack err cmd=$cmd";
                throw new \Exception($err_msg);
        }
    }
    
    public function onWorkerClose($connection)
    {
        $this->log("{$connection->remoteAddress} CLOSE INNER_CONNECTION\n");
        unset($this->_workerConnections[$connection->remoteAddress]);
    }
    
    /**
     * 存储全局的通信地址
     * @param string $address
     */
    protected function registerAddress()
    {
        $address = $this->lanIp.':'.$this->lanPort;
        // key
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        try
        {
            $store = Store::instance('gateway');
        }
        catch(\Exception $msg)
        {
            $this->log($msg);
            return false;
        }
        Lock::get();
        $addresses_list = $store->get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        $addresses_list[$address] = $address;
        if(!$store->set($key, $addresses_list))
        {
            Lock::release();
            if(get_class($store) == 'Memcached')
            {
                $msg = " registerAddress fail : " . $store->getResultMessage();
            }
            $this->log($msg);
            return false;
        }
        Lock::release();
        return true;
    }
    
    /**
     * 删除全局的通信地址
     * @param string $address
     */
    protected function unregisterAddress()
    {
        $address = $this->lanIp.':'.$this->lanPort;
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        try
        {
            $store = Store::instance('gateway');
        }
        catch (\Exception $msg)
        {
            $this->log($msg);
            return false;
        }
        Lock::get();
        $addresses_list = $store->get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        unset($addresses_list[$address]);
        if(!$store->set($key, $addresses_list))
        {
            Lock::release();
            $msg = "unregisterAddress fail";
            if(get_class($store) == 'Memcached')
            {
                $msg .= " reason:".$store->getResultMessage();
            }
            $this->log($msg);
            return;
        }
        Lock::release();
        return true;
    }
    
    public function ping()
    {
        // 关闭未回复心跳的连接
        foreach($this->_clientConnections as $connection)
        {
            // 上次发送的心跳还没有回复次数大于限定值就断开
            if($this->pingNotResponseLimit > 0 && $connection->pingNotResponseCount >= $this->pingNotResponseLimit)
            {
                $connection->close();
                continue;
            }
            $connection->pingNotResponseCount++;
            $connection->send($this->pingData);
        }
    }
    
    public function onWorkerStop()
    {
        $this->unregisterAddress();
        foreach($this->_clientConnections as $connection)
        {
            $this->delClientAddress($connection->globalClientId);
        }
    }
}