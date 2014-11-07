<?php
/**
 * 
 * 暴露给客户端的连接网关 只负责网络io
 * 1、监听客户端连接
 * 2、监听后端回应并转发回应给前端
 * 
 * @author walkor <walkor@workerman.net>
 * 
 */
require_once __DIR__ . '/../Lib/Autoloader.php';
use \Protocols\GatewayProtocol;
use \Lib\Store;
use \Lib\StatisticClient;

class Gateway extends Man\Core\SocketWorker
{
    /**
     * 内部通信socket udp
     * @var resouce
     */
    protected $innerMainSocketUdp = null;
    
    /**
     * 内部通信socket tcp
     * @var resouce
     */
    protected $innerMainSocketTcp = null;
    
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
     * client_id到连接的映射
     * @var array
     */
    protected $clientConnMap = array();
    
    /**
     * 连接到client_id的映射
     * @var array
     */
    protected $connClientMap = array();
    
    /**
     * 客户端链接和客户端远程地址映射
     * @var array
     */
    protected $connRemoteAddressMap = array();
    
    /**
     * client_id到session的映射
     * @var array
     */
    protected $connSessionMap = array();
    
    /**
     * 与worker的连接
     * [fd=>fd, $fd=>fd, ..]
     * @var array
     */
    protected $workerConnections = array();
    
    /**
     * 客户端心跳信息
     * [client_id=>not_response_count, client_id=>not_response_count, ..]
     */
    protected $pingInfo = array();
    
    /**
     * gateway 发送心跳时间间隔 单位：秒 ,0表示不发送心跳，在配置中设置
     * @var integer
     */
    protected $pingInterval = 0;
    
    /**
     * 心跳数据
     * 可以是二进制数据(二进制数据保存在文件中，在配置中设置ping数据文件路径 如 ping_data=/yourpath/ping.bin)
     * ping数据应该是客户端能够识别的数据格式，客户端必须回复心跳，不然链接会断开
     * @var string
     */
    protected $pingData = '';
    
    /**
     * 客户端连续$pingNotResponseLimit次不回应心跳则断开链接
     */
    protected $pingNotResponseLimit = 0;
    
    /**
     * 命令字，统计用到
     * @var array
     */
    protected static $interfaceMap = array(
            GatewayProtocol::CMD_SEND_TO_ONE             => 'CMD_SEND_TO_ONE',
            GatewayProtocol::CMD_SEND_TO_ALL             => 'CMD_SEND_TO_ALL',
            GatewayProtocol::CMD_KICK                    => 'CMD_KICK',
            GatewayProtocol::CMD_UPDATE_SESSION          => 'CMD_UPDATE_SESSION',
            GatewayProtocol::CMD_GET_ONLINE_STATUS       => 'CMD_GET_ONLINE_STATUS',
            GatewayProtocol::CMD_IS_ONLINE               => 'CMD_IS_ONLINE',
     );
    
    /**
     * 由于网络延迟或者socket缓冲区大小的限制，客户端发来的数据可能不会都全部到达，需要根据协议判断数据是否完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_buffer)
    {
        // 处理粘包
        return Event::onGatewayMessage($recv_buffer);
    }
    
    /**
     * 用户客户端发来消息时处理
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_buffer)
    {
        // 客户端发来任何一个完整的包都视为回应了服务端发的心跳
        if(!empty($this->pingInfo[$this->connClientMap[$this->currentDealFd]]))
        {
            $this->pingInfo[$this->connClientMap[$this->currentDealFd]] = 0;
        }
        
        // 统计打点
        StatisticClient::tick(__CLASS__, 'CMD_ON_MESSAGE');
        // 触发ON_MESSAGE
        if(false === $this->sendToWorker(GatewayProtocol::CMD_ON_MESSAGE, $this->currentDealFd, $recv_buffer))
        {
            return StatisticClient::report(__CLASS__, 'CMD_ON_MESSAGE', 0, 132, __CLASS__.'::dealProcess()->sendToWorker() fail');
        }
        StatisticClient::report(__CLASS__, 'CMD_ON_MESSAGE', 1, 0, '');
    }
    
    /**
     * 进程启动
     */
    public function start()
    {
        // 安装信号处理函数
        $this->installSignal();
        
        // 添加accept事件
        $ret = $this->event->add($this->mainSocket,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'accept'));
        
