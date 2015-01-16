<?php
namespace Lib\StoreDriver;

/**
 * 
 * 这里用php数组文件来存储数据，
 * 为了获取高性能需要用类似memcache的存储
 * @author walkor <walkor@workerman.net>
 * 
 */

class File
{
    // 为了避免频繁读取磁盘，增加了缓存机制
    protected $dataCache = array();
    // 上次缓存时间
    protected $lastCacheTime = 0;
    // 保存数据的文件
    protected $dataFile = '';
    // 打开文件的句柄
    protected $dataFileHandle = null;
    
    /**
     * 构造函数
     * @param 配置名 $config_name
     */
    public function __construct($config_name)
    {
        $this->dataFile = \Config\Store::$storePath . "/$config_name.store.cache.php";
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
        if(!is_file($this->dataFile))
        {
            touch($this->dataFile);
        }
        $this->dataFileHandle = fopen(__FILE__, 'r');
        if(!$this->dataFileHandle)
        {
            throw new \Exception("can not fopen($this->dataFile, 'r')");
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
        flock($this->dataFileHandle, LOCK_EX);
        $this->readDataFromDisk();
        $this->dataCache[$key] = $value;
        $ret = $this->writeToDisk();
        flock($this->dataFileHandle, LOCK_UN);
        return $ret;
    }
    
    /**
     * 读取
     * @param string $key
     * @param bool $use_cache
     * @return Ambigous <NULL, multitype:>
     */
    public function get($key, $use_cache = true)
    {
        flock($this->dataFileHandle, LOCK_EX);
        $this->readDataFromDisk();
        flock($this->dataFileHandle, LOCK_UN);
        return isset($this->dataCache[$key]) ? $this->dataCache[$key] : null;
    }
   
    /**
     * 删除
     * @param string $key
     * @return number
     */
    public function delete($key)
    {
        flock($this->dataFileHandle, LOCK_EX);
        $this->readDataFromDisk();
        unset($this->dataCache[$key]);
        $ret = $this->writeToDisk();
        flock($this->dataFileHandle, LOCK_UN);
        return $ret;
    }
    
    /**
     * 自增
     * @param string $key
     * @return boolean|multitype:
     */
    public function increment($key)
    {
        flock($this->dataFileHandle, LOCK_EX);
        $this->readDataFromDisk();
        if(!isset($this->dataCache[$key]))
        {
            flock($this->dataFileHandle, LOCK_UN);
            return false;
        }
        $this->dataCache[$key] ++;
        $this->writeToDisk();
        flock($this->dataFileHandle, LOCK_UN);
        return $this->dataCache[$key];
    }
    
    /**
     * 清零销毁存储数据
     */
    public function destroy()
    {
        @unlink($this->dataFile);
    }
    
    /**
     * 写入磁盘
     * @return number
     */
    protected function writeToDisk()
    {
        return file_put_contents($this->dataFile, "<?php \n return " . var_export($this->dataCache, true). ';');
    }
    
    /**
     * 从磁盘读
     */
    protected function readDataFromDisk()
    {
        $cache = include $this->dataFile;
        if(is_array($cache))
        {
            $this->dataCache = $cache;
        }
        $this->lastCacheTime = time();
    }
}
