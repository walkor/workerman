<?php
namespace Workerman\Connection;
use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * connection类的接口 
 * @author walkor<walkor@workerman.net>
 */
abstract class  ConnectionInterface
{
    /**
     * status命令的统计数据
     * @var array
     */
    public static $statistics = array(
        'connection_count'=>0,
        'total_request'   => 0, 
        'throw_exception' => 0,
        'send_fail'       => 0,
    );
    
    /**
     * 当收到数据时，如果有设置$onMessage回调，则执行
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * 当连接关闭时，如果设置了$onClose回调，则执行
     * @var callback
     */
    public $onClose = null;
    
    /**
     * 当出现错误时，如果设置了$onError回调，则执行
     * @var callback
     */
    public $onError = null;
    
    /**
     * 发送数据给对端
     * @param string $send_buffer
     * @return void|boolean
     */
    abstract public function send($send_buffer);
    
    /**
     * 获得远端ip
     * @return string
     */
    abstract public function getRemoteIp();
    
    /**
     * 获得远端端口
     * @return int
     */
    abstract public function getRemotePort();

    /**
     * 关闭连接，为了保持接口一致，udp保留了此方法，当是udp时调用此方法无任何作用
     * @void
     */
    abstract public function close($data = null);
}
