基于TCP的一个聊天的Demo，该架构适用于绝大部分即时通讯应用，如PC\手机app IM、游戏后台、企业通讯软件、与硬件通讯等
=========

注意：强烈建议生产环境包括压测环境使用memcache，配置方法如下：
========
 * 安装memcahced服务，例如 ubuntu 运行sudo apt-get install memcached  
 * 启动memcached ，例如 ubuntu 运行 memcached -m 256 -p 22322 -u memcache -l 127.0.0.1 -d  
 * 安装memcache扩展，例如 ubuntu 运行 sudo apt-get install php5-memcached  
 * 设置 applications/XXX/Config/Store.php 中的 public static $driver = self::DRIVER_MC；public static $gateway = array('127.0.0.1:22322');   
 * 重启workerman  

### Demo测试方法 
  * 运行 telnet ip 8480
  * 首先输入昵称 回车
  * 后面直接打字回车是向所有人发消息
  * $uid:xxxxxx 是向$uid用户发送消息  

可以开多个telnet窗口，窗口间可以实时聊天

目录结构
========

<pre>
.
├── Bootstrap  // 进程入口目录，分为gateway进程和BusinessWorker进程。gateway进程负责接收用户连接，转发用户请求给BusinessWorker进程，接收BusinessWorker进程的结果转发给用户
│   │
│   ├── BusinessWorker.php // 业务进程，接收Gateway进程的转发来的用户请求并处理，如果有需要将结果发给其它用户则通过Gateway进程转发
│   │
│   └── Gateway.php  // gateway进程，负责客户端连接，转发用户请求给BusinessWorker进程处理，并接收BusinessWorker进程的处理结果转发给用户
│ 
├── Lib  // 通用的库
│   │
│   ├── StoreDriver          // 存储驱动目录
│   │
│   ├── Gateway.php          // gateway进程的接口，BusinessWorker进程通过此文件的接口向gateway进程发送数据
│   │
│   ├── Store.php            // 存储类，默认使用文件存储，配置在Config/Store.php，生产环境请使用memcache作为存储
│   │
│   ├── Db.php                //  Db类，用于管理数据库连接
│   │
│   ├── DbConnection.php       // 数据库连接类，只支持pdo
│   │
│   ├── Autoloader.php       // 自动加载逻辑
│   │
│   ├── Context.php          // Gateway与Worker通信时的上下文信息，开发者不要改动其中的内容
│   │
│   └── StatisticClient.php  // 统计模块客户端
│ 
├── Config  // 配置
│   │
│   ├── Db.php          // 数据库配置
│   │
│   └── Store.php            // 存储配置，分为两种，一种是文件存储（无法支持分布式，开发测试用），另外一种是memcache存储，支持分布式
│ 
├── Protocols // 应用层协议相关
│   │
│   ├── GatewayProtocol.php  // gateway与BusinessWorker通讯的协议，开发者无需关注
│   │
│   ├── TextProtocol.php     // 简单的文本协议（applications/Demo中用到）
│   │
│   ├── JsonProtocol.php     // json协议（还没有例子使用）
│   │
│   └── WebSocket.php        // WebSocket协议（workerman-chat使用）
│ 
│ 
└── Event.php // 聊天所有的业务代码在此目录，群聊、私聊等
</pre>

为什么使用gateway worker模型
===========================

gateway worker模型非常适合长链接应用，例如聊天、游戏后台等。如果是短链接应用，则建议使用上面FileRecevierDemo基础的master slave模型。
###1、gateway只负责网络IO，worker主要负责业务逻辑。各司其职，非常高效。
打个比方，一个餐馆有4工人（进程），他们即负责招呼客人（网络IO），又负责在厨房做菜（业务逻辑）。当客人一下子来很多的时候（很多链接或很多数据），大家有可能都去招待客人了（都处理网络IO），厨房没人做菜（做业务）。当大家都做菜的时候（做业务），又没人招呼客人（接收链接），导致客人（用户）都在等待。但是当我们把工人（进程）分工一下，2个人专门招呼客人（geteway进程），两个人专门做菜（worker进程），这样每个时刻都有有人（进程）招待客人（接收数据），都有人（进程）做菜（处理业务）。当gateway不够用的时候（一般都是够用的）增加gateway，worker忙不过来的时候增加worker进程。这样效率会提升很多。
###2、提高稳定性
gateway进程因为要维持用户链接，这要求gateway进程一定要非常稳定，不然如果gateway进程出问题，则这个进程上的所有用户都会断开链接。让gateway只负责网络IO，不负责业务，就是因为业务频繁变化，可能会有致命的错误（例如调用了一个不存在的函数）导致进程退出，进而导致用户链接断开。而让gateway只负责网络IO，就是要避免这种风险。而worker进程是无状态的（没有保存用户链接等状态信息），即使偶尔出现FatalErr，也只会影响当前的这次请求，而不会对整个服务造成大的影响。
###3、热更新
由于gateway进程没有业务逻辑，所以geteway进程极少有代码更新。而worker进程由于负责业务逻辑，会有经常性的代码更新。这样看来我们每次代码更新，只要重启worker进程就可以实现运行新的业务代码。实际上也是这样，当更新程序逻辑时，我们只需要重启worker进程就可以了，这样就不会导致更新代码的时候用户链接会断开，达到不影响用户的情况下热更新后台程序。
###4、扩展容易
当worker进程不够用的时候，我们可以水平扩展它，可增加worker的进程数量，甚至可以增加服务器专门运行worker进程，达到水平扩展的目的，以支持更大的用户量。gateway进程也是同样的道理。

