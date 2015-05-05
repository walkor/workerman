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
namespace GatewayWorker\Lib\StoreDriver;

/**
 * 这里用php数组文件来存储数据，
 * 为了获取高性能需要用类似memcache的存储
 */

class File
{
    // 为了避免频繁读取磁盘，增加了缓存机制
    protected $dataCache = array();
    // 上次缓存时间
    protected $lastCacheTime = 0;
    // 打开文件的句柄
    protected $dataFileHandle = null;
    
    /**
     * 构造函数
     * @param 配置名 $config_name
     */
    public function __construct($config_name)
    {
        if(!is_dir(\Config\Store::$storePath) && !@mkdir(\Config\Store::$storePath, 0777, true))
        {
            // 可能目录已经被其它进程创建
            clearstatcache();
            if(!is_dir(\Config\Store::$storePath))
            {
                // 避免狂刷日志
                sleep(1);
                throw new \Exception('cant not mkdir('.\Config\Store::$storePath.')');
            }
        }
        $this->dataFileHandle = fopen(__FILE__, 'r');
        if(!$this->dataFileHandle)
        {
            throw new \Exception("can not fopen dataFileHandle");
        }
    }
    
    /**
     * 设置
     * @param string $key
     * @param mixed $value
     * @param int $ttl
     * @return number
     */
    public function set($key, $value, $ttl = 0)
    {
        return file_put_contents(\Config\Store::$storePath.'/'.$key, serialize($value), LOCK_EX);
    }
    
    /**
     * 读取
     * @param string $key
     * @param bool $use_cache
     * @return Ambigous <NULL, multitype:>
     */
    public function get($key, $use_cache = true)
    {
        $ret = @file_get_contents(\Config\Store::$storePath.'/'.$key);
        return $ret ? unserialize($ret) : null;
    }
   
    /**
     * 删除
     * @param string $key
     * @return number
     */
    public function delete($key)
    {
        return @unlink(\Config\Store::$storePath.'/'.$key);
    }
    
    /**
     * 自增
     * @param string $key
     * @return boolean|multitype:
     */
    public function increment($key)
    {
        flock($this->dataFileHandle, LOCK_EX);
        $val = $this->get($key);
        $val = !$val ? 1 : ++$val;
        file_put_contents(\Config\Store::$storePath.'/'.$key, serialize($val));
        flock($this->dataFileHandle, LOCK_UN);
        return $val;
    }
    
    /**
     * 清零销毁存储数据
     */
    public function destroy()
    {
        
    }
    
}
