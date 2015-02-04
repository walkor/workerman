<?php
namespace Workerman\Connection;

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * async connection 
 * @author walkor<walkor@workerman.net>
 */
class AsyncTcpConnection extends TcpConnection
{
    /**
     * status
     * @var int
     */
    protected $_status = self::STATUS_CONNECTING;
    
    /**
     * when connect success , onConnect will be run
     * @var callback
     */
    public $onConnect = null;
    
    /**
     * create a connection
     * @param resource $socket
     * @param EventInterface $event
     */
    public function __construct($remote_address)
    {
        list($scheme, $address) = explode(':', $remote_address, 2);
        if($scheme != 'tcp')
        {
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
        $this->_socket = stream_socket_client("tcp:$address", $errno, $errstr, 0, STREAM_CLIENT_ASYNC_CONNECT);
        if(!$this->_socket)
        {
            $this->emitError(WORKERMAN_CONNECT_FAIL, $errstr);
            return;
        }
        
        Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'checkConnection'));
    }
    
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
    
    public function checkConnection($socket)
    {
        Worker::$globalEvent->del($this->_socket, EventInterface::EV_WRITE);
        // php bug ?
        if(!feof($this->_socket) && !feof($this->_socket))
        {
            stream_set_blocking($this->_socket, 0);
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_READ, array($this, 'baseRead'));
            if($this->_sendBuffer)
            {
                Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            }
            $this->_status = self::STATUS_ESTABLISH;
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
            $this->emitError(WORKERMAN_CONNECT_FAIL, 'connect fail, maybe timedout');
        }
    }
    
    /**
     * send buffer to client
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer)
    {
        if($this->protocol)
        {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
        }
        
        if($this->_status === self::STATUS_CONNECTING)
        {
            $this->_sendBuffer .= $send_buffer;
            return null;
        }
        elseif($this->_status == self::STATUS_CLOSED)
        {
            return false;
        }
        
        if($this->_sendBuffer === '')
        {
            $len = @fwrite($this->_socket, $send_buffer);
            if($len === strlen($send_buffer))
            {
                return true;
            }
            
            if($len > 0)
            {
                $this->_sendBuffer = substr($send_buffer, $len);
            }
            else
            {
                if(feof($this->_socket))
                {
                    self::$statistics['send_fail']++;
                    if($this->onError)
                    {
                        call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'client close');
                    }
                    $this->destroy();
                    return false;
                }
                $this->_sendBuffer = $send_buffer;
            }
            
            Worker::$globalEvent->add($this->_socket, EventInterface::EV_WRITE, array($this, 'baseWrite'));
            return null;
        }
        else
        {
            $this->_sendBuffer .= $send_buffer;
        }
    }
    
}
