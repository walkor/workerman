<?php 
require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
/**
 * 
 * 1、提供telnet接口，远程控制服务器查看服务状态
 * 2、监控主进程是否挂掉
 * 3、监控worker进程是否频繁退出
 * 4、定时清理log文件
 * 5、定时监控worker内存泄漏
 * 
* @author walkor <walkor@workerman.net>
 */
class Monitor extends Man\Core\SocketWorker
{
    /**
     * 一天有多少秒
     * @var integer
     */
    const SECONDS_ONE_DAY = 86400;
    
    /**
     * 多长时间清理一次磁盘日志文件
     * @var integer
     */
    const CLEAR_LOGS_TIME_LONG = 86400;
    
    /**
     * 多长时间检测一次master进程是否存在
     * @var integer
     */
    const CHECK_MASTER_PROCESS_TIME_LONG = 5;
    
    /**
     * 多长时间检查一次主进程状态
     * @var integer
     */
    const CHECK_MASTER_STATUS_TIME_LONG = 60;
    
    /**
     * 多长时间检查一次内存占用情况
     * @var integer
     */
    const CHECK_WORKER_MEM_TIME_LONG = 60;
    
    /**
     * 清理多少天前的日志文件
     * @var integer
     */
    const CLEAR_BEFORE_DAYS = 14;
    
    /**
     * 告警发送时间间隔
     * @var integer
     */
    const WARING_SEND_TIME_LONG = 300;
    
    /**
     * 大量worker进程退出
     * @var integer
     */
    const WARNING_TOO_MANY_WORKERS_EXIT = 1;
    
    /**
     * 主进程死掉
     * @var integer
     */
    const WARNING_MASTER_DEAD = 8;
    
    /**
     * worker占用内存限制 单位KB
     * @var integer
     */
    const DEFAULT_MEM_LIMIT = 83886;
    
    /**
     * 上次获得的主进程信息 
     * [worker_name=>[0=>xx, 9=>xxx], worker_name=>[0=>xx]]
     * @var array
     */
    protected $lastMasterStatus = null;
    
    /**
     * 管理员认证信息
     * @var array
     */
    protected $adminAuth = array();
    
    /**
     * 最长的workerName
     * @var integer
     */
    protected $maxWorkerNameLength = 10;
    
    /**
     * 最长的Address
     * @var integer
     */
    protected $maxAddressLength = 20;
    
    /**
     * 上次发送告警的时间
     * @var array
     */
    protected static $lastWarningTimeMap = array(
        self::WARNING_TOO_MANY_WORKERS_EXIT => 0,
        self::WARNING_MASTER_DEAD => 0,
    );
    
    /**
     * 该进程开始服务
     * @see SocketWorker::start()
     */
    public function start()
    {
        // 安装信号
        $this->installSignal();
        
        // 初始化任务
        \Man\Core\Lib\Task::init($this->event);
        \Man\Core\Lib\Task::add(self::CLEAR_LOGS_TIME_LONG, array($this, 'clearLogs'), array(WORKERMAN_LOG_DIR));
        \Man\Core\Lib\Task::add(self::CHECK_MASTER_PROCESS_TIME_LONG, array($this, 'checkMasterProcess'));
        \Man\Core\Lib\Task::add(self::CHECK_MASTER_STATUS_TIME_LONG, array($this, 'checkMasterStatus'));
        \Man\Core\Lib\Task::add(self::CHECK_MASTER_STATUS_TIME_LONG, array($this, 'checkMemUsage'));
        
        // 添加accept事件
        $this->event->add($this->mainSocket,  \Man\Core\Events\BaseEvent::EV_READ, array($this, 'onAccept'));
        
        // 主体循环
        $ret = $this->event->loop();
    }
    
    /**
     * 当有链接事件时触发
     * @param resource $socket
     * @param null $null_one
     * @param null $null_two
     * @return void
     */
    public function onAccept($socket, $null_one = null, $null_two = null)
    {
        $fd = $this->accept($socket, $null_one , $null_two);
        if($fd)
        {
            $this->currentDealFd = (int)$fd;
            if($this->getRemoteIp() != '127.0.0.1')
            {
                $this->sendToClient("Password\n");
            }
            else 
            {
                $this->adminAuth[$this->currentDealFd] = time();
                $this->sendToClient("Hello admin\n");
            }
        }
    }
    
    
    /**
     * 确定包是否完整
     * @see Worker::dealInput()
     */
    public function dealInput($recv_buffer)
    {
        return 0;
    }
    
