<?php
/**
 * 
 * 处理具体逻辑
 * 
 * @author walkor <workerman.net>
 * 
 */
define('ROOT_DIR', realpath(__DIR__.'/../'));
require_once ROOT_DIR . '/Protocols/GatewayProtocol.php';
require_once ROOT_DIR . '/Event.php';
require_once ROOT_DIR . '/Lib/APLog.php';

class BusinessWorker extends Man\Core\SocketWorker
{
    /**
     * BusinessWorker 实例
     * @var BusinessWorker
     */
    protected static $instance = null;
    
    /**
     * 与gateway的连接
     * ['ip:port' => conn, 'ip:port' => conn, ...]
     * @var array
     */
    protected static $gatewayConnections = array();
    
    /**
     * 进程启动时初始化
     * @see Man\Core.SocketWorker::onStart()
     */
    protected function onStart()
    {
        // 定时检查与gateway进程的连接
        \Man\Core\Lib\Task::init($this->event);
        \Man\Core\Lib\Task::add(1, array($this, 'checkGatewayConnections'));
        self::$instance = $this;
    }
    
    /**
     * 获取实例
     */
    public static function getInstance()
    {
        return self::$instance;
    }
    
    /**
     * 获取与网关的连接
     */
    public static function getGatewayConnections()
    {
        return self::$gatewayConnections;
    }
    
    /**
     * 检查请求是否完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return GatewayProtocol::input($recv_str); 
    }

    /**
     * 处理请求
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        $pack = new GatewayProtocol($recv_str);
        Context::$client_ip = $pack->header['client_ip'];
        Context::$client_port = $pack->header['client_port'];
        Context::$local_ip = $pack->header['local_ip'];
        Context::$local_port = $pack->header['local_port'];
        Context::$socket_id = $pack->header['socket_id'];
        Context::$uid = $pack->header['uid'];
        switch($pack->header['cmd'])
        {
            case GatewayProtocol::CMD_ON_CONNECTION:
                $ret = call_user_func_array(array('Event', 'onConnect'), array($pack->body));
                break;
            case GatewayProtocol::CMD_ON_MESSAGE:
                $ret = call_user_func_array(array('Event', 'onMessage'), array(Context::$uid, $pack->body));
                break;
            case GatewayProtocol::CMD_ON_CLOSE:
                $ret = call_user_func_array(array('Event', 'onClose'), array(Context::$uid));
                break;
        }
        Context::clear();
        return $ret;
    }
    
    /**
     * 定时检查gateway通信端口
     */
    public function checkGatewayConnections()
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::get($key);
        if(empty($addresses_list))
        {
            return;
        }
       
        foreach($addresses_list as $addr)
        {
            if(!isset(self::$gatewayConnections[$addr]))
            {
                $conn = stream_socket_client("tcp://$addr", $errno, $errstr, 1);
                if(!$conn)
                {
                    $this->notice($errstr);
                    continue;
                }
                self::$gatewayConnections[$addr] = $conn;
                stream_set_blocking(self::$gatewayConnections[$addr], 0);
                
                $fd = (int) self::$gatewayConnections[$addr];
                $this->connections[$fd] = self::$gatewayConnections[$addr];
                $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>$this->prereadLength);
                $this->event->add($this->connections[$fd], \Man\Core\Events\BaseEvent::EV_READ , array($this, 'dealInputBase'), $fd);
            }
        }
    }
    
    /**
     * 发送数据给客户端
     * @see Man\Core.SocketWorker::sendToClient()
     */
    public function sendToClient($buffer, $con = null)
    {
        if($con)
        {
            $this->currentDealFd = (int) $con;
        }
        return parent::sendToClient($buffer);
    }
    
    /**
     * 关闭连接
     * @see Man\Core.SocketWorker::closeClient()
     */
    protected function closeClient($fd)
    {
        foreach(self::$gatewayConnections as $addr => $con)
        {
            $the_fd = (int) $con;
            if($the_fd == $fd)
            {
                unset(self::$gatewayConnections[$addr]);
            }
        }
        parent::closeClient($fd);
    }
    
}


/**
 * 上下文 包含当前用户uid， 内部通信local_ip local_port socket_id ，以及客户端client_ip client_port
 * @author walkor
 *
 */
class Context
{
    public static $series_id;
    public static $local_ip;
    public static $local_port;
    public static $socket_id;
    public static $client_ip;
    public static $client_port;
    public static $uid;
    public static function clear()
    {
        self::$series_id = self::$local_ip = self::$local_port = self::$socket_id = self::$client_ip = self::$client_port = self::$uid = null;
    }
}
