workerman
=========

workerman 是一个高性能的PHP socket服务框架，开发者可以在这个框架下开发各种网络应用,例如移动通讯、手游服务端、网络游戏服务器、聊天室服务器、硬件通讯服务器、智能家居等
workerman 具有以下特性
 * 支持HHVM，将PHP性能提高9倍左右
 * 多进程/多线程(多线程版本)
 * 支持TCP/UDP
 * 支持多端口监听
 * 支持各种应用层协议
 * 标准输入输出重定向
 * 守护进程化
 * 使用libevent事件轮询库，支持高并发
 * 支持文件更新检测及自动加载
 * 支持服务平滑重启
 * 支持telnet远程控制及监控
 * 支持异常监控及告警
 * 支持长连接
 * 支持以指定用户运行worker进程
 * 支持请求数上限配置
 * 服务端心跳支持
 * 支持多服务器部署

 [更多请访问www.workerman.net](http://www.workerman.net)  
 [文档doc.workerman.net](http://doc.workerman.net)  

applications/Demo测试方法
===============
  * 运行 telnet ip 8480
  * 首先输入昵称 回车
  * 后面直接打字回车是向所有人发消息
  * uid:聊天内容 是向uid用户发送消息  

可以开多个telnet窗口，窗口间可以实时聊天

关于applications/Demo
=================
 * [applications/Demo](https://github.com/walkor/workerman/tree/master/applications/Demo) 的业务逻辑全部在[applications/Demo/Event.php](https://github.com/walkor/workerman/blob/master/applications/Demo/Event.php) 中
 * 开发者看懂[applications/Demo/Event.php](https://github.com/walkor/workerman/blob/master/applications/Demo/Event.php) 的代码基本上就知道如何开发了
 * [applications/Demo](https://github.com/walkor/workerman/tree/master/applications/Demo) 使用的是及其简单的文本协议，适合非浏览器类的应用参考。例如移动通讯、手游、硬件通讯、智能家居等
 * 如果是浏览器类的即时应用，可以参考[workerman-chat](http://www.workerman.net/workerman-chat) ，使用的是websocket协议（支持各种浏览器），同样只需要看懂[applications/Chat/Event.php](https://github.com/walkor/workerman-chat/blob/master/applications/Chat/Event.php) 即可
 * 长连接类的应用 [applications/Demo](https://github.com/walkor/workerman/tree/master/applications/Demo)  [workerman-chat](http://www.workerman.net/workerman-chat)  [workerman-todpole](https://github.com/walkor/workerman-todpole) [workerman-flappy-bird](https://github.com/walkor/workerman-flappy-bird) 它们的代码结构完全相同，只是applications/XXX/Event.php实现不同

一些demo连接
==================
[小蝌蚪聊天室workerman-todpole](http://kedou.workerman.net)  
[多人在线flappybird](http://flap.workerman.net)  
[workerman-chat聊天室](http://chat.workerman.net)  
[json-rpc](http://www.workerman.net/workerman-jsonrpc)  
[thrift-rpc](http://www.workerman.net/workerman-thrift)  
[统计监控系统](http://www.workerman.net/workerman-statistics)  


短链开发demo
============

```php
<?php
class EchoService extends \Man\Core\SocketWorker
{
   /**
    * 判断telnet客户端发来的数据是否接收完整
    */
   public function dealInput($recv_buffer)
   {
        // 根据协议,判断最后一个字符是不是回车 \n
        if($recv_buffer[strlen($recv_buffer)-1] != "\n")
        {
            // 不是回车返回1告诉workerman我还需要再读一个字符
            return 1;
        }
        // 告诉workerman数据完整了
        return 0;
   }

   /**
    * 处理业务逻辑，这里只是按照telnet客户端发来的命令返回对应的数据
    */
   public function dealProcess($recv_buffer)
   {
        // 判断telnet客户端发来的是什么
        $cmd = trim($recv_buffer);
        switch($cmd)
        {
            // 获得服务器的日期
            case 'date':
            return $this->sendToClient(date('Y-m-d H:i:s')."\n");
            // 获得服务器的负载
            case 'load':
            return $this->sendToClient(var_export(sys_getloadavg(), true)."\n");
            case 'quit':
            return $this->closeClient($this->currentDealFd);
            default:
            return $this->sendToClient("unknown cmd\n");
        }
   }
}
```

长链接应用开发demo
=============

``php
// 协议为 文本+回车
class Event
{
    /**
     * 网关有消息时，区分请求边界，分包
     */
    public static function onGatewayMessage($buffer)
    {
        // 判断最后一个字符是否是回车("\n")
        if($buffer[strlen($buffer)-1] === "\n")
        {
            return 0;
        }

        // 说明还有请求数据没收到，但是由于不知道还有多少数据没收到，所以只能返回1，因为有可能下一个字符就是回车（"\n"）
        return 1;
    }

   /**
    * 有消息时触发该方法
    * @param int $client_id 发消息的client_id
    * @param string $message 消息
    * @return void
    */
   public static function onMessage($client_id, $message)
   {
        // 获得客户端来发的消息具体内容，trim去掉了请求末尾的回车
        $message_data = trim($message);

        // ****如果没有$_SESSION['not_first_time']说明是第一次发消息****
        if(empty($_SESSION['not_first_time']))
        {
            $_SESSION['not_first_time'] = true;

            // 广播所有用户，xxx come
            GateWay::sendToAll("client_id:$client_id come\n");
        }

        // 向所有人转发消息
        return GateWay::sendToAll("client[$client_id] said :" . $message));
   }

   /**
    * 当用户断开连接时触发的方法
    * @param integer $client_id 断开连接的用户id
    * @return void
    */
   public static function onClose($client_id)
   {
       // 广播 xxx logout
       GateWay::sendToAll("client[$client_id] logout\n");
   }
}
```

 
性能测试
=============

###测试环境：
系统：debian 6.0 64位  
内存：64G  
cpu：Intel(R) Xeon(R) CPU E5-2420 0 @ 1.90GHz （2颗物理cpu，6核心，2线程）
Workerman：开启200个Benchark进程
压测脚本：benchmark
业务：发送并返回hello字符串

###普通PHP（版本5.3.10）压测
    短链接（每次请求完成后关闭链接，下次请求建立新的链接）:
        条件： 压测脚本开500个并发线程模拟500个并发用户，每个线程链接Workerman 10W次，每次链接发送1个请求
        结果： 吞吐量：1.9W/S ， cpu利用率：32% 

    长链接（每次请求后不关闭链接，下次请求继续复用这个链接）:
        条件： 压测脚本开2000个并发线程模拟2000个并发用户，每个线程链接Workerman 1次，每个链接发送10W请求
        结果： 吞吐量：36.7W/S ， cpu利用率：69% 

    内存：每个进程内存稳定在6444K，无内存泄漏


###HHVM环境压测
    短链接（每次请求完成后关闭链接，下次请求建立新的链接）:
        条件： 压测脚本开1000个并发线程模拟1000个并发用户，每个线程链接Workerman 10W次，每次链接发送1个请求
        结果： 吞吐量：3.5W/S ， cpu利用率：35% 

    长链接（每次请求后不关闭链接，下次请求继续复用这个链接）:
        条件： 压测脚本开6000个并发线程模拟6000个并发用户，每个线程链接Workerman 1次，每个链接发送10W请求
        结果： 吞吐量：45W/S ， cpu利用率：67% 

    内存：HHVM环境每个进程内存稳定在46M，无内存泄漏


