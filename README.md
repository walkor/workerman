workerman
=========

workerman 是一个高性能的PHP socket服务框架，开发者可以在这个框架下开发各种网络应用,例如Rpc服务、聊天室、游戏等。
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
`sudo ./bin/workermand start`

重启启动  
`sudo ./bin/workermand restart`

平滑重启/重新加载配置  
`sudo ./bin/workermand reload`

查看服务状态  
`sudo ./bin/workermand status`

停止  
`sudo ./bin/workermand stop`


配置
========

 * 配置文件在workerman/conf/目录下  
 * 其中workerman/conf/workerman.conf是workerman的主体配置文件，在里面可以设置运行模式、日志目录、pid文件存储位置等配置  
 * workerman/conf/conf.d/下每个配置文件对应一个网络应用，同时也对应workerman\workers下的一组worker进程。
 * 以Rpc网络服务应用的配置文件workerman/conf/conf.d/RpcWorker.conf为例

```
;Rpc网络服务应用配置
;所用的传输层协议及绑定的ip端口
listen = tcp://0.0.0.0:2015
;长连接还是短连接，Rpc服务这里设置成短连接，每次请求后服务器主动断开
persistent_connection = 0
;启动多少worker进程，这里建议设置成cpu核数的整数倍，例如 CPU数*3
start_workers=12
;接收多少请求后退出该进程，重新启动一个新进程，设置成0代表永不重启
max_requests=1000
;以哪个用户运行该worker进程，建议使用权限较低的用户运行worker进程，例如www-data
user=www-data
;socket有数据可读的时候预读长度，一般设置为应用层协议包头的长度，这里设置成尽可能读取更多的数据
preread_length=84000
```

telnet远程控制及监控
====================

###workerman通过workerman/workers/Monitor.php提供telnet远程控制及监控功能
<pre>
输入
telnet xxx.xxx.xxx.xxx 2001
输入
status
展示workerman状态
status
---------------------------------------GLOBAL STATUS--------------------------------------------
WorkerMan version:2.0.1
start time:2013-12-26 22:12:48   run 0 days 0 hours
load average: 0, 0, 0
1 users          4 workers       15 processes
worker_name    exit_status     exit_count
FileMonitor    0                0
Monitor        0                0
RpcWorker      0                0
WorkerManAdmin 0                0
---------------------------------------PROCESS STATUS-------------------------------------------
pid     memory      listening        timestamp  worker_name    total_request packet_err thunder_herd client_close send_fail throw_exception suc/total
24139   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24140   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24141   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24142   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24143   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24144   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24145   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24146   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24147   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24148   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24149   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24150   1.25M   tcp://0.0.0.0:2015   1388067168 RpcWorker      0              0          0            0            0         0               100%
24151   1.25M   tcp://0.0.0.0:3000   1388067168 WorkerManAdmin 0              0          0            0            0         0               100%
</pre>

###telnet支持的命令
 * status
 * stop
 * reload
 * kill pid
 * quit