数据库类的使用方法
=========

##注意这个数据库类需要mysql_pdo扩展

## 配置
在Config/Db.php中配置数据库信息，如果有多个数据库，可以按照one_demo的配置在Db.php中配置多个实例  
例如下面配置了两个数据库实例

```php
<?php
namespace Config;
class Db
{
    // 数据库实例1
    public static $db1 = array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => 'mysql_user',
        'password' => 'mysql_password',
        'dbname'  => 'db1',
        'charset'    => 'utf8',
    );
  
    // 数据库实例2
    public static $db2 = array array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => 'mysql_user',
        'password' => 'mysql_password',
        'dbname'  => 'db2',
        'charset'    => 'utf8',
    );
}
```
2、使用方法

```php
$db1 = \Lib\Db::instance('db1');  
$db2 = \Lib\Db::instance('db2');  

// 获取所有数据
$db1->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->query();  
//等价于  
$db1->select('ID,Sex')->from('Persons')->where("sex= 'F' ")->query(); 
//等价于  
$db1->query("SELECT ID,Sex FROM `Persons` WHERE sex=‘M’");  


// 获取一行数据
$db1->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->row();  
//等价于  
$db1->select('ID,Sex')->from('Persons')->where("sex= 'F' ")->row(); 
//等价于  
$db1->row("SELECT ID,Sex FROM `Persons` WHERE sex=‘M’");  


// 获取一列数据
$db1->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->column();  
//等价于  
$db1->select('ID,Sex')->from('Persons')->where("sex= 'F' ")->column(); 
//等价于  
$db1->column("SELECT ID,Sex FROM `Persons` WHERE sex=‘M’");  

// 获取单个值
$db1->select('ID,Sex')->from('Persons')->where('sex= :sex')->bindValues(array('sex'=>'M'))->single();  
//等价于  
$db1->select('ID,Sex')->from('Persons')->where("sex= 'F' ")->single(); 
//等价于  
$db1->single("SELECT ID,Sex FROM `Persons` WHERE sex=‘M’");  

// 复杂查询
$db1->select('*')->from('table1')->innerJoin('table2','table1.uid = table2.uid')->where('age > :age')->groupBy(array('aid'))->having('foo="foo"')->orderBy(array('did'))->limit(10)->offset(20)->bindValues(arra
y('age' => 13));
// 等价于
$db1->query(SELECT * FROM `table1` INNER JOIN `table2` ON `table1`.`uid` = `table2`.`uid` WHERE age > 13 GROUP BY aid HAVING foo="foo" ORDER BY did LIMIT 10 OFFSET 20“);

// 插入
$insert_id = $db1->insert('Persons')->cols(array('Firstname'=>'abc', 'Lastname'=>'efg', 'Sex'=>'M', 'Age'=>13))->query();
等价于
$insert_id = $db1->query("INSERT INTO `Persons` ( `Firstname`,`Lastname`,`Sex`,`Age`) VALUES ( 'abc', 'efg', 'M', 13)");

// 更新
$row_count = $db1->update('Persons')->cols(array('sex'=>'O'))->where('ID=1')->bindValue('sex', 'F')->query();
// 等价于
$row_count = $db1->query("UPDATE `Persons` SET `sex` = 'F' WHERE ID=1");

// 删除
$row_count = $db1->delete('Persons')->where('ID=9')->query();
// 等价于
$row_count = $db1->query("DELETE FROM `Persons` WHERE ID=9");

```