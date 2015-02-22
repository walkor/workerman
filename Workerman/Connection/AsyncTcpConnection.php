<?php
namespace Workerman\Connection;

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * 异步tcp连接类 
 * @author walkor<walkor@workerman.net>
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * 连接状态 连接中
     * @var int
     */
    protected $_status = self::STATUS_CONNECTING;
    
    /**
     * 当连接成功时，如果设置了连接成功回调，则执行
     * @var callback
     */
    public $onConnect = null;
    
    /**
     * 构造函数，创建连接
     * @param resource $socket
     * @param EventInterface $event
     */
    public function __construct($remote_address)
    {
        // 获得协议及远程地址
        list($scheme, $address) = explode(':', $remote_address, 2);
        if($scheme != 'tcp')
        {
            // 判断协议类是否存在
            $scheme = ucfirst($scheme);
            $this->protocol = '\\Protocols\\'.$scheme;
            if(!class_exists($this->protocol))
            {
                $this->protocol = '\\Workerman\\Protocols\\' . $scheme;
                if(!class_exists($this->protocol))
                {
                    throw new Exception("class \\Protocols\\$scheme not exist");
                }
            }
        }
        // 创建异步连接
        $this->_socket = stream_socket_client("tcp:$address", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        // 如果失败尝试触发失败回调（如果有回调的话）
        if(!$this->_socket)
        {
            $this->emitError(WORKERMAN_CONNECT_FAIL, $errstr);
            return;
        }
        // 监听连接可写事件（可写意味着连接已经建立或者已经出错）
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
    }
    
    /**
     * 尝试触发失败回调
     * @param int $code
     * @param string $msg
     * @return void
     */
    protected function emitError($code, $msg)
    {
        if($this->onError)
        {
            try{
                call_user_func($this->onError, $this, $code, $msg);
            }
            catch(Exception $e)
            {
                echo $e;
            }
        }
    }
    
    /**
     * 检查连接状态，连接成功还是失败
     * @param resource $socket
     * @return void
     */
    public function checkConnection($socket)
    {
        // 删除连接可写监听
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
        // 需要判断两次连接是否已经断开
        if(!feof($this->_socket) && !feof($this->_socket))
        {
            // 设置非阻塞
            stream_set_blocking($this->_socket, 0);
            // 监听可读事件
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            // 如果发送缓冲区有数据则执行发送
            if($this->_sendBuffer)
            {
                Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            // 标记状态为连接已经建立
            $this->_status = self::STATUS_ESTABLISH;
            // 为status 命令统计数据
            ConnectionInterface::$statistics['connection_count']++;
            // 如果有设置onConnect回调，则执行
            if($this->onConnect)
            {
                try 
                {
                    call_user_func($this->onConnect, $this);
                }
                catch(Exception $e)
                {
                    self::$statistics['throw_exception']++;
                    echo $e;
                }
            }
        }
        else
        {
            // 连接未建立成功
            $this->emitError(WORKERMAN_CONNECT_FAIL, 'connect fail');
        }
    }
    
    /**
     * 发送数据给对方
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer)
    {
        // 如果有设置协议，则用协议编码
        if($this->protocol)
        {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
        }
        
        // 如果当前状态是连接中，则把数据放入发送缓冲区
        if($this->_status === self::STATUS_CONNECTING)
        {
            $this->_sendBuffer .= $send_buffer;
            return null;
        }
        // 如果当前连接是关闭中，则返回false
        elseif($this->_status == self::STATUS_CLOSED)
        {
            return false;
        }
        // 如果发送缓冲区无数据，则尝试直接发送
        if($this->_sendBuffer === '')
        {
            // 直接发送，得到已经发送（写入socket写缓冲区）的字节数
            $len = @fwrite($this->_socket, $send_buffer);
            // 如果已经发送出去的长度刚好为要发送数据的长度，则说明数据发送成功
            if($len === strlen($send_buffer))
            {
                return true;
            }
            // 数据只发送了一部分，则将剩余的数据放入发送缓冲区
            if($len > 0)
            {
                $this->_sendBuffer = substr($send_buffer, $len);
            }
            // 发送出现异常
            else
            {
                // 如果连接关闭
                if(feof($this->_socket))
                {
                    // status命令 统计发送失败次数
                    self::$statistics['send_fail']++;
                    // 如果有设置失败回到，则执行
                    if($this->onError)
                    {
                        call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'client close');
                    }
                    // 销毁本实例
                    $this->destroy();
                    return false;
                }
                // 连接未关闭，则将整个数据放入发送缓冲区
                $this->_sendBuffer = $send_buffer;
            }
            // 监听可写事件，将发送缓冲区的数据发送给对方（写到socket发送缓冲区）
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            return null;
        }
        // 发送缓冲区有数据，则直接将数据放入发送缓冲区
        else
        {
            $this->_sendBuffer .= $send_buffer;
        }
    }
}
