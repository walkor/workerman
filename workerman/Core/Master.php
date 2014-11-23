<?php 
namespace Man\Core;

if(!defined('WORKERMAN_ROOT_DIR'))
{
    define('WORKERMAN_ROOT_DIR', realpath(__DIR__."/../../")."/");
}

require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Checker.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Config.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Task.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Log.php';
require_once WORKERMAN_ROOT_DIR . 'Core/Lib/Mutex.php';

/**
 * 
 * 主进程
 * 
 * @package Core
 * 
* @author walkor <walkor@workerman.net>
 * <b>使用示例:</b>
 * <pre>
 * <code>
 * Man\Core\Master::run();
 * <code>
 * </pre>
 * 
 */
class Master
{
    /**
     * 版本
     * @var string
     */
    const VERSION = '2.1.5';
    
    /**
     * 服务名
     * @var string
     */
    const NAME = 'WorkerMan';
    
    /**
     * 服务状态 启动中
     * @var integer
     */ 
    const STATUS_STARTING = 1;
    
    /**
     * 服务状态 运行中
     * @var integer
     */
    const STATUS_RUNNING = 2;
    
    /**
     * 服务状态 关闭中
     * @var integer
     */
    const STATUS_SHUTDOWN = 4;
    
    /**
     * 服务状态 平滑重启中
     * @var integer
     */
    const STATUS_RESTARTING_WORKERS = 8;
    
    /**
     * 整个服务能够启动的最大进程数
     * @var integer
     */
    const SERVER_MAX_WORKER_COUNT = 5000;
    
    /**
     * 单个进程打开文件数限制
     * @var integer
     */
    const MIN_SOFT_OPEN_FILES = 10000;
    
    /**
     * 单个进程打开文件数限制 硬性限制
     * @var integer
     */
    const MIN_HARD_OPEN_FILES = 10000;
    
    /**
     * 共享内存中用于存储主进程统计信息的变量id
     * @var integer
     */
    const STATUS_VAR_ID = 1;
    
    /**
     * 发送停止命令多久后worker没退出则发送sigkill信号
     * @var integer
     */
    const KILL_WORKER_TIME_LONG = 4;
    
    /**
     * 默认listen的backlog，如果没配置backlog，则使用此值
     * @var integer
     */
    const DEFAULT_BACKLOG= 1024;
    
    /**
     * 用于保存所有子进程pid ['worker_name1'=>[pid1=>pid1,pid2=>pid2,..], 'worker_name2'=>[pid7,..], ...]
     * @var array
     */
    protected static $workerPidMap = array();
    
    /**
     * 服务的状态，默认是启动中
     * @var integer
     */
    protected static $serviceStatus = self::STATUS_STARTING;
    
    /**
     * 用来监听端口的Socket数组，用来fork worker使用
     * @var array
     */
    protected static $listenedSocketsArray = array();
    
    /**
     * 要重启r的pid数组 [pid1=>time_stamp, pid2=>time_stamp, ..]
     * @var array
     */
    protected static $pidsToRestart = array();
    
    /**
     * 共享内存resource id
     * @var resource
     */
    protected static $shmId = 0;
    
    /**
     * 消息队列 resource id
     * @var resource
     */
    protected static $queueId = 0;
    
    /**
     * master进程pid
     * @var integer
     */
    protected static $masterPid = 0;
    
    /**
     * server统计信息 ['start_time'=>time_stamp, 'worker_exit_code'=>['worker_name1'=>[code1=>count1, code2=>count2,..], 'worker_name2'=>[code3=>count3,...], ..] ]
     * @var array
     */
    protected static $serviceStatusInfo = array(
        'start_time' => 0,
        'worker_exit_code' => array(),
    );
    
