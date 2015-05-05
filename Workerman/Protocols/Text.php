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
namespace Workerman\Protocols;
use \Workerman\Connection\TcpConnection;

/**
 * Text协议
 * 以换行为请求结束标记
 * @author walkor <walkor@workerman.net>
 */
class Text
{
    /**
     * 检查包的完整性
     * 如果能够得到包长，则返回包的长度，否则返回0继续等待数据
     * @param string $buffer
     */
    public static function input($buffer ,TcpConnection $connection)
    {
        // 由于没有包头，无法预先知道包长，不能无限制的接收数据，
        // 所以需要判断当前接收的数据是否超过限定值
        if(strlen($buffer)>=TcpConnection::$maxPackageSize)
        {
            $connection->close();
            return 0;
        }
        // 获得换行字符"\n"位置
        $pos = strpos($buffer, "\n");
        // 没有换行符，无法得知包长，返回0继续等待数据
        if($pos === false)
        {
            return 0;
        }
        // 有换行符，返回当前包长，包含换行符
        return $pos+1;
    }
    
    /**
     * 打包，当向客户端发送数据的时候会自动调用
     * @param string $buffer
     * @return string
     */
    public static function encode($buffer)
    {
        // 加上换行
        return $buffer."\n";
    }
    
    /**
     * 解包，当接收到的数据字节数等于input返回的值（大于0的值）自动调用
     * 并传递给onMessage回调函数的$data参数
     * @param string $buffer
     * @return string
     */
    public static function decode($buffer)
    {
        // 去掉换行
        return trim($buffer);
    }
}
