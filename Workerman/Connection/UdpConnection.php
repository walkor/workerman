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
class UdpConnection extends ConnectionInterface
{
    /**
     * protocol
     * @var string
     */
    public $protocol = '';
    
    /**
     * the socket
     * @var resource
     */
    protected $_socket = null;
    
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
     * @param string $remote_address
     */
    public function __construct($socket, $remote_address)
    {
        $this->_socket = $socket;
        $this->_remoteAddress = $remote_address;
    }
    
    /**
     * send buffer to client
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer)
    {
        return strlen($send_buffer) === stream_socket_sendto($this->_socket, $send_buffer, 0, $this->_remoteAddress);
    }
    
    /**
     * get remote ip
     * @return string
     */
    public function getRemoteIp()
    {
        if(!$this->_remoteIp)
        {
            list($this->_remoteIp, $this->_remotePort) = explode(':', $this->_remoteAddress, 2);
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
            list($this->_remoteIp, $this->_remotePort) = explode(':', $this->_remoteAddress, 2);
        }
        return $this->_remotePort;
    }

    /**
     * close the connection
     * @void
     */
    public function close($data = null)
    {
        if($data !== null)
        {
            $this->send($data);
        }
        return true;
    }
}