    /**
     * 服务运行
     * @return void
     */
    public static function run()
    {
        // 输出信息
        self::notice("Workerman is starting ...", true);
        // 检查环境
        self::checkEnv();
        // 初始化
        self::init();
        // 变成守护进程
        self::daemonize();
        // 保存进程pid
        self::savePid();
        // 安装信号
        self::installSignal();
        // 创建监听套接字
        self::createListeningSockets();
        // 创建worker进程
        self::spawnWorkers();
        // 输出信息
        self::notice("\033[1A\n\033[KWorkerman start success ...\033[0m", true);
        // 标记服务状态为运行中
        self::$serviceStatus = self::STATUS_RUNNING;
        // 初始化任务
        \Man\Core\Lib\Task::init();
        // 关闭标准输出
        self::resetStdFd();
        // 主循环
        self::loop();
    }
    
    
    /**
     * 初始化 配置、进程名、共享内存、消息队列等
     * @return void
     */
    public static function init()
    {
        // 因为子进程要更换用户、开低端口等，必须是root启动
        if($user_info = posix_getpwuid(posix_getuid()))
        {
            if($user_info['name'] !== 'root')
            {
                exit("\033[31;40mYou should run workerman as root . Permission denied\033[0m\n");
            }
        }
        
        // 获取配置文件
        $config_path = Lib\Config::$configFile;
    
        // 设置进程名称，如果支持的话
        self::setProcTitle(self::NAME.':master with-config:' . $config_path);
        
        // 初始化共享内存消息队列
        if(extension_loaded('sysvmsg') && extension_loaded('sysvshm'))
        {
            self::$shmId = shm_attach(IPC_KEY, DEFAULT_SHM_SIZE);
            self::$queueId = msg_get_queue(IPC_KEY);
            msg_set_queue(self::$queueId,array('msg_qbytes'=>DEFAULT_MSG_QBYTES));
        }
    }
    
    /**
     * 检查环境配置
     * @return void
     */
    public static function checkEnv()
    {
        // 检查PID文件
        Lib\Checker::checkPidFile();
        
        // 检查扩展支持情况
        Lib\Checker::checkExtension();
        
        // 检查函数禁用情况
        Lib\Checker::checkDisableFunction();
        
        // 检查log目录是否可读
        Lib\Log::init();
        
        // 检查配置和语法错误等
        Lib\Checker::checkWorkersConfig();
        
        // 检查文件限制
        Lib\Checker::checkLimit();
    }
    
    /**
     * 使之脱离终端，变为守护进程
     * @return void
     */
    protected static function daemonize()
    {
        // 设置umask
        umask(0);
        // fork一次
        $pid = pcntl_fork();
        if(-1 == $pid)
        {
            // 出错退出
            exit("Can not fork");
        }
        elseif($pid > 0)
        {
            // 父进程，退出
            exit(0);
        }
        // 成为session leader
        if(-1 == posix_setsid())
        {
            // 出错退出
            exit("Setsid fail");
        }
    
        // 再fork一次，防止在符合SVR4标准的系统下进程再次获得终端
        $pid2 = pcntl_fork();
        if(-1 == $pid2)
        {
            // 出错退出
            exit("Can not fork");
        }
        elseif(0 !== $pid2)
        {
            // 禁止进程重新打开控制终端
            exit(0);
        }
    
        // 记录服务启动时间
        self::$serviceStatusInfo['start_time'] = time();
    }
    
    /**
     * 保存主进程pid
     * @return void
     */
    public static function savePid()
    {
        // 保存在变量中
        self::$masterPid = posix_getpid();
        
        // 保存到文件中，用于实现停止、重启
        if(false === @file_put_contents(WORKERMAN_PID_FILE, self::$masterPid))
        {
            exit("\033[31;40mCan not save pid to pid-file(" . WORKERMAN_PID_FILE . ")\033[0m\n\n\033[31;40mServer start fail\033[0m\n\n");
        }
        
        // 更改权限
        chmod(WORKERMAN_PID_FILE, 0644);
    }
    
    /**
     * 获取主进程pid
     * @return int
     */
    public static function getMasterPid()
    {
        return self::$masterPid;
    }
    
    /**
     * 根据配置文件，创建监听套接字
     * @return void
     */
    protected static function createListeningSockets()
    {
        // 循环读取配置创建socket
        foreach (Lib\Config::getAllWorkers() as $worker_name=>$config)
        {
            if(isset($config['listen']))
            {
                $context = self::getSocketContext($worker_name);
                $flags = substr($config['listen'], 0, 3) == 'udp' ? STREAM_SERVER_BIND : STREAM_SERVER_BIND | STREAM_SERVER_LISTEN;
                $error_no = 0;
                $error_msg = '';
                // 创建监听socket
                if($context)
                {
                    self::$listenedSocketsArray[$worker_name] = stream_socket_server($config['listen'], $error_no, $error_msg, $flags, $context);
                }
                else
                {
                    self::$listenedSocketsArray[$worker_name] = stream_socket_server($config['listen'], $error_no, $error_msg, $flags);
                }
                if(!self::$listenedSocketsArray[$worker_name])
                {
                    Lib\Log::add("can not create socket {$config['listen']} info:{$error_no} {$error_msg}\tServer start fail");
                    exit("\n\033[31;40mCan not create socket {$config['listen']} {$error_msg}\033[0m\n\n\033[31;40mWorkerman start fail\033[0m\n\n");
                }
            }
        }
    }
    
    
    /**
     * 根据配置文件创建Workers
     * @return void
     */
    protected static function spawnWorkers()
    {
        // 生成一定量的worker进程
        foreach (Lib\Config::getAllWorkers() as $worker_name=>$config)
        {
            // 初始化
            if(empty(self::$workerPidMap[$worker_name]))
            {
                self::$workerPidMap[$worker_name] = array();
            }
    
            while(count(self::$workerPidMap[$worker_name]) < $config['start_workers'])
            {
                // 子进程退出
                if(self::createOneWorker($worker_name) == 0)
                {
                    self::notice("Worker exit unexpected");
                    exit(500);
                }
            }
        }
    }
    
