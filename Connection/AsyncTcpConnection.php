<?php
/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link http://www.workerman.net/
 * @license http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Connection;

use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * 异步tcp连接类 
 */
class AsyncTcpConnection extends TcpConnection
{
    
    /**
     * 当连接成功时，如果设置了连接成功回调，则执行
     * @var callback
     */
    public $onConnect = null;
    
    /**
     * 连接状态 连接中
     * @var int
     */
    protected $_status = self::STATUS_CONNECTING;
    
    /**
     * 构造函数，创建连接
     * @param resource $socket
     * @param EventInterface $event
     */
    public function __construct($remote_address)
    {
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
        $this->_remoteAddress = substr($address, 2);
        $this->id = self::$_idRecorder++;
        // 统计数据
        self::$statistics['connection_count']++;
        $this->maxSendBufferSize = self::$defaultMaxSendBufferSize;
    }
    
    public function connect()
    {
        // 创建异步连接
        $this->_socket = stream_socket_client("tcp://{$this->_remoteAddress}", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        // 如果失败尝试触发失败回调（如果有回调的话）
        if(!$this->_socket)
        {
            $this->_status = self::STATUS_CLOSED;
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
            $this->_status = self::STATUS_CLOSED;
            // 关闭socket
            @fclose($this->_socket);
            // 连接未建立成功
            $this->emitError(WORKERMAN_CONNECT_FAIL, 'connect fail');
        }
    }
}
