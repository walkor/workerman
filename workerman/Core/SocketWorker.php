<?php
namespace Man\Core;
use Man\Core\Events\BaseEvent;

require_once WORKERMAN_ROOT_DIR . 'Core/Events/Select.php';
require_once WORKERMAN_ROOT_DIR . 'Core/AbstractWorker.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Config.php';

/**
 * SocketWorker 监听某个端口，对外提供网络服务的worker
 * 
* @author walkor <walkor@workerman.net>
* 
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * $worker = new SocketWorker();
 * $worker->start();
 * <code>
 * </pre>
 */

abstract class SocketWorker extends AbstractWorker
{
    
    /**
     * udp最大包长 linux:65507 mac:9216
     * @var integer
     */ 
    const MAX_UDP_PACKEG_SIZE = 65507;
    
    /**
     * 停止服务后等待EXIT_WAIT_TIME秒后还没退出则强制退出
     * @var integer
     */
    const EXIT_WAIT_TIME = 3;
    
    /**
     * worker的传输层协议
     * @var string
     */
    protected $protocol = "tcp";
    
    /**
     * worker监听端口的Socket
     * @var resource
     */
    protected $mainSocket = null;
    
    /**
     * worker接受的所有链接
     * @var array
     */
    protected $connections = array();
    
    /**
     * 客户端连接的读buffer
     * @var array
     */
    protected $recvBuffers = array();
    
    /**
     * 接收缓冲区最大值 单位字节 默认10MB
     * @var int
     */
    protected $maxRecvBufferSize = 10485760;
    
    /**
     *  客户端连接的写buffer
     * @var array
     */
    protected $sendBuffers = array();
    
    /**
     * 发送缓冲区最大自 单位字节 默认20MB
     * @var int
     */
    protected $maxSendBufferSize = 20971520;
    
    /**
     * 当前处理的fd
     * @var integer
     */
    protected $currentDealFd = 0;
    
    /**
     * UDP当前处理的客户端地址
     * @var string
     */
    protected $currentClientAddress = '';
    
    /**
     * 是否是长链接，(短连接每次请求后服务器主动断开，长连接一般是客户端主动断开)
     * @var bool
     */
    protected $isPersistentConnection = false;
    
    /**
     * 事件轮询库的名称
     * @var string
     */
    protected $eventLoopName ="\\Man\\Core\\Events\\Select";
    
    /**
     * 事件轮询库实例
     * @var object
     */
    protected $event = null;
    
    /**
     * 该worker进程处理多少请求后退出，0表示不自动退出
     * @var integer
     */
    protected $maxRequests = 0;
    
    /**
     * 预读长度
     * @var integer
     */
    protected $prereadLength = 4;
    
    /**
     * 该进程使用的php文件
     * @var array
     */
    protected $includeFiles = array();
    
