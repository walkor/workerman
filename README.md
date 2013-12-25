workerman
=========

workerman 是一个高性能的PHP Rpc服务框架，开发者可以在这个框架下开发各种网络应用。
workerman 具有以下特性
 * 多进程
 * 支持TCP/UDP
 * 支持各种应用层协议
 * 使用libevent事件轮询库，支持高并发
 * 支持文件更新检测及自动加载
 * 支持服务平滑重启
 * 支持telnet远程控制及监控
 * 支持异常监控及告警
 * 支持长连接
 * 支持以指定用户运行worker进程

所需环境
========

workerman需要PHP版本不低于5.3，只需要安装PHP的Cli即可，无需安装PHP-FPM、nginx、apache
workerman不能运行在Window平台

安装
=========

以ubuntu为例
安装PHP Cli
`sudo apt-get install php5-cli`
强烈建议安装libevent扩展，以便支持更高的并发量
`sudo pecl install libevent`
建议安装proctitle扩展(php5.5及以上版本原生支持，无需安装)，以便方便查看进程信息
`sudo pecl install proctitle`


启动停止
=========

以ubuntu为例

启动
sudo ./bin/workermand start

重启启动
sudo ./bin/workermand restart

平滑重启/重新加载配置
sudo ./bin/workermand reload

查看服务状态
sudo ./bin/workermand status

停止
sudo ./bin/workermand stop

Rpc应用客户端使用方法
=========

同步调用：

```php
<?php
include_once 'yourdir/RpcClient.php';

$address_array = array(
          'tcp://127.0.0.1:2015',
          'tcp://127.0.0.1:2015'
          );
// 配置服务端列表
RpcClient::config($address_array);

$uid = 567;

// User对应applications/Rpc/Services/User.php 中的User类
$user_client = RpcClient::instance('User');

// getInfoByUid对应User类中的getInfoByUid方法
$ret_sync = $user_client->getInfoByUid($uid);

```

异步调用：
RpcClient支持异步远程调用

```php
<?php
 // 服务端列表
    $address_array = array(
            'tcp://127.0.0.1:2015',
            'tcp://127.0.0.1:2015'
            );
    // 配置服务端列表
    RpcClient::config($address_array);
    
    $uid = 567;
    $user_client = RpcClient::instance('User');
   
    // 异步调用User::getInfoByUid方法
    $user_client->asend_getInfoByUid($uid);
    // 异步调用User::getEmail方法
    $user_client->asend_getEmail($uid);

     这里是其它的业务代码
     ....................
     ....................
     
    // 需要数据的时候异步接收数据
    $ret_async1 = $user_client->arecv_getEmail($uid);
    $ret_async2 = $user_client->arecv_getInfoByUid($uid);
    
     这里是其他业务逻辑

