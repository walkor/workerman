<?php 
namespace GatewayWorker;

use \Workerman\Worker;
use \Workerman\Lib\Timer;
use \Workerman\Protocols\GatewayProtocol;
use \GatewayWorker\Lib\Lock;
use \GatewayWorker\Lib\Store;
use \Workerman\Autoloader;

/**
 * 
 * Gateway，基于Worker开发
 * 用于转发客户端的数据给Worker处理，以及转发Worker的数据给客户端
 * 
 * @author walkor<walkor@workerman.net>
 *
 */
class Gateway extends Worker
{
    /**
     * 本机ip
     * @var 单机部署默认127.0.0.1，如果是分布式部署，需要设置成本机ip
     */
    public $lanIp = '127.0.0.1';
    
    /**
     * gateway内部通讯起始端口，每个gateway实例应该都不同，步长1000
     * @var int
     */
    public $startPort = 2000;
    
    /**
     * 是否可以平滑重启，gateway不能平滑重启，否则会导致连接断开
     * @var bool
     */
    public $reloadable = false;
    
    /**
     * 心跳时间间隔
     * @var int
     */
    public $pingInterval = 0;

    /**
     * $pingNotResponseLimit*$pingInterval时间内，客户端未发送任何数据，断开客户端连接
     * @var int
     */
    public $pingNotResponseLimit = 0;
    
    /**
     * 服务端向客户端发送的心跳数据
     * @var string
     */
    public $pingData = '';
    
    /**
     * 保存客户端的所有connection对象
     * @var array
     */
    protected $_clientConnections = array();
    
    /**
     * 保存所有worker的内部连接的connection对象
     * @var array
     */
    protected $_workerConnections = array();
    
    /**
     * gateway内部监听worker内部连接的worker
     * @var Worker
     */
    protected $_innerTcpWorker = null;
    
    /**
     * gateway内部监听udp数据的worker
     * @var Worker
     */
    protected $_innerUdpWorker = null;
    
    /**
     * 当worker启动时
     * @var callback
     */
    protected $_onWorkerStart = null;
    
    /**
     * 当有客户端连接时
     * @var callback
     */
    protected $_onConnect = null;
    
    /**
     * 当客户端发来消息时
     * @var callback
     */
    protected $_onMessage = null;
    
    /**
     * 当客户端连接关闭时
     * @var callback
     */
    protected $_onClose = null;
    
    /**
     * 当worker停止时
     * @var callback
     */
    protected $_onWorkerStop = null;
    
    /**
     * 进程启动时间
     * @var int
     */
    protected $_startTime = 0;
    
    /**
     * 构造函数
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name, $context_option = array())
    {
        parent::__construct($socket_name, $context_option);
        
        $backrace = debug_backtrace();
        $this->_appInitPath = dirname($backrace[0]['file']);
    }
    
    /**
     * 运行
     * @see Workerman.Worker::run()
     */
    public function run()
    {
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onConnect = $this->onConnect;
        $this->onConnect = array($this, 'onClientConnect');
        
        // onMessage禁止用户设置回调
        $this->onMessage = array($this, 'onClientMessage');
        
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onClose = $this->onClose;
        $this->onClose = array($this, 'onClientClose');
        // 保存用户的回调，当对应的事件发生时触发
        $this->_onWorkerStop = $this->onWorkerStop;
        $this->onWorkerStop = array($this, 'onWorkerStop');
        
        // 记录进程启动的时间
        $this->_startTime = time();
        // 运行父方法
        parent::run();
    }
    
