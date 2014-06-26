<?php 
/**
 * Benchmark 
 * 方法1：ab -n 100000 -c200 127.0.0.1:56789/
 * 方法2：使用workerman自带的benchmark软件，只支持64位linux系统
 * ①：./benchmark -n10000 -h1 -c400 -p56789 127.0.0.1    // 命令含义是400并发线程，连接127.0.0.1:56789端口发送一个hello\n扥带服务端返回一个hello\n后断开连接，这样运行10000次
 * ②：./benchmark -n1 -h10000 -c1000 -p56789 127.0.0.1    // 命令含义是1000并发线程，连接127.0.0.1:56789端口连续发送10000个hello\n
 * @author walkor <workerman.net>
 */
class Benchmark extends Man\Core\SocketWorker
{
    /**
     * @see Worker::dealInput()
     */
    public function dealInput($buffer)
    {
       // 由于请求包都小于一个MTU，不会有分包，这里直接返回0
       return 0;
    }
    
    /**
     * 处理业务
     * @see Worker::dealProcess()
     */
    public function dealProcess($buffer)
    {
        // 是HTTP协议
        if('G' == $buffer[0] )
        {
            $this->sendToClient("HTTP/1.1 200 OK\r\nContent-Length: 5\r\n\r\nhello");
            return $this->closeClient($this->currentDealFd);
        }
        // 是benchmark脚本
        return $this->sendToClient($buffer);
    }
    
} 
