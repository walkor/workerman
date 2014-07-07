<?php
/**
 * 
 * 日志类
 * 
* @author walkor <workerman.net>
 */
class APLog
{
    /**
     * 添加日志
     * @param string $msg
     * @return void
     */
    public static function add($msg)
    {
        $log_dir = ROOT_DIR. '/Logs/'.date('Y-m-d');
        umask(0);
        // 没有log目录创建log目录
        if(!is_dir($log_dir))
        {
            mkdir($log_dir,  0777, true);
        }
        if(!is_readable($log_dir))
        {
            return false;
        }
        
        $log_file = $log_dir . "/applications.log";
        file_put_contents($log_file, date('Y-m-d H:i:s') . " " . $msg . "\n", FILE_APPEND);
    }
}
