<?php
namespace Workerman\Events;

class Select implements EventInterface
{
    /**
     * all events
     * @var array
     */
    public $_allEvents = array();
    
    /**
     * all signal events
     * @var array
     */
    public $_signalEvents = array();
    
    /**
     * read fds
     * @var array
     */
    protected $_readFds = array();
    
    /**
     * write fds
     * @var array
     */
    protected $_writeFds = array();
    
    /**
     * construct
     * @return void
     */
    public function __construct()
    {
        $this->channel = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, STREAM_IPPROTO_IP);
        if($this->channel)
        {
            stream_set_blocking($this->channel[0], 0);
            $this->_readFds[0] = $this->channel[0];
        }
    }
    
    /**
     * add
     * @see Events\EventInterface::add()
     */
    public function add($fd, $flag, $func)
    {
        // key
        $fd_key = (int)$fd;
        switch ($flag)
        {
            case self::EV_READ:
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_readFds[$fd_key] = $fd;
                break;
            case self::EV_WRITE:
                $this->_allEvents[$fd_key][$flag] = array($func, $fd);
                $this->_writeFds[$fd_key] = $fd;
                break;
            case self::EV_SIGNAL:
                $this->_signalEvents[$fd_key][$flag] = array($func, $fd);
                pcntl_signal($fd, array($this, 'signalHandler'));
                break;
        }
        
        return true;
    }
    
    /**
     * signal handler
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        call_user_func_array($this->_signalEvents[$signal][self::EV_SIGNAL][0], array($signal));
    }
    
    /**
     * del
     * @see Events\EventInterface::del()
     */
    public function del($fd ,$flag)
    {
        $fd_key = (int)$fd;
        switch ($flag)
        {
            case self::EV_READ:
                unset($this->_allEvents[$fd_key][$flag], $this->_readFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            case self::EV_WRITE:
                unset($this->_allEvents[$fd_key][$flag], $this->_writeFds[$fd_key]);
                if(empty($this->_allEvents[$fd_key]))
                {
                    unset($this->_allEvents[$fd_key]);
                }
                break;
            case self::EV_SIGNAL:
                unset($this->_signalEvents[$fd_key]);
                pcntl_signal($fd, SIG_IGN);
                break;
        }
        return true;
    }
    /**
     * main loop
     * @see Events\EventInterface::loop()
     */
    public function loop()
    {
        $e = null;
        while (1)
        {
            // calls signal handlers for pending signals
            pcntl_signal_dispatch();
            // 
            $read = $this->_readFds;
            $write = $this->_writeFds;
            // waits for $read and $write to change status
            if(!@stream_select($read, $write, $e, 60))
            {
                // maybe interrupt by sianals, so calls signal handlers for pending signals
                pcntl_signal_dispatch();
                continue;
            }
            
            // read
            if($read)
            {
                foreach($read as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_READ]))
                    {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_READ][0], array($this->_allEvents[$fd_key][self::EV_READ][1]));
                    }
                }
            }
            
            // write
            if($write)
            {
                foreach($write as $fd)
                {
                    $fd_key = (int) $fd;
                    if(isset($this->_allEvents[$fd_key][self::EV_WRITE]))
                    {
                        call_user_func_array($this->_allEvents[$fd_key][self::EV_WRITE][0], array($this->_allEvents[$fd_key][self::EV_WRITE][1]));
                    }
                }
            }
        }
    }
}
