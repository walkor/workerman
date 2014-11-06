<?php
namespace Man\Core\Lib;
/**
 * 环境检查相关
 * 
* @author walkor <walkor@workerman.net>
 */
class Checker
{
    
    /**
     * 最长的workerName
     * @var integer
     */
    protected static $maxWorkerNameLength = 10;
    
    /**
     * 最长的user name
     * @var integer
     */
    protected static $maxUserNameLength = 10;
    
    /**
     * 最长的listen address
     * @var integer
     */
    protected static $maxListenLength = 10;
    
    /**
     * 最长的process count
     * @var integer
     */
    protected static $maxProcessCountLength = 9;
    
    /**
     * 检查启动worker进程的的用户是否合法
     * @return void
     */
    public static function checkWorkerUserName($worker_user)
    {
        if($worker_user)
        {
            $user_info = posix_getpwnam($worker_user);
            return !empty($user_info);
        }
    }
    
    /**
     * 检查扩展支持情况
     * @return void
     */
    public static function checkExtension()
    {
        // 扩展名=>是否是必须
        $need_map = array(
                'posix'       => true,
                'pcntl'       => true,
                'sockets'   => true,
                'sysvshm'  => false,
                'sysvmsg'   => false,
                'libevent'   => false,
                'proctitle'   => false,
        );
    
        // 检查每个扩展支持情况
        $pad_length = 26;
        foreach($need_map as $ext_name=>$must_required)
        {
            $suport = extension_loaded($ext_name);
            if($must_required && !$suport)
            {
                echo "-----------------------\033[47;30m EXTENSION \033[0m------------------------------\n";
                \Man\Core\Master::notice($ext_name. " [NOT SUPORT BUT REQUIRED] \tYou have to enable {$ext_name} \tWorkerman start fail");
                exit(str_pad('* ' . $ext_name, $pad_length) . " \033[31;40m [NOT SUPORT BUT REQUIRED] \033[0m\n\n\033[31;40mYou have to enable {$ext_name} \033[0m\n\n\033[31;40mWorkerman start fail\033[0m\n\n");
            }
    
            // 不支持
            if(!$suport)
            {
                // proctitle不是必须
                if('proctitle' == $ext_name)
                {
                    continue;
                }
                if(!isset($had_print_ext_info))
                {
                    $had_print_ext_info = '';
                    echo "-----------------------\033[47;30m EXTENSION \033[0m------------------------------\n";
                }
                echo '* ' , str_pad($ext_name, $pad_length), "\033[33;40m [NOT SUPORT] \033[0m\n";
            }
        }
    }
    
    /**
     * 检查禁用的函数
     * @return void
     */
    public static function checkDisableFunction()
    {
        // 可能禁用的函数
        $check_func_map = array(
                'stream_socket_server',
                'stream_socket_client',
                'pcntl_signal_dispatch',
        );
        // 获取php.ini中设置的禁用函数
        if($disable_func_string = ini_get("disable_functions"))
        {
            $disable_func_map = array_flip(explode(',', $disable_func_string));
        }
        // 遍历查看是否有禁用的函数
        foreach($check_func_map as $func)
        {
            if(isset($disable_func_map[$func]))
            {
                \Man\Core\Master::notice("Function $func may be disabled\tPlease check disable_functions in php.ini \t Workerman start fail");
                exit("\n\033[31;40mFunction $func may be disabled\nPlease check disable_functions in php.ini\033[0m\n\n\033[31;40mWorkerman start fail\033[0m\n\n");
            }
        }
    }
    