    /**
     * 处理业务
     * @see Worker::dealProcess()
     */
    public function dealProcess($buffer)
    {
        
        $buffer = trim($buffer);
        
        $ip = $this->getRemoteIp();
        if($ip != '127.0.0.1' && $buffer == 'status')
        {
            \Man\Core\Lib\Log::add("IP:$ip $buffer");
        }
        
        // 判断是否认证过
        $this->adminAuth[$this->currentDealFd] = !isset($this->adminAuth[$this->currentDealFd]) ? 0 : $this->adminAuth[$this->currentDealFd];
        if($this->adminAuth[$this->currentDealFd] < 3)
        {
            if($buffer != \Man\Core\Lib\Config::get($this->workerName.'.password'))
            {
                if(++$this->adminAuth[$this->currentDealFd] >= 3)
                {
                    $this->sendToClient("Password Incorrect \n");
                    $this->closeClient($this->currentDealFd);
                    return;
                }
                $this->sendToClient("Please Try Again\n");
                return;
            }
            else
            {
                $this->adminAuth[$this->currentDealFd] = time();
                $this->sendToClient("Hello Admin \n");
                return;
            }
        }
        
        
        // 单独停止某个worker进程
        if(preg_match("/kill (\d+)/", $buffer, $match))
        {
            $pid = $match[1];
            $this->sendToClient("Kill Pid $pid\n");
            if(!posix_kill($pid, SIGHUP))
            {
                $this->sendToClient("Pid Not Exsits\n");
            }
            return;
        }
        
        $master_pid = file_get_contents(WORKERMAN_PID_FILE);
        
        switch($buffer)
        {
            // 展示统计信息
            case 'status':
                $status = $this->getMasterStatus();
                if(empty($status))
                {
                    $this->sendToClient("Can not get Master status, Extension sysvshm or sysvmsg may not enabled\n");
                    return;
                }
                $worker_pids = $this->getWorkerPidMap();
                $pid_worker_name_map = $this->getPidWorkerMap();
                foreach($worker_pids as $worker_name=>$pid_array)
                {
                    if($this->maxWorkerNameLength < strlen($worker_name))
                    {
                        $this->maxWorkerNameLength = strlen($worker_name);
                    }
                }
                foreach(\Man\Core\Lib\Config::getAllWorkers() as $worker_name=>$config)
                {
                    if(!isset($config['listen']))
                    {
                        continue;
                    }
                    if($this->maxAddressLength < strlen($config['listen']))
                    {
                        $this->maxAddressLength = strlen($config['listen']);
                    }
                }
                
                $msg_type = $message = 0;
                // 将过期的消息读出来，清理掉
                if(\Man\Core\Master::getQueueId())
                {
                    while(@msg_receive(\Man\Core\Master::getQueueId(), self::MSG_TYPE_STATUS, $msg_type, 1000, $message, true, MSG_IPC_NOWAIT))
                    {
                    }
                }
                $loadavg = sys_getloadavg();
                $this->sendToClient("---------------------------------------GLOBAL STATUS--------------------------------------------\n");
                $this->sendToClient(\Man\Core\Master::NAME.' version:' . \Man\Core\Master::VERSION . "\n");
                $this->sendToClient('start time:'. date('Y-m-d H:i:s', $status['start_time']).'   run ' . floor((time()-$status['start_time'])/(24*60*60)). ' days ' . floor(((time()-$status['start_time'])%(24*60*60))/(60*60)) . " hours   \n");
                $this->sendToClient('load average: ' . implode(", ", $loadavg) . "\n");
                $this->sendToClient(count($this->connections) . ' users          ' . count($worker_pids) . ' workers       ' . count($pid_worker_name_map)." processes\n");
                $this->sendToClient(str_pad('worker_name', $this->maxWorkerNameLength) . " exit_status     exit_count\n");
                foreach($worker_pids as $worker_name=>$pid_array)
                {
                    if(isset($status['worker_exit_code'][$worker_name]))
                    {
                        foreach($status['worker_exit_code'][$worker_name] as  $exit_status=>$exit_count)
                        {
                            $this->sendToClient(str_pad($worker_name, $this->maxWorkerNameLength) . " " . str_pad($exit_status, 16). " $exit_count\n");
                        }
                    }
                    else
                    {
                        $this->sendToClient(str_pad($worker_name, $this->maxWorkerNameLength) . " " . str_pad(0, 16). " 0\n");
                    }
                }
                
                $this->sendToClient("---------------------------------------PROCESS STATUS-------------------------------------------\n");
                $this->sendToClient("pid\tmemory  ".str_pad('    listening', $this->maxAddressLength)." timestamp  ".str_pad('worker_name', $this->maxWorkerNameLength)." ".str_pad('total_request', 13)." ".str_pad('packet_err', 10)." ".str_pad('thunder_herd', 12)." ".str_pad('client_close', 12)." ".str_pad('send_fail', 9)." ".str_pad('throw_exception', 15)." suc/total status\n");
                if(!\Man\Core\Master::getQueueId())
                {
                    return;
                }
                
                $time_start = microtime(true);
                unset($pid_worker_name_map[posix_getpid()]);
                $total_worker_count = count($pid_worker_name_map);
                foreach($pid_worker_name_map as $pid=>$worker_name)
                {
                    posix_kill($pid, SIGUSR1);
                    if($response_pid = $this->getStatusFromQueue())
                    {
                        unset($pid_worker_name_map[$response_pid]);
                        $total_worker_count--;
                    }
                }
                
                while(count($pid_worker_name_map) > 0)
                {
                    if($response_pid = $this->getStatusFromQueue())
                    {
                        unset($pid_worker_name_map[$response_pid]);
                        $total_worker_count--;
                    }
                    if(microtime(true) - $time_start > 0.1)
                    {
                        break;
                    }
                }
                
                foreach($pid_worker_name_map as $pid=>$worker_name)
                {
                    if('FileMonitor' == $worker_name)
                    {
                        continue;
                    }
                    
                    $address = \Man\Core\Lib\Config::get($worker_name . '.listen');
                    if(!$address)
                    {
                        $address = 'none';
                    }
                    $str = "$pid\t".str_pad("N/A", 7)." " .str_pad($address,$this->maxAddressLength) ." N/A        ".str_pad($worker_name, $this->maxWorkerNameLength)." ";
                    $str = $str . str_pad("N/A", 14)." ".str_pad("N/A",10)." ".str_pad("N/A",12)." ".str_pad("N/A", 12)." ".str_pad("N/A",9)." ".str_pad("N/A",15)." N/A       \033[33;33mbusy\033[0m";
                    $this->sendToClient($str."\n");
                }
                break;
                // 停止server
            case 'stop':
                if($master_pid)
                {
                    $this->sendToClient("stoping....\n");
                    posix_kill($master_pid, SIGINT);
                }
                else
                {
                    $this->sendToClient("Can not get master pid\n");
                }
                break;
                // 平滑重启server
            case 'reload':
                $pid_worker_name_map = $this->getPidWorkerMap();
                unset($pid_worker_name_map[posix_getpid()]);
                if($pid_worker_name_map)
                {
                    foreach($pid_worker_name_map as $pid=>$item)
                    {
                        posix_kill($pid, SIGHUP);
                    }
                    $this->sendToClient("Restart Workers\n");
                }
                else
                {
                    if($master_pid)
                    {
                        posix_kill($master_pid, SIGHUP);
                        $this->sendToClient("Restart Workers\n");
                    }
                    else
                    {
                        $this->sendToClient("Can not get master pid\n");
                    }
                }
                break;
                // admin管理员退出
            case 'quit':
                $this->sendToClient("Admin Quit\n");
                $this->closeClient($this->currentDealFd);
                break;
            case '':
                break;
            default:
                $this->sendToClient("Unkonw CMD \nAvailable CMD:\n status     show server status\n stop       stop server\n reload     graceful restart server\n quit       quit and close connection\n kill pid   kill the worker process of the pid\n");
        }
    }
    
