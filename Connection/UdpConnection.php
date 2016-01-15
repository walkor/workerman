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

/**
 * udp连接类（udp实际上是无连接的，这里是为了保持与TCP接口一致） 
 */
class UdpConnection extends ConnectionInterface
{
    /**
     * 应用层协议
     * 值类似于 Workerman\\Protocols\\Http
     * @var string
     */
    public $protocol = '';
    
    /**
     * udp socket 资源
     * @var resource
     */
    protected $_socket = null;
    
    /**
     * 对端 ip
     * @var string
     */
    protected $_remoteIp = '';
    
    /**
     * 对端 端口
     * @var int
     */
    protected $_remotePort = 0;
    
    /**
     * 对端 地址
     * 值类似于 192.168.10.100:3698
     * @var string
     */
    protected $_remoteAddress = '';

    /**
     * 构造函数
     * @param resource $socket
     * @param string $remote_address
     */
    public function __construct($socket, $remote_address)
    {
        $this->_socket = $socket;
        $this->_remoteAddress = $remote_address;
    }
    
    /**
     * 发送数据给对端
     * @param string $send_buffer
     * @return void|boolean
     */
    public function send($send_buffer, $raw = false)
    {
        // 如果没有设置以原始数据发送，并且有设置协议则按照协议编码
        if(false === $raw && $this->protocol)
        {
            $parser = $this->protocol;
            $send_buffer = $parser::encode($send_buffer, $this);
            if($send_buffer === '')
            {
                return null;
            }
        }
        return strlen($send_buffer) === stream_socket_sendto($this->_socket, $send_buffer, 0, $this->_remoteAddress);
    }
    
    /**
     * 获得对端 ip
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
     * 获得对端端口
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
     * 关闭连接（此处为了保持与TCP接口一致，提供了close方法）
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
