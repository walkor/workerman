Benchmark 
=========

将workerman/conf/conf.d/Benchmark.conf 中的 start_workers设置为cpu核数的8倍

方法一：ab -n 100000 -c200 127.0.0.1:56789/  

方法二：使用workerman自带的benchmark软件，只支持64位linux系统  
1: ./benchmark -n10000 -h1 -c400 -p56789 127.0.0.1    // 命令含义是400并发线程，连接127.0.0.1:56789端口发送一个hello\n扥带服务端返回一个hello\n后断开连接，这样运行10000次  
2：./benchmark -n1 -h10000 -c1000 -p56789 127.0.0.1    // 命令含义是1000并发线程，连接127.0.0.1:56789端口并连续发送10000个hello\n  