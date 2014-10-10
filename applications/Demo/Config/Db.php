<?php 
namespace Config;

/**
 * mysql配置
 * @author walkor
 */
class Db
{
    /**
     * 数据库的一个实例配置，则使用时像下面这样使用
     * $user_array = Db::instance('one_demo')->select('name,age')->from('user')->where('age>12')->query();
     * 等价于
     * $user_array = Db::instance('one_demo')->query('SELECT `name`,`age` FROM `one_demo` WHERE `age`>12');
     * @var array
     */
    public static $one_demo = array(
        'host'    => '127.0.0.1',
        'port'    => 3306,
        'user'    => 'mysql_user',
        'password' => 'mysql_password',
        'dbname'  => 'db_name',
        'charset'    => 'utf8',
    );
}