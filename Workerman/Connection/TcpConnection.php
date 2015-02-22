<?php
namespace Workerman\Connection;

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * Tcp连接类 
 * @author walkor<walkor@workerman.net>
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * 当数据可读时，从socket缓冲区读取多少字节数据
     * @var int
     */
    const READ_BUFFER_SIZE = 8192;

    /**
     * 连接状态 连接中
     * @var int
     */
    const STATUS_CONNECTING = 1;
    
    /**
     * 连接状态 已经建立连接
     * @var int
     */
    const STATUS_ESTABLISH = 2;

    /**
     * 连接状态 连接关闭中，标识调用了close方法，但是发送缓冲区中任然有数据
     * 等待发送缓冲区的数据发送完毕（写入到socket写缓冲区）后执行关闭
     * @var int
     */
    const STATUS_CLOSING = 4;
    
    /**
     * 连接状态 已经关闭
     * @var int
     */
    const STATUS_CLOSED = 8;
    
    /**
     * 当对端发来数据时，如果设置了$onMessage回调，则执行
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * 当连接关闭时，如果设置了$onClose回调，则执行
     * @var callback
     */
    public $onClose = null;
    
    /**
     * 当出现错误是，如果设置了$onError回调，则执行
     * @var callback
     */
    public $onError = null;
    
    /**
     * 使用的应用层协议，是协议类的名称
     * 值类似于 Workerman\\Protocols\\Http
     * @var string
     */
    public $protocol = '';
    
    /**
     * 发送缓冲区大小，当发送缓冲区满时，会尝试触发onError回调（如果有设置的话）
     * 如果没设置onError回调，发送缓冲区满，则后续发送的数据将被丢弃，
     * 直到发送缓冲区有空的位置
     * 注意 此值可以动态设置
     * 例如 Workerman\Connection\TcpConnection::$maxSendBufferSize=1024000;
     * @var int
     */
    public static $maxSendBufferSize = 1048576;
    
    /**
     * 能接受的最大数据包，为了防止恶意攻击，当数据包的大小大于此值时执行断开
     * 注意 此值可以动态设置
     * 例如 Workerman\Connection\TcpConnection::$maxPackageSize=1024000;
     * @var int
     */
    public static $maxPackageSize = 10485760;
    
    /**
     * 实际的socket资源
     * @var resource
     */
    protected $_socket = null;

    /**
     * 发送缓冲区
     * @var string
     */
    protected $_sendBuffer = '';
    
    /**
     * 接收缓冲区
     * @var string
     */
    protected $_recvBuffer = '';
    
    /**
     * 当前正在处理的数据包的包长（此值是协议的intput方法的返回值）
     * @var int
     */
    protected $_currentPackageLength = 0;

    /**
     * 当前的连接状态
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISH;
    
    /**
     * 对端ip
     * @var string
     */
    protected $_remoteIp = '';
    
    /**
     * 对端端口
     * @var int
     */
    protected $_remotePort = 0;
    
    /**
     * 对端的地址 ip+port
     * 值类似于 192.168.1.100:3698
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 构造函数
     * @param resource $socket
     * @param EventInterface $event
     */
    public function __construct($socket)
    {
        $this->_socket = $socket;
        stream_set_blocking($this->_socket, 0);
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
    }
    
    /**
     * 发送数据给对端
     * @param string $send_buffer
     * @param bool $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        // 如果连接已经关闭，则返回false
        if($this->_status == self::STATUS_CLOSED)
        {
            return false;
        }
        // 如果没有设置以原始数据发送，并且有设置协议。只协议编码
        if(false === $raw && $this->protocol)
        {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
        }
        // 如果发送缓冲区为空，尝试直接发送
        if($this->_sendBuffer === '')
        {
            // 直接发送
            $len = @fwrite($this->_socket, $send_buffer);
            // 所有数据都发送完毕
            if($len === strlen($send_buffer))
            {
                return true;
            }
            // 只有部分数据发送成功
            if($len > 0)
            {
                // 未发送成功部分放入发送缓冲区
                $this->_sendBuffer = substr($send_buffer, $len);
            }
            else
            {
                // 如果连接断开
                if(feof($this->_socket))
                {
                    // status统计发送失败次数
                    self::$statistics['send_fail']++;
                    // 如果有设置失败回调，则执行
                    if($this->onError)
                    {
                        call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'client closed');
                    }
                    // 销毁连接
                    $this->destroy();
                    return false;
                }
                // 连接未断开，发送失败，则把所有数据放入发送缓冲区
                $this->_sendBuffer = $send_buffer;
            }
            // 监听对端可写事件
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            return null;
        }
        else
        {
            // 检查发送缓冲区是否已满
            if(self::$maxSendBufferSize <= strlen($this->_sendBuffer) + strlen($send_buffer))
            {
                // 为status命令统计发送失败次数
                self::$statistics['send_fail']++;
                // 如果有设置失败回调，则执行
                if($this->onError)
                {
                    call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'send buffer full');
                }
                return false;
            }
            // 将数据放入放缓冲区
            $this->_sendBuffer .= $send_buffer;
        }
    }
    
    /**
     * get remote ip
     * @return string
     */
    public function getRemoteIp()
    {
        if(!$this->_remoteIp)
        {
            $this->_remoteAddress = stream_socket_get_name($this->_socket, true);
            if($this->_remoteAddress)
            {
                list($this->_remoteIp, $this->_remotePort) = explode(':', $this->_remoteAddress, 2);
                $this->_remotePort = (int)$this->_remotePort;
            }
        }
        return $this->_remoteIp;
    }
    
    /**
     * get remote port
     */
    public function getRemotePort()
    {
        if(!$this->_remotePort)
        {
            $this->_remoteAddress = stream_socket_get_name($this->_socket, true);
            if($this->_remoteAddress)
            {
                list($this->_remoteIp, $this->_remotePort) = explode(':', $this->_remoteAddress, 2);
                $this->_remotePort = (int)$this->_remotePort;
            }
        }
        return $this->_remotePort;
    }

    /**
     * when socket is readable 
     * @param resource $socket
     * @return void
     */
    public function baseRead($socket)
    {
       while($buffer = fread($socket, self::READ_BUFFER_SIZE))
       {
          $this->_recvBuffer .= $buffer; 
       }
       
       if($this->_recvBuffer)
       {
           if(!$this->onMessage)
           {
               return ;
           }
           
           // protocol has been set
           if($this->protocol)
           {
               $parser = $this->protocol;
               while($this->_recvBuffer)
               {
                   // already know current package length 
                   if($this->_currentPackageLength)
                   {
                       // we need more buffer
                       if($this->_currentPackageLength > strlen($this->_recvBuffer))
                       {
                           break;
                       }
                   }
                   else
                   {
                       // try to get the current package length
                       $this->_currentPackageLength = $parser::input($this->_recvBuffer, $this);
                       // need more buffer
                       if($this->_currentPackageLength === 0)
                       {
                           break;
                       }
                       elseif($this->_currentPackageLength > 0 && $this->_currentPackageLength <= self::$maxPackageSize)
                       {
                           // need more buffer
                           if($this->_currentPackageLength > strlen($this->_recvBuffer))
                           {
                               break;
                           }
                       }
                       // error package
                       else
                       {
                           $this->close('error package. package_length='.var_export($this->_currentPackageLength, true));
                       }
                   }
                   
                   // recvived the  whole data 
                   self::$statistics['total_request']++;
                   $one_request_buffer = substr($this->_recvBuffer, 0, $this->_currentPackageLength);
                   $this->_recvBuffer = substr($this->_recvBuffer, $this->_currentPackageLength);
                   $this->_currentPackageLength = 0;
                   try
                   {
                       call_user_func($this->onMessage, $this, $parser::decode($one_request_buffer, $this));
                   }
                   catch(Exception $e)
                   {
                       self::$statistics['throw_exception']++;
                       echo $e;
                   }
               }
               if($this->_status !== self::STATUS_CLOSED && feof($socket))
               {
                   $this->destroy();
                   return;
               }
               return;
           }
           self::$statistics['total_request']++;
           // protocol not set
           try 
           {
               call_user_func($this->onMessage, $this, $this->_recvBuffer);
           }
           catch(Exception $e)
           {
               self::$statistics['throw_exception']++;
               echo $e;
           }
           $this->_recvBuffer = '';
           if($this->_status !== self::STATUS_CLOSED && feof($socket))
           {
               $this->destroy();
               return;
           }
       }
       else if(feof($socket))
       {
           $this->destroy();
           return;
       }
    }

    /**
     * when socket is writeable
     * @return void
     */
    public function baseWrite()
    {
        $len = @fwrite($this->_socket, $this->_sendBuffer);
        if($len === strlen($this->_sendBuffer))
        {
            Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
            $this->_sendBuffer = '';
            if($this->_status == self::STATUS_CLOSING)
            {
                $this->destroy();
            }
            return true;
        }
        if($len > 0)
        {
           $this->_sendBuffer = substr($this->_sendBuffer, $len);
        }
        else
        {
           if(feof($this->_socket))
           {
               self::$statistics['send_fail']++;
               $this->destroy();
           }
        }
    }
    
    /**
     * consume recvBuffer
     * @param int $length
     */
    public function consumeRecvBuffer($length)
    {
        $this->_recvBuffer = substr($this->_recvBuffer, $length);
    }

    /**
     * close the connection
     * @param mixed $data
     * @void
     */
    public function close($data = null)
    {
        if($data !== null)
        {
            $this->send($data);
        }
        $this->_status = self::STATUS_CLOSING;
        if($this->_sendBuffer === '')
        {
           $this->destroy();
        }
    }
    
    /**
     * get socket
     * @return resource
     */
    public function getSocket()
    {
        return $this->_socket;
    }

    /**
     * destroy the connection
     * @void
     */
    protected function destroy()
    {
       self::$statistics['connection_count']--;
       if($this->onClose)
       {
           try
           {
               call_user_func($this->onClose, $this);
           }
           catch (Exception $e)
           {
               self::$statistics['throw_exception']++;
               echo $e;
           }
       }
       Worker::$globalEvent->del($this->_socket, EventInterface::EV_READ);
       Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
       @fclose($this->_socket);
       $this->_status = self::STATUS_CLOSED;
    }
}