    /**
     * 当客户端发来数据时，转发给worker处理
     * @param TcpConnection $connection
     * @param mixed $data
     */
    public function onClientMessage($connection, $data)
    {
        $connection->pingNotResponseCount = 0;
        $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $connection, $data);
    }
    
    /**
     * 当客户端连接上来时，初始化一些客户端的数据
     * 包括全局唯一的client_id、初始化session等
     * @param unknown_type $connection
     */
    public function onClientConnect($connection)
    {
        // 分配一个全局唯一的client_id
        $connection->globalClientId = $this->createGlobalClientId();
        // 保存该连接的内部通讯的数据包报头，避免每次重新初始化
        $connection->gatewayHeader = array(
            'local_ip' => $this->lanIp,
            'local_port' => $this->lanPort,
            'client_ip'=>$connection->getRemoteIp(),
            'client_port'=>$connection->getRemotePort(),
            'client_id'=>$connection->globalClientId,
        );
        // 连接的session
        $connection->session = '';
        // 该连接的心跳参数
        $connection->pingNotResponseCount = 0;
        // 保存客户端连接connection对象
        $this->_clientConnections[$connection->globalClientId] = $connection;
        // 保存该连接的内部gateway通讯地址
        $address = $this->lanIp.':'.$this->lanPort;
        $this->storeClientAddress($connection->globalClientId, $address);
        
        // 如果用户有自定义onConnect回调，则执行
        if($this->_onConnect)
        {
            call_user_func($this->_onConnect, $connection);
        }
        
        // 如果设置了Event::onConnect，则通知worker进程，让worker执行onConnect
        if(method_exists('Event','onConnect'))
        {
            $this->sendToWorker(GatewayProtocol::CMD_ON_CONNECTION, $connection);
        }
    }
    
    /**
     * 发送数据给worker进程
     * @param int $cmd
     * @param TcpConnection $connection
     * @param mixed $body
     */
    protected function sendToWorker($cmd, $connection, $body = '')
    {
        $gateway_data = $connection->gatewayHeader;
        $gateway_data['cmd'] = $cmd;
        $gateway_data['body'] = $body;
        $gateway_data['ext_data'] = $connection->session;
        // 随机选择一个worker处理
        $key = array_rand($this->_workerConnections);
        if($key)
        {
            if(false === $this->_workerConnections[$key]->send($gateway_data))
            {
                $msg = "SendBufferToWorker fail. May be the send buffer are overflow";
                $this->log($msg);
                return false;
            }
        }
        // 没有可用的worker
        else
        {
            // gateway启动后1-2秒内SendBufferToWorker fail是正常现象，因为与worker的连接还没建立起来，所以不记录日志，只是关闭连接
            $time_diff = 2;
            if(time() - $this->_startTime >= $time_diff)
            {
                $msg = "SendBufferToWorker fail. The connections between Gateway and BusinessWorker are not ready";
                $this->log($msg);
            }
            $connection->close();
            return false;
        }
        return true;
    }
    
    /**
     * 保存客户端连接的gateway通讯地址
     * @param int $global_client_id
     * @param string $address
     * @return bool
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
    
    /**
     * 删除客户端gateway通讯地址
     * @param int $global_client_id
     * @return void
     */
    protected function delClientAddress($global_client_id)
    {
        Store::instance('gateway')->delete('gateway-'.$global_client_id);
    }
    
    /**
     * 当客户端关闭时
     * @param unknown_type $connection
     */
    public function onClientClose($connection)
    {
        // 尝试通知worker，触发Event::onClose
        if(method_exists('Event','onClose'))
        {
            $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $connection);
        }
        // 清理连接的数据
        $this->delClientAddress($connection->globalClientId);
        unset($this->_clientConnections[$connection->globalClientId]);
        if($this->_onClose)
        {
            call_user_func($this->_onClose, $connection);
        }
    }
    
    /**
     * 创建一个workerman集群全局唯一的client_id
     * @return int|false
     */
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
    
    /**
     * 当Gateway启动的时候触发的回调函数
     * @return void
     */
    public function onWorkerStart()
    {
        // 分配一个内部通讯端口
        $this->lanPort = $this->startPort - posix_getppid() + posix_getpid();
        if($this->lanPort<0 || $this->lanPort >=65535)
        {
            $this->lanPort = rand($this->startPort, 65535);
        }
        
        // 如果有设置心跳，则定时执行
        if($this->pingInterval > 0)
        {
            Timer::add($this->pingInterval, array($this, 'ping'));
        }
    
        // 初始化gateway内部的监听，用于监听worker的连接已经连接上发来的数据
        $this->_innerTcpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $this->_innerTcpWorker->listen();
        $this->_innerUdpWorker = new Worker("GatewayProtocol://{$this->lanIp}:{$this->lanPort}");
        $this->_innerUdpWorker->transport = 'udp';
        $this->_innerUdpWorker->listen();
    
        // 重新设置自动加载根目录
        Autoloader::setRootPath($this->_appInitPath);
        
        // 设置内部监听的相关回调
        $this->_innerTcpWorker->onMessage = array($this, 'onWorkerMessage');
        $this->_innerUdpWorker->onMessage = array($this, 'onWorkerMessage');
        
        $this->_innerTcpWorker->onConnect = array($this, 'onWorkerConnect');
        $this->_innerTcpWorker->onClose = array($this, 'onWorkerClose');
        
        // 注册gateway的内部通讯地址，worker去连这个地址，以便gateway与worker之间建立起TCP长连接
        if(!$this->registerAddress())
        {
            $this->log('registerAddress fail and exit');
            Worker::stopAll();
        }
        
        if($this->_onWorkerStart)
        {
            call_user_func($this->_onWorkerStart, $this);
        }
    }
    
    
    /**
     * 当worker通过内部通讯端口连接到gateway时
     * @param TcpConnection $connection
     */
    public function onWorkerConnect($connection)
    {
        $connection->remoteAddress = $connection->getRemoteIp().':'.$connection->getRemotePort();
        $this->_workerConnections[$connection->remoteAddress] = $connection;
    }
    
    /**
     * 当worker发来数据时
     * @param TcpConnection $connection
     * @param mixed $data
     * @throws \Exception
     */
    public function onWorkerMessage($connection, $data)
    {
        $cmd = $data['cmd'];
        switch($cmd)
        {
            // 向某客户端发送数据，Gateway::sendToClient($client_id, $message);
            case GatewayProtocol::CMD_SEND_TO_ONE:
                if(isset($this->_clientConnections[$data['client_id']]))
                {
                    $this->_clientConnections[$data['client_id']]->send($data['body']);
                }
                break;
                // 关闭客户端连接，Gateway::closeClient($client_id);
            case GatewayProtocol::CMD_KICK:
                if(isset($this->_clientConnections[$data['client_id']]))
                {
                    $this->_clientConnections[$data['client_id']]->close();
                }
                break;
                // 广播, Gateway::sendToAll($message, $client_id_array)
            case GatewayProtocol::CMD_SEND_TO_ALL:
                // $client_id_array不为空时，只广播给$client_id_array指定的客户端
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
                // $client_id_array为空时，广播给所有在线客户端
                else
                {
                    foreach($this->_clientConnections as $client_connection)
                    {
                        $client_connection->send($data['body']);
                    }
                }
                break;
                // 更新客户端session
            case GatewayProtocol::CMD_UPDATE_SESSION:
                if(isset($this->_clientConnections[$data['client_id']]))
                {
                    $this->_clientConnections[$data['client_id']]->session = $data['ext_data'];
                }
                break;
                // 获得客户端在线状态 Gateway::getOnlineStatus()
            case GatewayProtocol::CMD_GET_ONLINE_STATUS:
                $online_status = json_encode(array_keys($this->_clientConnections));
                $connection->send($online_status);
                break;
                // 判断某个client_id是否在线 Gateway::isOnline($client_id)
            case GatewayProtocol::CMD_IS_ONLINE:
                $connection->send((int)isset($this->_clientConnections[$data['client_id']]));
                break;
            default :
                $err_msg = "gateway inner pack err cmd=$cmd";
                throw new \Exception($err_msg);
        }
    }
    
    /**
     * 当worker连接关闭时
     * @param TcpConnection $connection
     */
    public function onWorkerClose($connection)
    {
        //$this->log("{$connection->remoteAddress} CLOSE INNER_CONNECTION\n");
        unset($this->_workerConnections[$connection->remoteAddress]);
    }
    
    /**
     * 存储当前Gateway的内部通信地址
     * @param string $address
     * @return bool
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
        // 为保证原子性，需要加锁
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
     * 删除当前Gateway的内部通信地址
     * @param string $address
     * @return bool
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
        // 为保证原子性，需要加锁
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
    
    /**
     * 心跳逻辑
     * @return void
     */
    public function ping()
    {
        // 遍历所有客户端连接
        foreach($this->_clientConnections as $connection)
        {
            // 上次发送的心跳还没有回复次数大于限定值就断开
            if($this->pingNotResponseLimit > 0 && $connection->pingNotResponseCount >= $this->pingNotResponseLimit)
            {
                $connection->close();
                continue;
            }
            $connection->pingNotResponseCount++;
            if($this->pingData)
            {
                $connection->send($this->pingData);
            }
        }
    }
    
    /**
     * 当gateway关闭时触发，清理数据
     * @return void
     */
    public function onWorkerStop()
    {
        $this->unregisterAddress();
        foreach($this->_clientConnections as $connection)
        {
            $this->delClientAddress($connection->globalClientId);
        }
        // 尝试触发用户设置的回调
        if($this->_onWorkerStop)
        {
            call_user_func($this->_onWorkerStop, $this);
        }
    }
}