    /**
     * 统计信息
     * @var array
     */
    protected $statusInfo = array(
        'start_time'      => 0, // 该进程开始时间戳
        'total_request'   => 0, // 该进程处理的总请求数
        'packet_err'      => 0, // 该进程收到错误数据包的总数
        'throw_exception' => 0, // 该进程逻辑处理时收到异常的总数
        'thunder_herd'    => 0, // 该进程受惊群效应影响的总数
        'client_close'    => 0, // 客户端提前关闭链接总数
        'send_fail'       => 0, // 发送数据给客户端失败总数
    );
    
    
    /**
     * 必须实现该方法，根据具体协议和当前收到的数据决定是否继续收包
     * @param string $bin 收到的数据包(可能是二进制)
     * @return int/false 返回0表示接收完毕 ; int>0表示还有int字节没有接收; false数据包出错（例如数据包不合法等）
     */
    abstract public function dealInput($bin);
    
    
    /**
     * 必须实现该方法，根据包中的数据处理逻辑
     * @param string $bin 收到的数据包
     * @return void
     */
    abstract public function dealProcess($bin);
    
    
    /**
     * 构造函数
     * @param int $port
     * @param string $ip
     * @param string $protocol
     * @return void
     */
    public function __construct($worker_name = null)
    {
        // worker name
        $this->workerName = $worker_name ? $worker_name : get_class($this);
        
        // 是否开启长连接
        $this->isPersistentConnection = (bool)Lib\Config::get( $this->workerName . '.persistent_connection');
        // 最大请求数，超过这个数则安全重启，如果没有配置则使用PHP_INT_MAX
        $this->maxRequests = (int)Lib\Config::get( $this->workerName . '.max_requests');
        $this->maxRequests = $this->maxRequests <= 0 ? PHP_INT_MAX : $this->maxRequests;

        // 预读数据长度，长连接需要设置此项
        $preread_length = (int)Lib\Config::get( $this->workerName . '.preread_length');
        if($preread_length > 0)
        {
            $this->prereadLength = $preread_length;
        }
        elseif(!$this->isPersistentConnection)
        {
            $this->prereadLength = 65535;
        }
        
        // 接收缓冲区大小限制
        if(($max_recv_buffer_size = Lib\Config::get($this->workerName . '.max_recv_buffer_size')) && $max_recv_buffer_size > 0)
        {
            $this->maxRecvBufferSize = $max_recv_buffer_size;
        }
        
        // 发送缓冲区大小限制
        if(($max_send_buffer_size = Lib\Config::get($this->workerName . '.max_send_buffer_size')) && $max_send_buffer_size > 0)
        {
            $this->maxSendBufferSize = $max_send_buffer_size;
        }
        
        // worker启动时间
        $this->statusInfo['start_time'] = time();
        
        //事件轮询库
        if(extension_loaded('libevent'))
        {
            $this->setEventLoopName('Libevent');
        }
        
        // 检查退出状态
        $this->addShutdownHook();
        
        // 初始化事件轮询库
        $this->event = new $this->eventLoopName();
        
        // 初始化任务系统
        \Man\Core\Lib\Task::init($this->event);
    }
    
    
    /**
     * 让该worker实例开始服务
     *
     * @return void
     */
    public function start()
    {
        // 安装信号处理函数
        $this->installSignal();
        
        // 触发该worker进程onStart事件，该进程整个生命周期只触发一次
        $this->onStart();

        // 监听事件
        if($this->mainSocket)
        {
            if($this->protocol == 'udp')
            {
                // 添加读udp事件
                $this->event->add($this->mainSocket,  Events\BaseEvent::EV_READ, array($this, 'recvUdp'));
            }
            else
            {
                // 添加accept事件
                $ret = $this->event->add($this->mainSocket,  Events\BaseEvent::EV_READ, array($this, 'accept'));
            }
        }
        
        // 主体循环,整个子进程会阻塞在这个函数上
        $ret = $this->event->loop();
        $this->notice('worker loop exit unexpected');
        exit(0);
    }
    
    /**
     * 停止服务
     * @return void
     */
    public function stop()
    {
        // 触发该worker进程onStop事件
        $this->onStop();
       
        // 标记这个worker开始停止服务
        if($this->workerStatus != self::STATUS_SHUTDOWN)
        {
            // 停止接收连接
            if($this->mainSocket)
            {
                $this->event->del($this->mainSocket, Events\BaseEvent::EV_READ);
                fclose($this->mainSocket);
            }
            $this->workerStatus = self::STATUS_SHUTDOWN;
        }
        
        // 没有链接要处理了
        if($this->allTaskHasDone())
        {
            exit(0);
        }
    }
    
    /**
     * 设置worker监听的socket
     * @param resource $socket
     * @return void
     */
    public function setListendSocket($socket)
    {
        // 初始化
        $this->mainSocket = $socket;
        // 设置监听socket非阻塞
        stream_set_blocking($this->mainSocket, 0);
        // 获取协议
        $mata_data = stream_get_meta_data($socket);
        $this->protocol = substr($mata_data['stream_type'], 0, 3);
    }
    
