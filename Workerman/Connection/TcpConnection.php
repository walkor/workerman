<?php
namespace Workerman\Connection;

use Workerman\Events\Libevent;
use Workerman\Events\Select;
use Workerman\Events\EventInterface;
use Workerman\Worker;
use \Exception;

/**
 * connection 
 * @author walkor<walkor@workerman.net>
 */
class TcpConnection extends ConnectionInterface
{
    /**
     * when recv data from client ,how much bytes to read
     * @var unknown_type
     */
    const READ_BUFFER_SIZE = 8192;

    /**
     * connection status connecting
     * @var int
     */
    const STATUS_CONNECTING = 1;
    
    /**
     * connection status establish
     * @var int
     */
    const STATUS_ESTABLISH = 2;

    /**
     * connection status closing
     * @var int
     */
    const STATUS_CLOSING = 4;
    
    /**
     * connection status closed
     * @var int
     */
    const STATUS_CLOSED = 8;
    
    /**
     * when receive data, onMessage will be run 
     * @var callback
     */
    public $onMessage = null;
    
    /**
     * when connection close, onClose will be run
     * @var callback
     */
    public $onClose = null;
    
    /**
     * when some thing wrong ,onError will be run
     * @var callback
     */
    public $onError = null;
    
    /**
     * protocol
     * @var string
     */
    public $protocol = '';
    
    /**
     * max send buffer size (Bytes)
     * @var int
     */
    public static $maxSendBufferSize = 1048576;
    
    /**
     * max package size (Bytes)
     * @var int
     */
    public static $maxPackageSize = 10485760;
    
    /**
     * the socket
     * @var resource
     */
    protected $_socket = null;

    /**
     * the buffer to send
     * @var string
     */
    protected $_sendBuffer = '';
    
    /**
     * the buffer read from socket
     * @var string
     */
    protected $_recvBuffer = '';
    
    /**
     * current package length
     * @var int
     */
    protected $_currentPackageLength = 0;

    /**
     * connection status
     * @var int
     */
    protected $_status = self::STATUS_ESTABLISH;
    
    /**
     * remote ip
     * @var string
     */
    protected $_remoteIp = '';
    
    /**
     * remote port
     * @var int
     */
    protected $_remotePort = 0;
    
    /**
     * remote address
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * create a connection
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
     * send buffer to client
     * @param string $send_buffer
     * @param bool $raw
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        if($this->_status == self::STATUS_CLOSED)
        {
            return false;
        }
        if(false === $raw && $this->protocol)
        {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
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
                        call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'client closed');
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
            // check send buffer size
            if(self::$maxSendBufferSize <= strlen($this->_sendBuffer) + strlen($send_buffer))
            {
                self::$statistics['send_fail']++;
                if($this->onError)
                {
                    call_user_func($this->onError, $this, WORKERMAN_SEND_FAIL, 'send buffer full');
                }
                return false;
            }
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
