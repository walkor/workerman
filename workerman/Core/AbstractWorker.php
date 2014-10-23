<?php 
namespace Man\Core;
require_once WORKERMAN_ROOT_DIR . 'Core/Events/Select.php';

/**
 * 抽象Worker类
 * 必须实现start方法
* @author walkor <walkor@workerman.net>
*/
abstract class AbstractWorker
{
    /**
     * worker状态 运行中
     * @var integer
     */
    const STATUS_RUNNING = 2;
    
    /**
     * worker状态 停止中
     * @var integer
     */
    const STATUS_SHUTDOWN = 4;
    
    /**
     * 消息队列状态消息类型
     * @var integer
     */
    const MSG_TYPE_STATUS = 1;
    
    /**
     * 消息队列文件监控消息类型
     * @var integer
     */
    const MSG_TYPE_FILE_MONITOR = 2;
    
    /**
     * worker名称
     * @var string
     */
    protected $workerName = __CLASS__;
    
    
    /**
     * worker监听端口的Socket
     * @var resource
     */
    protected $mainSocket = null;
    
    /**
     * 当前worker的服务状态
     * @var integer
     */
    protected $workerStatus = self::STATUS_RUNNING;
    
    /**
     * 让该worker实例开始服务
     *
     * @return void
     */
    abstract public function start();
    
    /**
     * 构造函数，主要是初始化信号处理函数
     * @return void
     */
    public function __construct($worker_name = null)
    {
        $this->workerName = $worker_name ? $worker_name : get_class($this);
        $this->installSignal();
        $this->addShutdownHook();
    }
    
    /**
     * 设置监听的socket
     * @param resource $socket
     * @return void
     */
    public function setListendSocket($socket)
    {
        // 初始化
        $this->mainSocket = $socket;
        stream_set_blocking($this->mainSocket, 0);
    }
    
    /**
     * 安装信号处理函数
     * @return void
     */
    protected function installSignal()
    {
        // 报告进程状态
        pcntl_signal(SIGINT, array($this, 'signalHandler'));
        pcntl_signal(SIGHUP, array($this, 'signalHandler'));
        pcntl_signal(SIGTTOU, array($this, 'signalHandler'));
        // 设置忽略信号
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_signal(SIGUSR1, SIG_IGN);
        pcntl_signal(SIGUSR2, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGCHLD, SIG_IGN);
    }
    
    /**
     * 设置server信号处理函数
     * @param integer $signal
     * @return void
     */
    public function signalHandler($signal)
    {
        switch($signal)
        {
                // 停止该进程
            case SIGINT:
                // 平滑重启
            case SIGHUP:
                $this->workerStatus = self::STATUS_SHUTDOWN;
                break;
                // 终端关闭
            case SIGTTOU:
                $this->resetFd();
                break;
        }
    }
    
    /**
     * 判断该进程是否收到退出信号,收到信号后要马上退出，否则稍后会被住进成强行杀死
     * @return boolean
     */
    public function hasShutDown()
    {
        pcntl_signal_dispatch();
        return $this->workerStatus == self::STATUS_SHUTDOWN;
    }
    
    /**
     * 获取主进程统计信息
     * @return array
     */
    protected function getMasterStatus()
    {
        if(!Master::getShmId())
        {
            return array();
        }
        return @shm_get_var(Master::getShmId(), Master::STATUS_VAR_ID);
    }
    
    /**
     * 获取worker与pid的映射关系
     * @return array ['worker_name1'=>[pid1=>pid1,pid2=>pid2,..], 'worker_name2'=>[pid3,..], ...]
     */
    protected function getWorkerPidMap()
    {
        $status = $this->getMasterStatus();
        if(empty($status))
        {
            return array();
        }
        return $status['pid_map'];
    }
    
    /**
     * 获取pid与worker的映射关系
     * @return array  ['pid1'=>'worker_name1','pid2'=>'worker_name2', ...]
     */
    protected function getPidWorkerMap()
    {
        $pid_worker_map = array();
        if($worker_pid_map = $this->getWorkerPidMap())
        {
            foreach($worker_pid_map as $worker_name=>$pid_array)
            {
                foreach($pid_array as $pid)
                {
                    $pid_worker_map[$pid] = $worker_name;
                }
            }
        }
        return $pid_worker_map;
    }
    
    
    /**
     * 进程关闭时进行错误检查
     * @return void
     */
    protected function addShutdownHook()
    {
        register_shutdown_function(array($this, 'checkErrors'));
    }
    
    /**
     * 检查错误
     * @return void
     */
    public function checkErrors()
    {
        if(self::STATUS_SHUTDOWN != $this->workerStatus) 
        {
            $error_msg = "WORKER EXIT UNEXPECTED  ";
            if($errors = error_get_last())
            {
                $error_msg .= $this->getErrorType($errors['type']) . " {$errors['message']} in {$errors['file']} on line {$errors['line']}";
            }
            $this->notice($error_msg);
        }
    }
    
    /**
     * 获取错误类型对应的意义
     * @param integer $type
     * @return string
     */
    public function getErrorType($type)
    {
        switch($type)
        {
            case E_ERROR: // 1 //
                return 'E_ERROR';
            case E_WARNING: // 2 //
                return 'E_WARNING';
            case E_PARSE: // 4 //
                return 'E_PARSE';
            case E_NOTICE: // 8 //
                return 'E_NOTICE';
            case E_CORE_ERROR: // 16 //
                return 'E_CORE_ERROR';
            case E_CORE_WARNING: // 32 //
                return 'E_CORE_WARNING';
            case E_COMPILE_ERROR: // 64 //
                return 'E_COMPILE_ERROR';
            case E_CORE_WARNING: // 128 //
                return 'E_COMPILE_WARNING';
            case E_USER_ERROR: // 256 //
                return 'E_USER_ERROR';
            case E_USER_WARNING: // 512 //
                return 'E_USER_WARNING';
            case E_USER_NOTICE: // 1024 //
                return 'E_USER_NOTICE';
            case E_STRICT: // 2048 //
                return 'E_STRICT';
            case E_RECOVERABLE_ERROR: // 4096 //
                return 'E_RECOVERABLE_ERROR';
            case E_DEPRECATED: // 8192 //
                return 'E_DEPRECATED';
            case E_USER_DEPRECATED: // 16384 //
                return 'E_USER_DEPRECATED';
        }
        return "";
    } 

    /**
     * 记录日志
     * @param sring $str
     * @return void
     */
    protected function notice($str, $display = true)
    {
        $str = 'Worker['.get_class($this).']:'.$str;
        Lib\Log::add($str);
        if($display && Lib\Config::get('workerman.debug') == 1)
        {
            echo $str."\n";
        }
    }
    
    /**
     * 关闭标准输入输出
     * @return void
     */
    protected function resetFd()
    {
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        // 将标准输出重定向到/dev/null
        $STDOUT = fopen('/dev/null',"rw+");
        $STDERR = fopen('/dev/null',"rw+");
    }
    
}



