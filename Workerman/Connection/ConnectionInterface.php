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
abstract class  ConnectionInterface
{
    /**
     * statistics for status
     * @var array
     */
    public static $statistics = array(
        'total_request'   => 0, 
        'throw_exception' => 0,
        'send_fail'       => 0,
    );
    
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
     * when something wrong ,onError will be run
     * @var callback
     */
    public $onError = null;
    
    /**
     * send buffer to client
     * @param string $send_buffer
     * @return void|boolean
     */
    abstract public function send($send_buffer);
    
    /**
     * get remote ip
     * @return string
     */
    abstract public function getRemoteIp();
    
    /**
     * get remote port
     */
    abstract public function getRemotePort();

    /**
     * close the connection
     * @void
     */
    abstract public function close($data = null);
}
