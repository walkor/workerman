<?php
namespace GatewayWorker;

use \Workerman\Worker;
use \Workerman\Connection\AsyncTcpConnection;
use \Workerman\Protocols\GatewayProtocol;
use \Workerman\Lib\Timer;
use \GatewayWorker\Lib\Lock;
use \GatewayWorker\Lib\Store;
use \GatewayWorker\Lib\Context;
use \Event;

/**
 * 
 * BusinessWorker 用于处理Gateway转发来的数据
 * 
 * @author walkor<walkor@workerman.net>
 *
 */
class BusinessWorker extends Worker
{
    /**
     * 如果连接gateway通讯端口失败，尝试重试多少次
     * @var int
     */
    const MAX_RETRY_COUNT = 5;
    
    /**
     * 保存与gateway的连接connection对象
     * @var array
     */
    public $gatewayConnections = array();
    
    /**
     * 连接失败gateway内部通讯地址
     * @var array
     */
    protected $_badGatewayAddress = array();
    
    /**
     * 保存用户设置的worker启动回调
     * @var callback
     */
    protected $_onWorkerStart = null;
    
    /**
     * 构造函数
     * @param string $socket_name
     * @param array $context_option
     */
    public function __construct($socket_name = '', $context_option = array())
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
        $this->_onWorkerStart = $this->onWorkerStart;
        $this->onWorkerStart = array($this, 'onWorkerStart');
        parent::run();
    }
    
    /**
     * 当进程启动时一些初始化工作
     * @return void
     */
    protected function onWorkerStart()
    {
        Timer::add(1, array($this, 'checkGatewayConnections'));
        $this->checkGatewayConnections();
        \GatewayWorker\Lib\Gateway::setBusinessWorker($this);
        if($this->_onWorkerStart)
        {
            call_user_func($this->_onWorkerStart, $this);
        }
    }
    
    /**
     * 当gateway转发来数据时
     * @param TcpConnection $connection
     * @param mixed $data
     */
    public function onGatewayMessage($connection, $data)
    {
        // 上下文数据
        Context::$client_ip = $data['client_ip'];
        Context::$client_port = $data['client_port'];
        Context::$local_ip = $data['local_ip'];
        Context::$local_port = $data['local_port'];
        Context::$client_id = $data['client_id'];
        // $_SERVER变量
        $_SERVER = array(
                'REMOTE_ADDR' => Context::$client_ip,
                'REMOTE_PORT' => Context::$client_port,
                'GATEWAY_ADDR' => Context::$local_ip,
                'GATEWAY_PORT'  => Context::$local_port,
                'GATEWAY_CLIENT_ID' => Context::$client_id,
        );
        // 尝试解析session
        if($data['ext_data'] != '')
        {
            $_SESSION = Context::sessionDecode($data['ext_data']);
        }
        else
        {
            $_SESSION = null;
        }
        // 备份一次$data['ext_data']，请求处理完毕后判断session是否和备份相等，不相等就更新session
        $session_str_copy = $data['ext_data'];
        $cmd = $data['cmd'];
    
        // 尝试执行Event::onConnection、Event::onMessage、Event::onClose
        try{
            switch($cmd)
            {
                case GatewayProtocol::CMD_ON_CONNECTION:
                    Event::onConnect(Context::$client_id);
                    break;
                case GatewayProtocol::CMD_ON_MESSAGE:
                    Event::onMessage(Context::$client_id, $data['body']);
                    break;
                case GatewayProtocol::CMD_ON_CLOSE:
                    Event::onClose(Context::$client_id);
                    break;
            }
        }
        catch(\Exception $e)
        {
            $msg = 'client_id:'.Context::$client_id."\tclient_ip:".Context::$client_ip."\n".$e->__toString();
            $this->log($msg);
        }
    
        // 判断session是否被更改
        $session_str_now = $_SESSION !== null ? Context::sessionEncode($_SESSION) : '';
        if($session_str_copy != $session_str_now)
        {
            \GatewayWorker\Lib\Gateway::updateSocketSession(Context::$client_id, $session_str_now);
        }
    
        Context::clear();
    }
    
    /**
     * 当与Gateway的连接断开时触发
     * @param TcpConnection $connection
     * @return  void
     */
    public function onClose($connection)
    {
        unset($this->gatewayConnections[$connection->remoteAddress]);
    }

    /**
     * 检查gateway的通信端口是否都已经连
     * 如果有未连接的端口，则尝试连接
     * @return void
     */
    public function checkGatewayConnections()
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::instance('gateway')->get($key);
        if(empty($addresses_list))
        {
            return;
        }
        foreach($addresses_list as $addr)
        {
            if(!isset($this->gatewayConnections[$addr]))
            {
                $gateway_connection = new AsyncTcpConnection("GatewayProtocol://$addr");
                $gateway_connection->remoteAddress = $addr;
                $gateway_connection->onConnect = array($this, 'onConnectGateway');
                $gateway_connection->onMessage = array($this, 'onGatewayMessage');
                $gateway_connection->onClose = array($this, 'onClose');
                $gateway_connection->onError = array($this, 'onError');
            }
        }
    }
    
    /**
     * 当连接上gateway的通讯端口时触发
     * 将连接connection对象保存起来
     * @param TcpConnection $connection
     * @return void
     */
    public function onConnectGateway($connection)
    {
        $this->gatewayConnections[$connection->remoteAddress] = $connection;
        unset($this->_badGatewayAddress[$connection->remoteAddress]);
    }
    
    /**
     * 当与gateway的连接出现错误时触发
     * @param TcpConnection $connection
     * @param int $error_no
     * @param string $error_msg
     */
    public function onError($connection, $error_no, $error_msg)
    {
         $this->tryToDeleteGatewayAddress($connection->remoteAddress, $error_msg);
    }
    
    /**
     * 从存储中删除删除连不上的gateway通讯端口
     * @param string $addr
     * @param string $errstr
     */
    public function tryToDeleteGatewayAddress($addr, $errstr)
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        if(!isset($this->_badGatewayAddress[$addr]))
        {
            $this->_badGatewayAddress[$addr] = 0;
        }
        // 删除连不上的端口
        if($this->_badGatewayAddress[$addr]++ > self::MAX_RETRY_COUNT)
        {
            Lock::get();
            $addresses_list = Store::instance('gateway')->get($key);
            unset($addresses_list[$addr]);
            Store::instance('gateway')->set($key, $addresses_list);
            Lock::release();
            $this->log("tcp://$addr ".$errstr." del $addr from store", false);
        }
    }
}