    /**
     * 检查worker配置、worker语法错误等
     * @return void
     */
    public static function checkWorkersConfig()
    {
        $current_pwuid = posix_getpwuid(posix_getuid());
        $current_user_name = $current_pwuid['name'];
        self::$maxUserNameLength = strlen($current_user_name);
        
        foreach(Config::getAllWorkers() as $worker_name=>$config)
        {
            if(strlen($worker_name)>self::$maxWorkerNameLength)
            {
                self::$maxWorkerNameLength = strlen($worker_name);
            }
            if(isset($config['user']) && strlen($config['user']) > self::$maxUserNameLength)
            {
                self::$maxUserNameLength = strlen($config['user']);
            }
            if(isset($config['listen']) && strlen($config['listen']) > self::$maxListenLength)
            {
               self::$maxListenLength = strlen($config['listen']);
            }
        }
        $total_worker_count = 0;
        // 检查worker 是否有语法错误
         echo "------------------------\033[47;30m WORKERS \033[0m-------------------------------\n";
        echo "\033[47;30muser\033[0m",str_pad('', self::$maxUserNameLength+2-strlen('user')), "\033[47;30mworker\033[0m",str_pad('', self::$maxWorkerNameLength+2-strlen('worker')), "\033[47;30mlisten\033[0m",str_pad('', self::$maxListenLength+2-strlen('listen')), "\033[47;30mprocesses\033[0m",str_pad('', self::$maxProcessCountLength+2-strlen('processes')),"\033[47;30m","status\033[0m\n";
        foreach (Config::getAllWorkers() as $worker_name=>$config)
        {
            if(isset($config['user']))
            {
                $worker_user = $config['user'];
                if(!self::checkWorkerUserName($worker_user))
                {
                    echo str_pad($config['user'], self::$maxUserNameLength+2),str_pad($worker_name, self::$maxWorkerNameLength+2),"\033[31;40m [FAIL] \033[0m\n";
                    \Man\Core\Master::notice("Can not run $worker_name processes as user $worker_user , User $worker_user not exists\tWorkerman start fail");
                    exit("\n\033[31;40mCan not run $worker_name processes as user $worker_user , User $worker_user not exists\033[0m\n\n\033[31;40mWorkerman start fail\033[0m\n\n");
                }
            }
            else
            {
                $worker_user = $current_user_name;
            }
            
            echo str_pad($worker_user, self::$maxUserNameLength+2),str_pad($worker_name, self::$maxWorkerNameLength+2);
            
            if(isset($config['listen']))
            {
                echo str_pad($config['listen'], self::$maxListenLength+2);
            }
            else 
            {
                echo str_pad('none', self::$maxListenLength+2);
            }
            
            if(empty($config['start_workers']))
            {
                \Man\Core\Master::notice(str_pad($worker_name, 40)." [start_workers not set]\tWorkerman start fail");
                exit(str_pad('', self::$maxProcessCountLength+2)."\033[31;40m [start_workers not set]\033[0m\n\n\033[31;40mWorkerman start fail\033[0m\n");
            }
            
            echo str_pad(' '.$config['start_workers'], self::$maxProcessCountLength+2);
    
            $total_worker_count += $config['start_workers'];
    
            // 语法检查
            if($worker_file = \Man\Core\Lib\Config::get($worker_name.'.worker_file'))
            {
                $class_name = basename($worker_file, '.php');
            }
            else
            {
                \Man\Core\Master::notice("$worker_name not set worker_file in conf/conf.d/$worker_name.conf");
                echo"\033[31;40m [not set worker_file] \033[0m\n";
                continue;
            }
            if(0 != self::checkSyntaxError($worker_file, $class_name))
            {
                //unset(Config::instance()->config[$worker_name]);
                \Man\Core\Master::notice("$worker_name has Fatal Err");
                echo"\033[31;40m [Fatal Err] \033[0m\n";
                continue;
            }
            echo "\033[32;40m [OK] \033[0m\n";
        }
    
        if($total_worker_count > \Man\Core\Master::SERVER_MAX_WORKER_COUNT)
        {
            \Man\Core\Master::notice("Number of worker processes can not be greater than " . \Man\Core\Master::SERVER_MAX_WORKER_COUNT . ".\tPlease check start_workers in " . WORKERMAN_ROOT_DIR . "config/main.php\tWorkerman start fail");
            exit("\n\033[31;40mNumber of worker processes can not be greater than " . \Man\Core\Master::SERVER_MAX_WORKER_COUNT . ".\nPlease check start_workers in " . WORKERMAN_ROOT_DIR . "config/main.php\033[0m\n\n\033[31;40mWorkerman start fail\033[0m\n");
        }
    
        echo "----------------------------------------------------------------\n";
    }
    
    /**
     * 检查worker文件是否有语法错误
     * @param string $worker_name
     * @return int 0：无语法错误 其它:可能有语法错误
     */
    public static function checkSyntaxError($file, $class_name = null)
    {
        $pid = pcntl_fork();
        // 父进程
        if($pid > 0)
        {
            // 退出状态不为0说明可能有语法错误
            $pid = pcntl_wait($status);
            return $status;
        }
        // 子进程
        elseif($pid == 0)
        {
            ini_set('display_errors', 'Off');
            require_once WORKERMAN_ROOT_DIR . 'Core/SocketWorker.php';
            // 载入对应worker
            require_once $file;
            if($class_name && !class_exists($class_name))
            {
                throw new \Exception("Class $class_name not exists");
            }
            exit(0);
        }
    }
    
    /**
     * 检查打开文件限制
     * @return void
     */
    public static function checkLimit()
    {
        if($limit_info = posix_getrlimit())
        {
            if('unlimited' != $limit_info['soft openfiles'] && $limit_info['soft openfiles'] < \Man\Core\Master::MIN_SOFT_OPEN_FILES)
            {
                echo "Notice : Soft open files now is {$limit_info['soft openfiles']},  We recommend greater than " . \Man\Core\Master::MIN_SOFT_OPEN_FILES . "\n";
            }
            if('unlimited' != $limit_info['hard filesize'] && $limit_info['hard filesize'] < \Man\Core\Master::MIN_SOFT_OPEN_FILES)
            {
                echo "Notice : Hard open files now is {$limit_info['hard filesize']},  We recommend greater than " . \Man\Core\Master::MIN_HARD_OPEN_FILES . "\n";
            }
        }
    }
    
    /**
     * 检查配置的pid文件是否可写
     * @return void
     */
    public static function checkPidFile()
    {
        // 已经有进程pid可能server已经启动
        if(@file_get_contents(WORKERMAN_PID_FILE))
        {
            \Man\Core\Master::notice("\033[33;40mWorkerman already started\033[0m", true);
            exit;
        }
        
        if(is_dir(WORKERMAN_PID_FILE))
        {
            exit("\n\033[31;40mpid-file ".WORKERMAN_PID_FILE." is Directory\033[0m\n\n\033[31;40mWorkerman start failed\033[0m\n\n");
        }
        
        $pid_dir = dirname(WORKERMAN_PID_FILE);
        if(!is_dir($pid_dir))
        {
            if(!mkdir($pid_dir, 0777, true))
            {
                exit("Create dir $pid_dir fail\n");
            }
        }
        
        if(!is_writeable($pid_dir))
        {
            exit("\n\033[31;40m$pid_dir is not writeable, can't write pid file\033[0m\n\n\033[31;40mWorkerman start failed\033[0m\n\n");
        }
    }
}