    /**
     * 设置worker的事件轮询库的名称
     * @param string 
     * @return void
     */
    public function setEventLoopName($event_loop_name)
    {
        $this->eventLoopName = "\\Man\\Core\\Events\\".$event_loop_name;
        require_once WORKERMAN_ROOT_DIR . 'Core/Events/'.ucfirst(str_replace('WORKERMAN', '', $event_loop_name)).'.php';
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
        
        // 非阻塞
        stream_set_blocking($this->connections[$fd], 0);
        $this->event->add($this->connections[$fd], Events\BaseEvent::EV_READ , array($this, 'dealInputBase'), $fd);
        return $new_connection;
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
         // 可能是惊群效应
         if(false === $data || empty($address))
         {
             $this->statusInfo['thunder_herd']++;
             return false;
         }
         
         // 接受请求数加1
         $this->statusInfo['total_request'] ++;
         
         $this->currentClientAddress = $address;
         if(0 === $this->dealInput($data))
         {
             $this->dealProcess($data);
         }
    }
    
    /**
     * 处理受到的数据
     * @param event_buffer $event_buffer
     * @param int $fd
     * @return void
     */
    public function dealInputBase($connection, $flag, $fd = null)
    {
        $this->currentDealFd = $fd;
        $buffer = stream_socket_recvfrom($connection, $this->recvBuffers[$fd]['remain_len']);
        // 出错了
        if('' == $buffer && '' == ($buffer = fread($connection, $this->recvBuffers[$fd]['remain_len'])))
        {
            if(!feof($connection))
            {
                return;
            }
            
            // 客户端断开链接
            $this->statusInfo['client_close']++;
            // 如果该链接对应的buffer有数据，说明发生错误
            if(!empty($this->sendBuffers[$fd]['buf']))
            {
                $this->statusInfo['send_fail']++;
            }
            
            // 关闭链接
            $this->closeClient($fd);
            if($this->workerStatus == self::STATUS_SHUTDOWN)
            {
                $this->stop();
            }
            return;
        }
        
        $this->recvBuffers[$fd]['buf'] .= $buffer;
        
        $remain_len = $this->dealInput($this->recvBuffers[$fd]['buf']);
        
        if(false === $remain_len)
        {
            // 出错
            $this->statusInfo['packet_err']++;
            $this->sendToClient('packet_err:'.$this->recvBuffers[$fd]['buf']);
            $this->notice("PACKET_ERROR\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".bin2hex($this->recvBuffers[$fd]['buf'])."]\n");
            $this->closeClient($fd);
        }
        // 包接收完毕
        elseif(0 === (int)$remain_len)
        {
            // 执行处理
            try{
                // 接受请求数加1
                $this->statusInfo['total_request'] ++;
                // 业务处理
                $this->dealProcess($this->recvBuffers[$fd]['buf']);
            }
            catch(\Exception $e)
            {
                $this->notice('CODE:' . $e->getCode() . ' MESSAGE:' . $e->getMessage()."\n".$e->getTraceAsString()."\nCLIENT_IP:".$this->getRemoteIp()."\nBUFFER:[".bin2hex($this->recvBuffers[$fd]['buf'])."]\n");
                $this->statusInfo['throw_exception'] ++;
            }
            
            // 是否是长连接
            if($this->isPersistentConnection)
            {
                // 清空缓冲buffer
                $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>$this->prereadLength);
            }
            else
            {
                // 关闭链接
                if(empty($this->sendBuffers[$fd]))
                {
                    $this->closeClient($fd);
                }
            }
        }
        elseif($remain_len > 0) 
        {
            // 判断是否大于接收缓冲区最大值限制
            if(strlen($this->recvBuffers[$fd]['buf']) + $remain_len > $this->maxRecvBufferSize)
            {
                $this->notice('client_ip:'.$this->getRemoteIp().' send a packet which length greater than conf.max_recv_buffer_size:' . $this->maxRecvBufferSize.' .May be an attack so close connection');
                $this->closeClient($fd);
            }
            else
            {
                $this->recvBuffers[$fd]['remain_len'] = $remain_len;
            }
        }
        else
        {
            $this->notice("dealInput return value($remain_len) is illegal illegal");
            $this->closeClient($fd);
        }