    /**
     * 创建一个worker进程
     * @param string $worker_name 服务名
     * @return int 父进程:>0得到新worker的pid ;<0 出错; 子进程:始终为0
     */
    protected static function createOneWorker($worker_name)
    {
        // 创建子进程
        $pid = pcntl_fork();
        
        // 先处理收到的信号
        pcntl_signal_dispatch();
        
        // 父进程
        if($pid > 0)
        {
            // 初始化master的一些东东
            self::$workerPidMap[$worker_name][$pid] = $pid;
            // 更新进程信息到共享内存
            self::updateStatusToShm();
            
            return $pid;
        }
        // 子进程
        elseif($pid === 0)
        {
            // 忽略信号
            self::ignoreSignal();
            
            // 清空任务
            Lib\Task::delAll();
            
            // 关闭不用的监听socket
            foreach(self::$listenedSocketsArray as $tmp_worker_name => $tmp_socket)
            {
                if($tmp_worker_name != $worker_name)
                {
                    fclose($tmp_socket);
                }
            }
    
            // 尝试以指定用户运行worker进程
            if($worker_user = Lib\Config::get($worker_name . '.user'))
            {
                self::setProcUser($worker_user);
            }
            
            // 关闭输出
            self::resetStdFd(Lib\Config::get($worker_name.'.no_debug'));
    
            // 尝试设置子进程进程名称
            self::setWorkerProcTitle($worker_name);
    
            // 包含必要文件
            require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
            
            // 查找worker文件
            $worker_file = \Man\Core\Lib\Config::get($worker_name.'.worker_file');
            $class_name = basename($worker_file, '.php');
            
            // 如果有语法错误 sleep 5秒 避免狂刷日志
            if(\Man\Core\Lib\Checker::checkSyntaxError($worker_file, $class_name))
            {
                sleep(5);
            }
            require_once $worker_file;
            
            // 创建实例
            $worker = new $class_name($worker_name);
            
            // 如果该worker有配置监听端口，则将监听端口的socket传递给子进程
            if(isset(self::$listenedSocketsArray[$worker_name]))
            {
                $worker->setListendSocket(self::$listenedSocketsArray[$worker_name]);
            }
            
            // 使worker开始服务
            $worker->start();
            return 0;
        }
        // 出错
        else
        {
            self::notice("create worker fail worker_name:$worker_name detail:pcntl_fork fail");
            return $pid;
        }
    }
    
    
    /**
     * 安装相关信号控制器
     * @return void
     */
    protected static function installSignal()
    {
        // 设置终止信号处理函数
        pcntl_signal(SIGINT,  array('\Man\Core\Master', 'signalHandler'), false);
        // 设置SIGUSR1信号处理函数,测试用
        pcntl_signal(SIGUSR1, array('\Man\Core\Master', 'signalHandler'), false);
        // 设置SIGUSR2信号处理函数,平滑重启Server
        pcntl_signal(SIGHUP, array('\Man\Core\Master', 'signalHandler'), false);
        // 设置子进程退出信号处理函数
        pcntl_signal(SIGCHLD, array('\Man\Core\Master', 'signalHandler'), false);
    
        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_IGN);
    }
    
    /**
     * 忽略信号
     * @return void
     */
    protected static function ignoreSignal()
    {
        // 设置忽略信号
        pcntl_signal(SIGPIPE, SIG_IGN);
        pcntl_signal(SIGTTIN, SIG_IGN);
        pcntl_signal(SIGTTOU, SIG_IGN);
        pcntl_signal(SIGQUIT, SIG_IGN);
        pcntl_signal(SIGALRM, SIG_IGN);
        pcntl_signal(SIGINT, SIG_IGN);
        pcntl_signal(SIGUSR1, SIG_IGN);
        pcntl_signal(SIGUSR2, SIG_IGN);
        pcntl_signal(SIGHUP, SIG_IGN);
    }
    
    /**
     * 设置server信号处理函数
     * @param null $null
     * @param int $signal
     * @return void
     */
    public static function signalHandler($signal)
    {
        switch($signal)
        {
            // 停止服务信号
            case SIGINT:
                self::notice("Workerman is shutting down");
                self::stop();
                break;
            // 测试用
            case SIGUSR1:
                break;
            // worker退出信号
            case SIGCHLD:
                // 这里什么也不做
                // self::checkWorkerExit();
                break;
            // 平滑重启server信号
            case SIGHUP:
                Lib\Config::reload();
                self::notice("Workerman reloading");
                $pid_worker_name_map = self::getPidWorkerNameMap();
                $pids_to_restart = array();
                foreach($pid_worker_name_map as $pid=>$worker_name)
                {
                    // 如果对应进程配置了不热启动则不重启对应进程
                    if(Lib\Config::get($worker_name.'.no_reload'))
                    {
                        // 发送reload信号，以便触发onReload方法
                        posix_kill($pid, SIGHUP);
                        continue;
                    }
                    $pids_to_restart[] = $pid;
                }
                self::addToRestartPids($pids_to_restart);
                self::restartPids();
                break;
        }
    }
    
    /**
     * 设置子进程进程名称
     * @param string $worker_name
     * @return void
     */
    public static function setWorkerProcTitle($worker_name)
    {
        if(isset(self::$listenedSocketsArray[$worker_name]))
        {
            // 获得socket的信息
            $sock_name = stream_socket_get_name(self::$listenedSocketsArray[$worker_name], false);
            
            // 更改进程名，如果支持的话
            $mata_data = stream_get_meta_data(self::$listenedSocketsArray[$worker_name]);
            $protocol = substr($mata_data['stream_type'], 0, 3);
            self::setProcTitle(self::NAME.":worker $worker_name {$protocol}://$sock_name");
        }
        else
        {
            self::setProcTitle(self::NAME.":worker $worker_name");
        }
            
    }
    
    /**
     * 主进程主循环 主要是监听子进程退出、服务终止、平滑重启信号
     * @return void
     */
    public static function loop()
    {
        while(1)
        {
            sleep(1);
            // 检查是否有进程退出
            self::checkWorkerExit();
            // 触发信号处理
            pcntl_signal_dispatch();
        }
    }
    
    
    /**
     * 监控worker进程状态，退出重启
     * @param resource $channel
     * @param int $flag
     * @param int $pid 退出的进程id
     * @return mixed
     */
    public static function checkWorkerExit()
    {
        // 由于SIGCHLD信号可能重叠导致信号丢失，所以这里要循环获取所有退出的进程id
        while(($pid = pcntl_waitpid(-1, $status, WUNTRACED | WNOHANG)) != 0)
        {
            // 如果是重启的进程，则继续重启进程
            if(isset(self::$pidsToRestart[$pid]) && self::$serviceStatus != self::STATUS_SHUTDOWN)
            {
                unset(self::$pidsToRestart[$pid]);
                self::restartPids();
            }
    
            // 出错
            if($pid < 0)
            {
                $last_error = function_exists('pcntl_get_last_error') ? pcntl_get_last_error() : 'function pcntl_get_last_error not exists';
                self::notice('pcntl_waitpid return '.$pid.' and pcntl_get_last_error = ' . $last_error);
                return $pid;
            }
    
            // 查找子进程对应的woker_name
            $pid_workname_map = self::getPidWorkerNameMap();
            $worker_name = isset($pid_workname_map[$pid]) ? $pid_workname_map[$pid] : '';
            // 没找到worker_name说明出错了
            if(empty($worker_name))
            {
                self::notice("child exist but not found worker_name pid:$pid");
                break;
            }
    
            // 进程退出状态不是0，说明有问题了
            if($status !== 0)
            {
                self::notice("worker[$pid:$worker_name] exit with status $status");
            }
            // 记录进程退出状态
            self::$serviceStatusInfo['worker_exit_code'][$worker_name][$status] = isset(self::$serviceStatusInfo['worker_exit_code'][$worker_name][$status]) ? self::$serviceStatusInfo['worker_exit_code'][$worker_name][$status] + 1 : 1;
            // 更新状态到共享内存
            self::updateStatusToShm();
            
            // 清理这个进程的数据
            self::clearWorker($worker_name, $pid);
    
            // 如果服务是不是关闭中
            if(self::$serviceStatus != self::STATUS_SHUTDOWN)
            {
                // 重新创建worker
                self::spawnWorkers();
            }
            // 判断是否都重启完毕
            else
            {
                $all_worker_pid = self::getPidWorkerNameMap();
                if(empty($all_worker_pid))
                {
                    // 删除共享内存
                    self::removeShmAndQueue();
                    // 发送提示
                    self::notice("Workerman stoped");
                    // 删除pid文件
                    @unlink(WORKERMAN_PID_FILE);
                    exit(0);
                }
            }//end if
        }//end while
    }
    
    /**
     * 获取pid 到 worker_name 的映射
     * @return array ['pid1'=>'worker_name1','pid2'=>'worker_name2', ...]
     */
    public static function getPidWorkerNameMap()
    {
        $all_pid = array();
        foreach(self::$workerPidMap as $worker_name=>$pid_array)
        {
            foreach($pid_array as $pid)
            {
                $all_pid[$pid] = $worker_name;
            }
        }
        return $all_pid;
    }
    
    /**
     * 放入重启队列中
     * @param array $restart_pids
     * @return void
     */
    public static function addToRestartPids($restart_pids)
    {
        if(!is_array($restart_pids))
        {
            self::notice("addToRestartPids(".var_export($restart_pids, true).") \$restart_pids not array");
            return false;
        }
    
        // 将pid放入重启队列
        foreach($restart_pids as $pid)
        {
            if(!isset(self::$pidsToRestart[$pid]))
            {
                // 重启时间=0
                self::$pidsToRestart[$pid] = 0;
            }
        }
    }
    
    /**
     * 重启workers
     * @return void
     */
    public static function restartPids()
    {
        // 标记server状态
        if(self::$serviceStatus != self::STATUS_RESTARTING_WORKERS && self::$serviceStatus != self::STATUS_SHUTDOWN)
        {
            self::$serviceStatus = self::STATUS_RESTARTING_WORKERS;
        }
    
        // 没有要重启的进程了
        if(empty(self::$pidsToRestart))
        {
            self::$serviceStatus = self::STATUS_RUNNING;
            self::notice("\nWorker Restart Success");
            return true;
        }
    
        // 遍历要重启的进程 标记它们重启时间
        foreach(self::$pidsToRestart as $pid => $stop_time)
        {
            if($stop_time == 0)
            {
                self::$pidsToRestart[$pid] = time();
                posix_kill($pid, SIGHUP);
                Lib\Task::add(self::KILL_WORKER_TIME_LONG, array('\Man\Core\Master', 'forceKillWorker'), array($pid), false);
                break;
            }
        }
    }
    
    /**
     * worker进程退出时，master进程的一些清理工作
     * @param string $worker_name
     * @param int $pid
     * @return void
     */
    protected static function clearWorker($worker_name, $pid)
    {
        // 释放一些不用了的数据
        unset(self::$pidsToRestart[$pid], self::$workerPidMap[$worker_name][$pid]);
    }
    
    /**
     * 停止服务
     * @return void
     */
    public static function stop()
    {
        
        // 如果没有子进程则直接退出
        $all_worker_pid = self::getPidWorkerNameMap();
        if(empty($all_worker_pid))
        {
            exit(0);
        }
    
        // 标记server开始关闭
        self::$serviceStatus = self::STATUS_SHUTDOWN;
    
        // killWorkerTimeLong 秒后如果还没停止则强制杀死所有进程
        Lib\Task::add(self::KILL_WORKER_TIME_LONG, array('\Man\Core\Master', 'stopAllWorker'), array(true), false);
    
        // 停止所有worker
        self::stopAllWorker();
    }
    
    /**
     * 停止所有worker
     * @param bool $force 是否强制退出
     * @return void
     */
    public static function stopAllWorker($force = false)
    {
        // 获得所有pid
        $all_worker_pid = self::getPidWorkerNameMap();
    
        // 强行杀死
        if($force)
        {
            // 杀死所有子进程
            foreach($all_worker_pid as $pid=>$worker_name)
            {
                // 发送SIGKILL信号
                self::forceKillWorker($pid);
                self::notice("Kill workers[$worker_name] force");
            }
        }
        else
        {
            // 向所有子进程发送终止信号
            foreach($all_worker_pid as $pid=>$worker_name)
            {
                // 发送SIGINT信号
                posix_kill($pid, SIGINT);
            }
        }
    }
    
    
    /**
     * 强制杀死进程
     * @param int $pid
     * @return void
     */
    public static function forceKillWorker($pid)
    {
        if(posix_kill($pid, 0))
        {
            self::notice("Kill workers $pid force!");
            posix_kill($pid, SIGKILL);
        }
    }
    
    
    /**
     * 设置运行用户
     * @param string $worker_user
     * @return void
     */
    protected static function setProcUser($worker_user)
    {
        $user_info = posix_getpwnam($worker_user);
        if($user_info['uid'] != posix_getuid() || $user_info['gid'] != posix_getgid())
        {
            // 尝试设置gid uid
            if(!posix_setgid($user_info['gid']) || !posix_setuid($user_info['uid']))
            {
                self::notice( 'Notice : Can not run woker as '.$worker_user." , You shuld be root\n", true);
            }
        }
    }
    
    /**
     * 获取共享内存资源id
     * @return resource
     */
    public static function getShmId()
    {
        return self::$shmId;
    }
    
    /**
     * 获取消息队列资源id
     * @return resource
     */
    public static function getQueueId()
    {
        return self::$queueId;
    }
    
    
    /**
     * 关闭标准输入输出
     * @return void
     */
    protected static function resetStdFd($force = false)
    {
        // 如果此进程配置是no_debug，则关闭输出
        if(!$force)
        {
            // 开发环境不关闭标准输出，用于调试
            if(Lib\Config::get('workerman.debug') == 1 && posix_ttyname(STDOUT))
            {
                return;
            }
        }
        global $STDOUT, $STDERR;
        @fclose(STDOUT);
        @fclose(STDERR);
        // 将标准输出重定向到/dev/null
        $STDOUT = fopen('/dev/null',"rw+");
        $STDERR = fopen('/dev/null',"rw+");
    }
    
    /**
     * 更新主进程收集的状态信息到共享内存
     * @return bool
     */
    protected static function updateStatusToShm()
    {
        if(!self::$shmId)
        {
            return true;
        }
        return shm_put_var(self::$shmId, self::STATUS_VAR_ID, array_merge(self::$serviceStatusInfo, array('pid_map'=>self::$workerPidMap)));
    }
    
    /**
     * 销毁共享内存以及消息队列
     * @return void
     */
    protected static function removeShmAndQueue()
    {
        if(self::$shmId)
        {
            shm_remove(self::$shmId);
        }
        if(self::$queueId)
        {
            msg_remove_queue(self::$queueId);
        }
    }
    
    /**
     * 设置进程名称，需要proctitle支持 或者php>=5.5
     * @param string $title
     * @return void
     */
    protected static function setProcTitle($title)
    {
        // >=php 5.5
        if (function_exists('cli_set_process_title'))
        {
            @cli_set_process_title($title);
        }
        // 需要扩展
        elseif(extension_loaded('proctitle') && function_exists('setproctitle'))
        {
            @setproctitle($title);
        }
    }
    
    /**
     * 获得socket的上下文选项
     * @param string $worker_name
     * @return resource
     */
    protected static function getSocketContext($worker_name)
    {
        $context = null;
        // 根据手册5.3.3之前版本stream_socket_server 不支持 backlog 选项
        if(version_compare(PHP_VERSION, '5.3.3') < 0)
        {
            return $context;
        }
        // 读取worker的backlog
        $backlog = (int)Lib\Config::get($worker_name . '.backlog');
        // 没有设置或者不合法则尝试使用workerman.conf中的backlog设置
        if($backlog <= 0)
        {
            $backlog = (int)Lib\Config::get('workerman.backlog');
        }
        // 都没设置backlog，使用默认值
        if($backlog <= 0)
        {
            $backlog = self::DEFAULT_BACKLOG;
        }
        // backlog选项
        $opts = array(
            'socket' => array(
                'backlog' => $backlog,
            ),
        );
        // 返回上下文
        $context = stream_context_create($opts);
        return $context;
    }
    
    /**
     * notice,记录到日志
     * @param string $msg
     * @param bool $display
     * @return void
     */
    public static function notice($msg, $display = false)
    {
        Lib\Log::add("Server:".trim($msg));
        if($display)
        {
            if(self::$serviceStatus == self::STATUS_STARTING && @posix_ttyname(STDOUT))
            {
                echo($msg."\n");
            }
        }
    }
}

