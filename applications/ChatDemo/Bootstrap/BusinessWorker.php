<?php
/**
 * 
 * 处理具体逻辑
 * 
 * @author walkor <workerman.net>
 * 
 */
require_once __DIR__ . '/../Lib/Autoloader.php';

use \Protocols\GatewayProtocol;
use \Lib\Store;
use \Lib\Gateway;
use \Lib\StatisticClient;
use \Lib\Context;

class BusinessWorker extends Man\Core\SocketWorker
{
    /**
     * 与gateway的连接
     * ['ip:port' => conn, 'ip:port' => conn, ...]
     * @var array
     */
    protected $gatewayConnections = array();
    
    /**
     * 连不上的gateway地址
     * ['ip:port' => retry_count, 'ip:port' => retry_count, ...]
     * @var array
     */
    protected $badGatewayAddress = array();
    
    /**
     * 连接gateway失败重试次数
     * @var int
     */
    const MAX_RETRY_COUNT = 5;
    
    /**
     * 命令字映射 统计用到
     * @var array
     */
    protected static $interfaceMap = array(
        GatewayProtocol::CMD_ON_GATEWAY_CONNECTION => 'CMD_ON_GATEWAY_CONNECTION',
        GatewayProtocol::CMD_ON_CONNECTION         => 'CMD_ON_CONNECTION',
        GatewayProtocol::CMD_ON_MESSAGE            => 'CMD_ON_MESSAGE',
        GatewayProtocol::CMD_ON_CLOSE              => 'CMD_ON_CLOSE',
    );
    
    /**
     * 进程启动时初始化
     * @see Man\Core.SocketWorker::onStart()
     */
    protected function onStart()
    {
        // 定时检查与gateway进程的连接
        \Man\Core\Lib\Task::init($this->event);
        \Man\Core\Lib\Task::add(1, array($this, 'checkGatewayConnections'));
        $this->checkGatewayConnections();
        Gateway::setBusinessWorker($this);
    }
    
    /**
     * 获取与gateway的连接
     */
    public function getGatewayConnections()
    {
        return $this->gatewayConnections;
    }
    
    /**
     * 检查gateway转发来的用户请求是否完整
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
        $_SERVER = array(
            'REMOTE_ADDR' => Context::$client_ip,
            'REMOTE_PORT' => Context::$client_port,
            'GATEWAY_ADDR' => Context::$local_ip,
            'GATEWAY_PORT'  => Context::$local_port,
            'GATEWAY_SOCKET_ID' => Context::$socket_id,
        );
        if($pack->ext_data != '')
        {
            $_SESSION = Context::sessionDecode($pack->ext_data);
        }
        else
        {
            $_SESSION = null;
        }
        // 备份一次$pack->ext_data，请求处理完毕后判断session是否和备份相等
        $session_str_copy = $pack->ext_data;
        $cmd = $pack->header['cmd'];
        
        StatisticClient::tick();
        $module = __CLASS__;
        $interface = isset(self::$interfaceMap[$cmd]) ? self::$interfaceMap[$cmd] : 'null';
        $success = 1;
        $code = 0;
        $msg = '';
        try{
            switch($cmd)
            {
                case GatewayProtocol::CMD_ON_GATEWAY_CONNECTION:
                    call_user_func_array(array('Event', 'onGatewayConnect'), array());
                    break;
                case GatewayProtocol::CMD_ON_CONNECTION:
                    call_user_func_array(array('Event', 'onConnect'), array($pack->body));
                    break;
                case GatewayProtocol::CMD_ON_MESSAGE:
                    call_user_func_array(array('Event', 'onMessage'), array(Context::$uid, $pack->body));
                    break;
                case GatewayProtocol::CMD_ON_CLOSE:
                    call_user_func_array(array('Event', 'onClose'), array(Context::$uid));
                    break;
            }
        }
        catch(\Exception $e)
        {
            $success = 0;
            $code = $e->getCode() > 0 ? $e->getCode() : 500;
            $msg = 'uid:'.Context::$uid."\tclient_ip:".Context::$client_ip."\n".$e->__toString();
        }
        
        $session_str_now = $_SESSION !== null ? Context::sessionEncode($_SESSION) : '';
        if($session_str_copy != $session_str_now)
        {
            Gateway::updateSocketSession(Context::$socket_id, $session_str_now);
        }
        
        Context::clear();
        StatisticClient::report($module, $interface, $success, $code, $msg);
    }
    
    /**
     * 定时检查gateway通信端口，如果有新的gateway则去建立长连接
     */
    public function checkGatewayConnections()
    {
        $key = 'GLOBAL_GATEWAY_ADDRESS';
        $addresses_list = Store::instance('gateway')->get($key);
        if(empty($addresses_list))
        {
            return;
        }
       
        // 循环遍历，查找未连接的gateway ip 端口
        foreach($addresses_list as $addr)
        {
            if(!isset($this->gatewayConnections[$addr]))
            {
                // 执行连接
                $conn = @stream_socket_client("tcp://$addr", $errno, $errstr, 1);
                if(!$conn)
                {
                    if(!isset($this->badGatewayAddress[$addr]))
                    {
                        $this->badGatewayAddress[$addr] = 0;
                    }
                    // 删除连不上的端口
                    if($this->badGatewayAddress[$addr]++ > self::MAX_RETRY_COUNT)
                    {
                        $addresses_list = Store::instance('gateway')->get($key);
                        unset($addresses_list[$addr]);
                        Store::instance('gateway')->set($key, $addresses_list);
                        $this->notice("tcp://$addr ".$errstr." del $addr from store", false);
                    }
                    continue;
                }
                unset($this->badGatewayAddress[$addr]);
                $this->gatewayConnections[$addr] = $conn;
                stream_set_blocking($this->gatewayConnections[$addr], 0);
                
                // 初始化一些值
                $fd = (int) $this->gatewayConnections[$addr];
                $this->connections[$fd] = $this->gatewayConnections[$addr];
                $this->recvBuffers[$fd] = array('buf'=>'', 'remain_len'=>$this->prereadLength);
                // 添加数据可读事件
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
        // 清理$this->gatewayConnections对应项
        foreach($this->gatewayConnections as $addr => $con)
        {
            $the_fd = (int) $con;
            if($the_fd == $fd)
            {
                unset($this->gatewayConnections[$addr]);
            }
        }
        parent::closeClient($fd);
    }
    
}