        // 检查是否是关闭状态或者是否到达请求上限
        if($this->workerStatus == self::STATUS_SHUTDOWN || $this->statusInfo['total_request'] >= $this->maxRequests)
        {
            // 停止服务
            $this->stop();
            // EXIT_WAIT_TIME秒后退出进程
            pcntl_alarm(self::EXIT_WAIT_TIME);
        }
    }
    
    /**
     * 根据fd关闭链接
     * @param int $fd
     * @return void
     */
    protected function closeClient($fd=null)
    {
        if(empty($fd))
        {
            $fd = $this->currentDealFd;
        }
        // udp忽略
        if($this->protocol != 'udp' && isset($this->connections[$fd]))
        {
            $this->event->del($this->connections[$fd], Events\BaseEvent::EV_READ);
            $this->event->del($this->connections[$fd], Events\BaseEvent::EV_WRITE);
            fclose($this->connections[$fd]);
        }
        unset($this->connections[$fd], $this->recvBuffers[$fd], $this->sendBuffers[$fd]);
    }
    
    /**
     * 安装信号处理函数
     * @return void
     */
    protected function installSignal()
    {
        // 闹钟信号
        $this->event->add(SIGALRM, Events\BaseEvent::EV_SIGNAL, array($this, 'signalHandler'));
        // 终止进程信号
        $this->event->add(SIGINT, Events\BaseEvent::EV_SIGNAL, array($this, 'signalHandler'));
        // 平滑重启信号
        $this->event->add(SIGHUP, Events\BaseEvent::EV_SIGNAL, array($this, 'signalHandler'));
        // 报告进程状态
        $this->event->add(SIGUSR1, Events\BaseEvent::EV_SIGNAL, array($this, 'signalHandler'));
        // 报告该进程使用的文件
        $this->event->add(SIGUSR2, Events\BaseEvent::EV_SIGNAL, array($this, 'signalHandler'));
        // 关闭标准输入输出
        $this->event->add(SIGTTOU, Events\BaseEvent::EV_SIGNAL, array($this, 'signalHandler'));
        
        // 设置忽略信号
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }
    
    /**
     * 设置server信号处理函数
     * @param null $null
     * @param int $signal
     */
    public function signalHandler($signal, $null = null, $null = null)
    {
        switch($signal)
        {
            // 时钟处理函数
            case SIGALRM:
                // 停止服务后EXIT_WAIT_TIME秒还没退出则强制退出
                if($this->workerStatus == self::STATUS_SHUTDOWN)
                {
                    exit(0);
                }
                break;
            // 停止该进程
            case SIGINT:
                $this->stop();
                // EXIT_WAIT_TIME秒后退出进程
                pcntl_alarm(self::EXIT_WAIT_TIME);
                break;
            // 平滑重启
            case SIGHUP:
                $this->onReload();
                // 如果配置了no_reload则不重启该进程
                if(\Man\Core\Lib\Config::get($this->workerName.'.no_reload'))
                {
                    return;
                }
                $this->stop();
                // EXIT_WAIT_TIME秒后退出进程
                pcntl_alarm(self::EXIT_WAIT_TIME);
                break;
            // 报告进程状态
            case SIGUSR1:
                $this->writeStatusToQueue();
                break;
            // 报告进程使用的php文件
            case SIGUSR2:
                $this->writeFilesListToQueue();
                break;
            // FileMonitor检测到终端已经关闭，向此进程发送SIGTTOU信号，关闭此进程的标准输入输出
            case SIGTTOU:
                $this->resetFd();
                break;
        }
    }
    
    /**
     * 发送数据到客户端
     * @return bool
     */
    public function sendToClient($buffer_to_send)
    {
        // tcp
        if($this->protocol != 'udp')
        {
            if(!empty($this->sendBuffers[$this->currentDealFd]))
            {
                // 获得将要发送的buffer的长度
                $total_send_buffer_len = strlen($this->sendBuffers[$this->currentDealFd]) + strlen($buffer_to_send);
                // 如果大于最大限制值则丢弃这个包
                if($total_send_buffer_len > $this->maxSendBufferSize)
                {
                    $this->notice('sendToClient fail. The send buffer\'s length of client_ip:'.$this->getRemoteIp().' is '.$total_send_buffer_len.' greater than conf.d.max_send_buffer_size:' . $this->maxSendBufferSize.', so discard the packet');
                    return false;
                }
                // 将数据放入发送缓冲区中，等待发送
                $this->sendBuffers[$this->currentDealFd] .= $buffer_to_send;
                return;
            }
            // 执行发送
            $send_len = @fwrite($this->connections[$this->currentDealFd], $buffer_to_send);
            // 发送完全
            if($send_len === strlen($buffer_to_send))
            {
                return true;
            }
            // 长度大于0
            if($send_len > 0)
            {
                $this->sendBuffers[$this->currentDealFd] = substr($buffer_to_send, $send_len);
            }
            else
            {
                $this->sendBuffers[$this->currentDealFd] = $buffer_to_send;
            }
            
            if(!isset($this->connections[$this->currentDealFd]))
            {
                $debug_str = new \Exception('sendToClient fail. Connections of '. $this->currentDealFd.' does not exist');
                $this->notice((string)$debug_str);
                return false;
            }
            if(feof($this->connections[$this->currentDealFd]))
            {
                return false;
            }
            $this->event->add($this->connections[$this->currentDealFd],  Events\BaseEvent::EV_WRITE, array($this, 'tcpWriteToClient'));
            return null;
        }
        // udp 直接发送，要求数据包不能超过65515
       return strlen($buffer_to_send) == stream_socket_sendto($this->mainSocket, $buffer_to_send, 0, $this->currentClientAddress);
    }
    
    /**
     * 向客户端socket写数据
     * @param int $fd
     * @param string $bin_data
     */
    public function tcpWriteToClient($fd)
    {
        $fd = (int) $fd;
        if(empty($this->connections[$fd]))
        {
            $this->notice("tcpWriteToClient \$this->connections[$fd] is null");
            return false;
        }
        
        $send_len = @fwrite($this->connections[$fd], $this->sendBuffers[$fd]);
        if($send_len === strlen($this->sendBuffers[$fd]))
        {
            $this->event->del($this->connections[$fd], BaseEvent::EV_WRITE);
            if(!$this->isPersistentConnection)
            {
                return $this->closeClient($fd);
            }
            $this->sendBuffers[$fd] = '';
            return;
        }
        
        if($send_len > 0)
        {
            $this->sendBuffers[$fd] = substr($this->sendBuffers[$fd], $send_len);
        }
    }
    
    /**
     * 获取客户端地址
     * @param int $fd 已经链接的socket id
     * @return string ip:port
     */
    public function getRemoteAddress($fd = null)
    {
        if(empty($fd) && $this->protocol !== 'udp')
        {
            if(!isset($this->connections[$this->currentDealFd]))
            {
                return '';
            }
            $fd = $this->currentDealFd;
        }
        if($this->protocol == 'udp')
        {
            $sock_name = $this->currentClientAddress;
        }
        else
        {
            $sock_name = stream_socket_get_name($this->connections[$fd], true);
        }
        return $sock_name;
    }
    
    /**
     * 获取客户端ip
     * @param integer $fd 已经链接的socket id
     * @return string
     */
    public function getRemoteIp($fd = null)
    {
        $ip = '';
        $address= $this->getRemoteAddress($fd);
        if($address)
        {
            list($ip, $port) = explode(':', $address, 2);
        }
        return $ip;
    }
    
    /**
     * 获取客户端ip
     * @param integer $fd 已经链接的socket id
     * @return integer
     */
    public function getRemotePort($fd = null)
    {
        $port = 0;
        $address= $this->getRemoteAddress($fd);
        if($address)
        {
            list($ip, $port) = explode(':', $address, 2);
        }
        return $port;
    }
    
    /**
     * 获取本地ip
     * @return string 
     */
    public function getLocalIp()
    {
        if(!$this->mainSocket && !isset($this->connections[$this->currentDealFd]))
        {
            return '';
        }
        $ip = '';
        $sock_name = '';
        if($this->protocol === 'udp' || !isset($this->connections[$this->currentDealFd]))
        {
            $sock_name = stream_socket_get_name($this->mainSocket, false);
        }
        else
        {
            $sock_name = stream_socket_get_name($this->connections[$this->currentDealFd], false);
        }
        
        if($sock_name)
        {
            $tmp = explode(':', $sock_name);
            $ip = $tmp[0];
        }
        
        return $ip;
    }
    
    /**
     * 将当前worker进程状态写入消息队列
     * @return void
     */
    protected function writeStatusToQueue()
    {
        if(!Master::getQueueId())
        {
            return;
        }
        $error_code = 0;
        @msg_send(Master::getQueueId(), self::MSG_TYPE_STATUS, array_merge($this->statusInfo, array('memory'=>memory_get_usage(true), 'pid'=>posix_getpid(), 'worker_name' => $this->workerName)), true, false, $error_code);
    }
    
    /**
     * 开发环境将当前进程使用的文件写入消息队列,用于FileMonitor监控文件更新
     * @return void
     */
    protected function writeFilesListToQueue()
    {
        if(!Master::getQueueId())
        {
            return;
        }
        $error_code = 0;
        $flip_file_list = array_flip(get_included_files());
        $file_list = array_diff_key($flip_file_list, $this->includeFiles);
        $this->includeFiles = $flip_file_list;
        if($file_list)
        {
            foreach(array_chunk($file_list, 10, true) as $list)
            {
                @msg_send(Master::getQueueId(), self::MSG_TYPE_FILE_MONITOR, array_keys($list), true, false, $error_code);
            }
        }
    }
    
    /**
     * 是否所有任务都已经完成
     * @return bool
     */
    protected function allTaskHasDone()
    {
        // 如果是长链接并且没有要处理的数据则是任务都处理完了
        return $this->noConnections() || ($this->isPersistentConnection && $this->allBufferIsEmpty());
    }
    
    /**
     * 检查是否所有的链接的缓冲区都是空
     * @return bool
     */
    protected function allBufferIsEmpty()
    {
        foreach($this->recvBuffers as $fd => $buf)
        {
            if(!empty($buf['buf']))
            {
                return false;
            }
        }
        return true;
    }
    
    /**
     * 该进程收到的任务是否都已经完成，重启进程时需要判断
     * @return bool
     */
    protected function noConnections()
    {
        return empty($this->connections);
    }
    
    
    /**
     * 该worker进程开始服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onStart()
    {
        return false;
    }
    
    /**
     * 该worker进程停止服务的时候会触发一次，可以在这里做一些全局的事情
     * @return bool
     */
    protected function onStop()
    {
        return false;
    }
    
    /**
     * 该worker进程收到reload信号时触发
     * 以下情况会收到reload信号
     * 1、运行 workermand reload，全部进程都会收到reload信号
     * 2、开启workerman.conf.debug=1，并且磁盘文件有更新，全部进程会收到reload信号
     * 3、telnet远程控制workerman，运行 reload 命令，全部进程会收到reload信号
     * 4、telnet远程控制workerman，运行 kill pid 命令，pid对应进程会收到reload信号
     * 5、当前进程内存占用大于Monitor.conf.max_mem_limit 时当前进程会收到reload信号
     */
    protected function onReload()
    {
        return false;
    }
}