        // 创建内部通信套接字，用于与BusinessWorker通讯
        $start_port = Man\Core\Lib\Config::get($this->workerName.'.lan_port_start');
        // 计算本进程监听的ip端口
        if(strpos(\Man\Core\Master::VERSION, 'mt'))
        {
            $this->lanPort = $start_port + \Thread::getCurrentThreadId()%100;
        }
        else 
        {
            $this->lanPort = $start_port - posix_getppid() + posix_getpid();
        }
        // 如果端口不在合法范围
        if($this->lanPort<0 || $this->lanPort >=65535)
        {
            $this->lanPort = rand($start_port, 65535);
        }
        // 如果
        $this->lanIp = Man\Core\Lib\Config::get($this->workerName.'.lan_ip');
        if(!$this->lanIp)
        {
            $this->notice($this->workerName.'.lan_ip not set');
            $this->lanIp = '127.0.0.1';
        }
        $error_no_udp = $error_no_tcp = 0;
        $error_msg_udp = $error_msg_tcp = '';
        // 执行监听
        $this->innerMainSocketUdp = stream_socket_server("udp://".$this->lanIp.':'.$this->lanPort, $error_no_udp, $error_msg_udp, STREAM_SERVER_BIND);
        $this->innerMainSocketTcp = stream_socket_server("tcp://".$this->lanIp.':'.$this->lanPort, $error_no_tcp, $error_msg_tcp, STREAM_SERVER_BIND | STREAM_SERVER_LISTEN);
        // 出错，退出，下次会换个端口
        if(!$this->innerMainSocketUdp || !$this->innerMainSocketTcp)
        {
            $this->notice('create innerMainSocket udp or tcp fail and exit '.$error_msg_udp.$error_msg_tcp);
            $this->stop();
        }
        else
        {
            stream_set_blocking($this->innerMainSocketUdp , 0);
            stream_set_blocking($this->innerMainSocketTcp , 0);
        }
        
        // 注册套接字
        if(!$this->registerAddress($this->lanIp.':'.$this->lanPort))
        {
            $this->notice('registerAddress fail and exit');
            $this->stop();
        }
        