    /**
     * 从消息队列中获取主进程状态
     * @return void
     */
    protected function getStatusFromQueue()
    {
        if(@msg_receive(\Man\Core\Master::getQueueId(), self::MSG_TYPE_STATUS, $msg_type, 10000, $message, true, MSG_IPC_NOWAIT))
        {
            $pid = $message['pid'];
            $worker_name = $message['worker_name'];
            $address = \Man\Core\Lib\Config::get($worker_name . '.listen');
            if(!$address)
            {
                $address = 'none';
            }
            $str = "$pid\t".str_pad(round($message['memory']/(1024*1024),2)."M", 7)." " .str_pad($address,$this->maxAddressLength) ." ". $message['start_time'] ." ".str_pad($worker_name, $this->maxWorkerNameLength)." ";
            if($message)
            {
                $str = $str . str_pad($message['total_request'], 14)." ".str_pad($message['packet_err'],10)." ".str_pad($message['thunder_herd'],12)." ".str_pad($message['client_close'], 12)." ".str_pad($message['send_fail'],9)." ".str_pad($message['throw_exception'],15)." ".str_pad(($message['total_request'] == 0 ? 100 : (round(($message['total_request']-($message['packet_err']+$message['send_fail']))/$message['total_request'], 6)*100))."%", 9) . " \033[32;40midle\033[0m";
            }
            else
            {
                $str .= var_export($message, true);
            }
            $this->sendToClient($str."\n");
            return $pid;
        }
        return false;
    }
    
    
    /**
     * 清理日志目录
     * @param string $dir
     * @return void
     */
    public function clearLogs($dir)
    {
        $time_now = time();
        foreach(glob($dir."/20*-*-*") as $file)
        {
            if(!is_dir($file)) continue;
            $base_name = basename($file);
            $log_time = strtotime($base_name);
            if($log_time === false) continue;
            if(($time_now - $log_time)/self::SECONDS_ONE_DAY >= self::CLEAR_BEFORE_DAYS)
            {
                $this->recursiveDelete($file);
            }
            
        }
    }
    
    /**
     * 检测主进程是否存在
     * @return void
     */
    public function checkMasterProcess()
    {
        $master_pid = \Man\Core\Master::getMasterPid();
        if(!posix_kill($master_pid, 0))
        {
            $this->onMasterDead();
        }
    }
    
    /**
     * 主进程挂掉会触发
     * @return void
     */
    protected function onMasterDead()
    {
        // 不要频繁告警，5分钟告警一次
        $time_now = time();
        if($time_now - self::$lastWarningTimeMap[self::WARNING_MASTER_DEAD] < self::WARING_SEND_TIME_LONG)
        {
            return;
        }
        // 延迟告警，启动脚本kill掉主进程不告警，该进程也会随之kill掉
        sleep(5);
        
        $ip = $this->getIp();
        
        $this->sendSms('告警消息 WorkerMan框架监控 ip:'.$ip.' 主进程意外退出');
        
        // 记录这次告警时间
        self::$lastWarningTimeMap[self::WARNING_MASTER_DEAD] = $time_now;
    }
    
    /**
     * 检查主进程状态统计信息
     * @return void
     */
    public function checkMasterStatus()
    {
        $status = $this->getMasterStatus();
        if(empty($status))
        {
            $this->notice("can not get master status" , false);
            return;
        }
        $status = $status['worker_exit_code'];
        if(null === $this->lastMasterStatus)
        {
            $this->lastMasterStatus = $status;
            return;
        }
        
        $max_worker_exit_count = (int)\Man\Core\Lib\Config::get($this->workerName.".max_worker_exit_count");
        if($max_worker_exit_count <= 0)
        {
            $max_worker_exit_count = 2000;
        }
        
        foreach($status as $worker_name => $code_count_info)
        {
            foreach($code_count_info as $code=>$count)
            {
                $last_count = isset($this->lastMasterStatus[$worker_name][$code]) ? $this->lastMasterStatus[$worker_name][$code] : 0;
                $inc_count = $count - $last_count;
                if($inc_count >= $max_worker_exit_count)
                {
                    $this->onTooManyWorkersExits($worker_name, $code, $inc_count);
                }
            }
        }
        $this->lastMasterStatus = $status;
    }
    