        // 添加读udp/tcp事件
        $this->event->add($this->innerMainSocketUdp,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'recvInnerUdp'));
        $this->event->add($this->innerMainSocketTcp,  Man\Core\Events\BaseEvent::EV_READ, array($this, 'acceptInnerTcp'));
        
        // 初始化心跳包时间间隔
        $ping_interval = \Man\Core\Lib\Config::get($this->workerName.'.ping_interval');
        if((int)$ping_interval > 0)
        {
            $this->pingInterval = (int)$ping_interval;
        }
        
        // 获取心跳包数据
        $ping_data_or_path = \Man\Core\Lib\Config::get($this->workerName.'.ping_data');
        if(is_file($ping_data_or_path))
        {
            $this->pingData = file_get_contents($ping_data_or_path);
        }
        else
        {
            $this->pingData = $ping_data_or_path;
        }
        
        // 不返回心跳回应（客户端发来的任何数据都算是回应）的限定值
        $this->pingNotResponseLimit = (int)\Man\Core\Lib\Config::get($this->workerName.'.ping_not_response_limit');
        
        // 设置定时任务，发送心跳
        if($this->pingInterval > 0)
        {
            \Man\Core\Lib\Task::init($this->event);
            \Man\Core\Lib\Task::add($this->pingInterval, array($this, 'ping'));
        }
        
        // 主体循环,整个子进程会阻塞在这个函数上
        $ret = $this->event->loop();
        // 下面正常不会执行到
        $this->notice('worker loop exit');
        // 执行到就退出
        exit(0);
    }
    
    
    /**
     * 接受一个链接
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function accept($socket, $null_one = null, $null_two = null)
    {
        // 获得一个连接
        $new_connection = @stream_socket_accept($socket, 0);
        // 可能是惊群效应
        if(false === $new_connection)
        {
            $this->statusInfo['thunder_herd']++;
            return false;
        }
        
        // 连接的fd序号
        $fd = (int) $new_connection;
        $this->connections[$fd] = $new_connection;
        $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>$this->prereadLength);
        $this->connSessionMap[$fd] = '';
 
        // 非阻塞
        stream_set_blocking($this->connections[$fd], 0);
        $this->event->add($this->connections[$fd], Man\Core\Events\BaseEvent::EV_READ , array($this, 'dealInputBase'), $fd);
        
        // 全局唯一client_id
        $global_client_id = $this->createGlobalClientId();
        $this->clientConnMap[$global_client_id] = $fd;
        $this->connClientMap[$fd] = $global_client_id;
        $address = array('local_ip'=>$this->lanIp, 'local_port'=>$this->lanPort, 'socket_id'=>$fd);
        
        // 保存客户端内部通讯地址
        $this->storeClientAddress($global_client_id, $address);
        
        // 客户端保存 ip:port
        $address= $this->getRemoteAddress($fd);
        if($address)
        {
            list($client_ip, $client_port) = explode(':', $address, 2);
        }
        else
        {
            $client_ip = 0;
            $client_port = 0;
        }
        $this->connRemoteAddressMap[$fd] = array('ip'=>$client_ip, 'port'=>$client_port);
        
        // 触发GatewayOnConnection事件
        if(method_exists('Event','onGatewayConnect'))
        {
            // 统计打点
            StatisticClient::tick(__CLASS__, 'CMD_ON_GATEWAY_CONNECTION');
            if(false === $this->sendToWorker(GatewayProtocol::CMD_ON_GATEWAY_CONNECTION, $fd))
            {
                StatisticClient::report(__CLASS__, 'CMD_ON_GATEWAY_CONNECTION', 0, 131, __CLASS__.'::accept()->sendToWorker() fail');
            }
            else
            {
                StatisticClient::report(__CLASS__, 'CMD_ON_GATEWAY_CONNECTION', 1, 0, '');
            }
        }
        
        return $new_connection;
    }
    
    /**
     * 存储全局的通信地址 
     * @param string $address
     */
    protected function registerAddress($address)
    {
        // 统计打点
        StatisticClient::tick(__CLASS__, 'registerAddress');
        // 这里使用了信号量只能实现单机互斥，分布式互斥需要借助于memcached incr cas 或者其他分布式存储
        \Man\Core\Lib\Mutex::get();
        // key
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        // 获取实例
        try 
        {
            $store = Store::instance('gateway');
        }
        catch(\Exception $msg)
        {
            StatisticClient::report(__CLASS__, 'registerAddress', 0, 107, $msg);
            \Man\Core\Lib\Mutex::release();
            return false;
        }
        // 获取数据
        $addresses_list = $store->get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        // 添加数据
        $addresses_list[$address] = $address;
        // 存储
        if(!$store->set($key, $addresses_list))
        {
            // 存储失败
            \Man\Core\Lib\Mutex::release();
            $msg = "注册gateway通信地址出错";
            if(get_class($store) == 'Memcached')
            {
                $msg .= " 原因:".$store->getResultMessage();
            }
            $this->notice($msg);
            StatisticClient::report(__CLASS__, 'registerAddress', 0, 107, new \Exception($msg));
            return false;
        }
        // 存储成功
        \Man\Core\Lib\Mutex::release();
        StatisticClient::report(__CLASS__, 'registerAddress', 1, 0, '');
        return true;
    }
    
    /**
     * 删除全局的通信地址
     * @param string $address
     */
    protected function unregisterAddress($address)
    {
        // 统计打点
        StatisticClient::tick(__CLASS__, 'unregisterAddress');
        // 这里使用了信号量只能实现单机互斥，分布式互斥需要借助于memcached incr cas或者其他分布式存储
        \Man\Core\Lib\Mutex::get();
        // key
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        // 获取存储实例
        try 
        {
            $store = Store::instance('gateway');
        }
        catch (\Exception $msg)
        {
            StatisticClient::report(__CLASS__, 'unregisterAddress', 0, 108, $msg);
            \Man\Core\Lib\Mutex::release();
            return false;
        }
        // 获取数据
        $addresses_list = $store->get($key);
        if(empty($addresses_list))
        {
            $addresses_list = array();
        }
        // 去掉要删除的数据
        unset($addresses_list[$address]);
        // 保存数据
        if(!$store->set($key, $addresses_list))
        {
            \Man\Core\Lib\Mutex::release();
            $msg = "删除gateway通信地址出错";
            if(get_class($store) == 'Memcached')
            {
                $msg .= " 原因:".$store->getResultMessage();
            }
            $this->notice($msg);
            StatisticClient::report(__CLASS__, 'unregisterAddress', 0, 108, new \Exception($msg));
            return;
        }
        // 存储成功
        \Man\Core\Lib\Mutex::release();
        StatisticClient::report(__CLASS__, 'unregisterAddress', 1, 0, '');
    }
    
    /**
     * 接收Udp数据
     * 如果数据超过一个udp包长，需要业务自己解析包体，判断数据是否全部到达
     * @param resource $socket
     * @param $null_one $flag
     * @param $null_two $base
     * @return void
     */
    public function recvInnerUdp($socket, $null_one = null, $null_two = null)
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
    
    /**
     * 内部通讯端口接受BusinessWorker连接请求，以便建立起长连接
     * @param resouce $socket
     * @param null $null_one
     * @param null $null_two
     */
    public function acceptInnerTcp($socket, $null_one = null, $null_two = null)
    {
        // 获得一个连接
        $new_connection = @stream_socket_accept($socket, 0);
        if(false === $new_connection)
        {
            return false;
        }
    
        // 连接的fd序号
        $fd = (int) $new_connection;
        $this->connections[$fd] = $new_connection;
        $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>GatewayProtocol::HEAD_LEN);
    
        // 非阻塞
        stream_set_blocking($this->connections[$fd], 0);
        $this->event->add($this->connections[$fd], \Man\Core\Events\BaseEvent::EV_READ , array($this, 'recvInnerTcp'), $fd);
        
        // 标记这个连接是内部通讯长连接，区别于客户端连接
        $this->workerConnections[$fd] = $fd;
        return $new_connection;
    }
    
    /**
     * 内部通讯判断数据是否全部到达
     * @param string $buffer
     */
    public function dealInnerInput($buffer)
    {
        return GatewayProtocol::input($buffer);
    }
    
    /**
     * 处理内部通讯收到的数据
     * @param event_buffer $event_buffer
     * @param int $fd
     * @return void
     */
    public function recvInnerTcp($connection, $flag, $fd = null)
    {
        $this->currentDealFd = $fd;
        $buffer = stream_socket_recvfrom($connection, $this->recvBuffers[$fd]['remain_len']);
        // 出错了
        if('' == $buffer && '' == ($buffer = fread($connection, $this->recvBuffers[$fd]['remain_len'])))
        {
            // 判断是否是链接断开
            if(!feof($connection))
            {
                return;
            }
            
            // 如果该链接对应的buffer有数据，说明发生错误
            if(!empty($this->recvBuffers[$fd]['buf']))
            {
                $this->statusInfo['send_fail']++;
            }
    
            // 关闭链接
            $this->closeInnerClient($fd);
            $this->notice("CLIENT:".$this->getRemoteIp()." CLOSE INNER_CONNECTION\n");
            
            if($this->workerStatus == self::STATUS_SHUTDOWN)
            {
                $this->stop();
            }
            return;
        }
    
        $this->recvBuffers[$fd]['buf'] .= $buffer;
    
        $remain_len = $this->dealInnerInput($this->recvBuffers[$fd]['buf']);
        // 包接收完毕
        if(0 === $remain_len)
        {
            // 内部通讯业务处理
            $this->innerDealProcess($this->recvBuffers[$fd]['buf']);
            $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>GatewayProtocol::HEAD_LEN);
        }
        // 出错
        else if(false === $remain_len)
        {
            // 出错
            $this->statusInfo['packet_err']++;
            $this->notice("INNER_PACKET_ERROR and CLOSE_INNER_CONNECTION\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".bin2hex($this->recvBuffers[$fd]['buf'])."]\n");
            $this->closeInnerClient($fd);
        }
        else
        {
            $this->recvBuffers[$fd]['remain_len'] = $remain_len;
        }
    
        // 检查是否是关闭状态或者是否到达请求上限
        if($this->workerStatus == self::STATUS_SHUTDOWN )
        {
            // 停止服务
            $this->stop();
            // EXIT_WAIT_TIME秒后退出进程
            pcntl_alarm(self::EXIT_WAIT_TIME);
        }
    }

    /**
     * 内部通讯处理
     * @param string $recv_buffer
     */
    public function innerDealProcess($recv_buffer)
    {
        $pack = new GatewayProtocol($recv_buffer);
        $cmd = $pack->header['cmd'];
        $interface = isset(self::$interfaceMap[$cmd]) ? self::$interfaceMap[$cmd] : $cmd;
        StatisticClient::tick(__CLASS__, $interface);
        try
        {
            switch($cmd)
            {
                // 向某客户端发送数据
                case GatewayProtocol::CMD_SEND_TO_ONE:
                    if(false === $this->sendToSocketId($pack->header['socket_id'], $pack->body))
                    {
                        throw new \Exception('发送数据到客户端失败，可能是该客户端的发送缓冲区已满，或者客户端已经下线', 121);
                    }
                    break;
                // 踢掉客户端
                case GatewayProtocol::CMD_KICK:
                    $this->closeClient($pack->header['socket_id']);
                    break;
                // 向所客户端发送数据
                case GatewayProtocol::CMD_SEND_TO_ALL:
                    if($pack->ext_data)
                    {
                        $client_id_array = unpack('N*', $pack->ext_data);
                        foreach($client_id_array as $client_id)
                        {
                            if(isset($this->clientConnMap[$client_id]))
                            {
                                $this->sendToSocketId($this->clientConnMap[$client_id], $pack->body);
                            }
                        }
                    }
                    else
                    {
                        $this->broadCast($pack->body);
                    }
                    break;
                // 更新某个客户端的session
                case GatewayProtocol::CMD_UPDATE_SESSION:
                    if(isset($this->connSessionMap[$pack->header['socket_id']]))
                    {
                        $this->connSessionMap[$pack->header['socket_id']] = $pack->ext_data;
                    }
                    break;
                // 获得在线状态
                case GatewayProtocol::CMD_GET_ONLINE_STATUS:
                    $online_status = json_encode(array_values($this->connClientMap));
                    stream_socket_sendto($this->innerMainSocketUdp, $online_status, 0, $this->currentClientAddress);
                    break;
                // 判断某个客户端id是否在线
                case GatewayProtocol::CMD_IS_ONLINE:
                    stream_socket_sendto($this->innerMainSocketUdp, (int)isset($this->clientConnMap[$pack->header['client_id']]), 0, $this->currentClientAddress);
                    break;
                // 未知的命令
                default :
                    $err_msg = "gateway inner pack err cmd=$cmd";
                    $this->notice($err_msg);
                    throw new \Exception($err_msg, 110);
            }
        }
        catch(\Exception $msg)
        {
            StatisticClient::report(__CLASS__, $interface, 0, $msg->getCode() > 0 ? $msg->getCode() : 111, $msg);
            return;
        }
        StatisticClient::report(__CLASS__, $interface, 1, 0, '');
    }
    
    /**
     * 广播数据
     * @param string $bin_data
     */
    protected function broadCast($bin_data)
    {
        foreach($this->clientConnMap as $client_id=>$conn)
        {
            $this->sendToSocketId($conn, $bin_data);
        }
    }
    
    /**
     * 根据client_id获取client_id对应连接的socket_id
     * @param int $client_id
     */
    protected function getFdByClientId($client_id)
    {
        if(isset($this->clientConnMap[$client_id]))
        {
            return $this->clientConnMap[$client_id];
        }
        return 0;
    }
    
    /**
     * 根据连接socket_id获取client_id
     * @param int $fd
     */
    protected function getClientIdByFd($fd)
    {
        if(isset($this->connClientMap[$fd]))
        {
            return $this->connClientMap[$fd];
        }
        return 0;
    }
    
    /**
     * 向某个socket_id的连接发送消息
     * @param int $socket_id
     * @param string $bin_data
     */
    public function sendToSocketId($socket_id, $bin_data)
    {
        if(!isset($this->connections[$socket_id]))
        {
            return null;
        }
        $this->currentDealFd = $socket_id;
        return $this->sendToClient($bin_data);
    }

    /**
     * 用户客户端关闭连接时触发
     * @see Man\Core.SocketWorker::closeClient()
     */
    protected function closeClient($fd = null)
    {
        if($client_id = $this->getClientIdByFd($fd))
        {
            $this->sendToWorker(GatewayProtocol::CMD_ON_CLOSE, $fd);
            $this->deleteClientAddress($client_id);
            unset($this->clientConnMap[$client_id]);
        }
        unset($this->connClientMap[$fd], $this->connSessionMap[$fd], $this->connRemoteAddressMap[$fd]);
        parent::closeClient($fd);
    }
    
    /**
     * 内部通讯socket在BusinessWorker主动关闭连接时触发
     * @param int $fd
     */
    protected function closeInnerClient($fd)
    {
        unset($this->workerConnections[$fd]);
        parent::closeClient($fd);
    }
    
    /**
     * 随机抽取一个与BusinessWorker的长连接，将数据发给一个BusinessWorker
     * @param int $cmd
     * @param int $socket_id
     * @param string $body
     */
    protected function sendToWorker($cmd, $socket_id, $body = '')
    {
        $pack = new GatewayProtocol();
        $pack->header['cmd'] = $cmd;
        $pack->header['local_ip'] = $this->lanIp;
        $pack->header['local_port'] = $this->lanPort;
        $pack->header['socket_id'] = $socket_id;
        $pack->header['client_ip'] = $this->connRemoteAddressMap[$socket_id]['ip'];
        $pack->header['client_port'] = $this->connRemoteAddressMap[$socket_id]['port'];
        $pack->header['client_id'] = $this->getClientIdByFd($socket_id);
        $pack->body = $body;
        $pack->ext_data = $this->connSessionMap[$pack->header['socket_id']];
        return $this->sendBufferToWorker($pack->getBuffer());
    }
    
    /**
     * 随机抽取一个与BusinessWorker的长连接，将数据发给一个BusinessWorker
     * @param string $bin_data
     */
    protected function sendBufferToWorker($bin_data)
    {
        if($this->currentDealFd = array_rand($this->workerConnections))
        {
             if(false === $this->sendToClient($bin_data))
             {
                 $msg = "sendBufferToWorker fail. May be the send buffer are overflow";
                 $this->notice($msg);
                 StatisticClient::report(__CLASS__, 'sendBufferToWorker', 0, 101, new \Exception($msg));
                 return false;
             }
        }
        else
        {
            $msg = "sendBufferToWorker fail. the Connections between Gateway and BusinessWorker are not ready";
            $this->notice($msg);
            StatisticClient::report(__CLASS__, 'sendBufferToWorker', 0, 102, new \Exception($msg));
            return false;
        }
        StatisticClient::report(__CLASS__, 'sendBufferToWorker', 1, 0, '');
    }
    
    /**
     * 打印日志
     * @see Man\Core.AbstractWorker::notice()
     */
    protected function notice($str, $display=true)
    {
        $str = 'Worker['.get_class($this).']:'."$str";
        Man\Core\Lib\Log::add($str);
        if($display && Man\Core\Lib\Config::get('workerman.debug') == 1)
        {
            echo $str."\n";
        }
    }
    
    /**
     * 进程停止时，清除一些数据，忽略错误
     * @see Man\Core.SocketWorker::onStop()
     */
    public function onStop()
    {
        $this->unregisterAddress($this->lanIp.':'.$this->lanPort);
        foreach($this->connClientMap as $client_id)
        {
            Store::instance('gateway')->delete($client_id);
        }
    }
    
    /**
     * 创建全局唯一的id
     */
    protected function createGlobalClientId()
    {
        StatisticClient::tick(__CLASS__, 'createGlobalClientId');
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
            $msg = "生成全局client_id出错";
            if(get_class($store) == 'Memcached')
            {
                $msg .= " 原因:".$store->getResultMessage(); 
            }
            $this->notice($msg);
            StatisticClient::report(__CLASS__, 'createGlobalClientId', 0, 104, new \Exception($msg));
            return $global_client_id;
        }
        StatisticClient::report(__CLASS__, 'createGlobalClientId', 1, 0, '');
        return $global_client_id;
    }
    
    /**
     * 保存客户端内部通讯地址
     * @param int $global_client_id
     * @param string $address
     */
    protected function storeClientAddress($global_client_id, $address)
    {
        StatisticClient::tick(__CLASS__, 'storeClientAddress');
        if(!Store::instance('gateway')->set($global_client_id, $address))
        {
            $msg = "保存客户端通讯地址出错";
            if(get_class(Store::instance('gateway')) == 'Memcached')
            {
                $msg .= " 原因:".Store::instance('gateway')->getResultMessage();
            }
            $this->notice($msg);
            return StatisticClient::report(__CLASS__, 'storeClientAddress', 0, 105, new \Exception($msg));
        }
        StatisticClient::report(__CLASS__, 'storeClientAddress', 1, 0, '');
    }
    
    /**
     * 删除客户端内部通讯地址
     * @param int $global_client_id
     * @param string $address
     */
    protected function deleteClientAddress($global_client_id)
    {
        StatisticClient::tick(__CLASS__, 'deleteClientAddress');
        if(!Store::instance('gateway')->delete($global_client_id))
        {
            $msg = "删除客户端通讯地址出错";
            if(get_class(Store::instance('gateway')) == 'Memcached')
            {
                $msg .= " 原因:".Store::instance('gateway')->getResultMessage();
            }
            $this->notice($msg);
            return StatisticClient::report(__CLASS__, 'deleteClientAddress', 0, 106, new \Exception($msg));
        }
        StatisticClient::report(__CLASS__, 'deleteClientAddress', 1, 0, '');
    }
    
    /**
     * 向客户端发送心跳数据
     * 并把规定时间内没回应(客户端发来的任何数据都算是回应)的客户端踢掉
     */
    public function ping()
    {
        // 清理下线的链接
        foreach($this->pingInfo as $client_id=>$not_response_count)
        {
            // 已经下线的忽略
            if(!isset($this->clientConnMap[$client_id]))
            {
                unset($this->pingInfo[$client_id]);
                continue;
            }
            // 上次发送的心跳还没有回复次数大于限定值就断开
            if($this->pingNotResponseLimit > 0 && $not_response_count >= $this->pingNotResponseLimit)
            {
                $this->closeClient($this->clientConnMap[$client_id]);
            }
        }
        // 向所有链接发送心跳数据
        foreach($this->clientConnMap as $client_id=>$conn)
        {
            // 如果没设置ping的数据，则不发送心跳给客户端，但是要求客户端在规定的时间内（ping_interval*ping_not_response_limit）有请求发来，不然会断开
            if($this->pingData)
            {
                $this->sendToSocketId($conn, $this->pingData);
            }
            if(isset($this->pingInfo[$client_id]))
            {
                $this->pingInfo[$client_id]++;
            }
            else
            {
                $this->pingInfo[$client_id] = 1;
            }
        }
    }
}