    /**
     * 检查worker进程是否有严重的内存泄漏
     * @return void
     */
    public function checkMemUsage()
    {
        foreach($this->getPidWorkerMap() as $pid=>$worker_name)
        {
            $this->checkWorkerMemByPid($pid, $worker_name);
        }
    }
    
    /**
     * 根据进程id收集进程内存占用情况
     * @param int $pid
     * @return void
     */
    protected function checkWorkerMemByPid($pid, $worker_name)
    {
        $mem_limit = \Man\Core\Lib\Config::get($this->workerName.'.max_mem_limit');
        if(!$mem_limit)
        {
            $mem_limit = self::DEFAULT_MEM_LIMIT;
        }
        // 读取系统对该进程统计的信息
        $status_file = "/proc/$pid/status";
        if(is_file($status_file))
        {
            // 获取信息
            $status = file_get_contents($status_file);
            if(empty($status))
            {
                return;
            }
            // 目前只需要进程的内存占用信息
            $match = array();
            if(preg_match('/VmRSS:\s+(\d+)\s+([a-zA-Z]+)/', $status, $match))
            {
                $memory_usage = $match[1];
                if($memory_usage >= $mem_limit)
                {
                    posix_kill($pid, SIGHUP);
                    $this->notice("worker:$worker_name pid:$pid memory exceeds the maximum $memory_usage>=$mem_limit");
                }
            }
        }
    }
    
    /**
     * 当有大量进程频繁退出时触发
     * @param string $worker_name
     * @param int $status
     * @param int $exit_count
     * @return void
     */
    public function onTooManyWorkersExits($worker_name, $status, $exit_count)
    {
        // 不要频繁告警，5分钟告警一次
        $time_now = time();
        if($time_now - self::$lastWarningTimeMap[self::WARNING_TOO_MANY_WORKERS_EXIT] < self::WARING_SEND_TIME_LONG)
        {
            return;
        }
    
        $ip = $this->getIp();
        
        if(65280 == $status || 30720 == $status)
        {
            $this->sendSms('告警消息 Workerman框架监控 '.$ip.' '.$worker_name.'5分钟内出现 FatalError '.$exit_count.'次 时间:'.date('Y-m-d H:i:s'));
        }
        else
        {
            $this->sendSms('告警消息 Workerman框架监控 '.$ip.' '.$worker_name.' 进程频繁退出 退出次数'.$exit_count.' 退出状态码：'.$status .' 时间:'.date('Y-m-d H:i:s'));
        }
    
        // 记录这次告警时间
        self::$lastWarningTimeMap[self::WARNING_TOO_MANY_WORKERS_EXIT] = $time_now;
    }
    
    /**
     * 发送短信
     * @param int $phone_num
     * @param string $content
     * @return void
     */
    protected function sendSms($content)
    {
        // 短信告警
        
    }
    
    /**
     * 获取本地ip
     * @param string $worker_name
     * @return string
     */
    public function getIp($worker_name = '')
    {
        $ip = $this->getLocalIp();
        if(empty($ip) || $ip == '0.0.0.0' || $ip = '127.0.0.1')
        {
            if($worker_name)
            {
                $ip = \Man\Core\Lib\Config::get($worker_name . '.ip');
            }
            if(empty($ip) || $ip == '0.0.0.0' || $ip = '127.0.0.1')
            {
                $ret_string = shell_exec('ifconfig');
                if(preg_match("/:(\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3})/", $ret_string, $match))
                {
                    $ip = $match[1];
                }
            }
        }
        return $ip;
    }
    
    /**
     * 递归删除文件
     * @param string $path
     */
    private function recursiveDelete($path)
    {
        return is_file($path) ? unlink($path) : array_map(array($this, 'recursiveDelete'),glob($path.'/*')) == rmdir($path);
    }
    
} 
