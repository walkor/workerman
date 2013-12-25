<?php
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'applications/Common/Protocols/Http.php';

/**
 * 
 *  WorkerMan 管理后台
 *  HTTP协议
 *  
 * @author walkor <worker-man@qq.com>
 */
class WorkerManAdmin extends Man\Core\SocketWorker
{
    /**
     * 资源类型
     * @var array
     */
    protected static $typeMap = array(
            'js'     => 'text/javascript',
            'png' => 'image/png',
            'jpg'  => 'image/jpeg',
            'gif'   => 'image/gif',
            'png' => 'image/png',
            'css'   => 'text/css',
        );
    
    public function onStart()
    {
        App\Common\Protocols\HttpCache::init();
    }

    /**
     * 确定数据是否接收完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return App\Common\Protocols\http_input($recv_str); 
    }

    /**
     * 数据接收完整后处理业务逻辑
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
         // http请求处理开始。解析http协议，生成$_POST $_GET $_COOKIE
        App\Common\Protocols\http_start($recv_str);
        // 开启session
        App\Common\Protocols\session_start();
        // 缓冲输出
        ob_start();
        // 请求的文件
        $file = $_SERVER['REQUEST_URI'];
        $pos = strpos($file, '?');
        if($pos !== false)
        {
            // 去掉文件名后面的querystring
            $file = substr($_SERVER['REQUEST_URI'], 0, $pos);
        }
        // 得到文件真实路径
        $file = WORKERMAN_ROOT_DIR . 'applications/WorkerManAdmin/'.$file;
        if(!is_file($file))
        {
            // 从定向到index.php
            $file = WORKERMAN_ROOT_DIR . 'applications/WorkerManAdmin/index.php';
        }
        // 请求的文件存在
        if(is_file($file))
        {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            // 如果请求的是php文件
            if($extension == 'php')
            {
                // 载入php文件
                try 
                {
                    include $file;
                }
                catch(\Exception $e) 
                {
                    // 如果不是exit
                    if($e->getMessage() != 'jump_exit')
                    {
                        echo $e;
                    }
                }
            }
            // 请求的是静态资源文件
            else
            {
                if(isset(self::$typeMap[$extension]))
                {
                    App\Common\Protocols\header('Content-Type: '. self::$typeMap[$extension]);
                }
                // 发送文件
                readfile($file);
            }
        }
        else 
        {
            echo 'index.php missed';
        }
        $content = ob_get_clean();
        $buffer = App\Common\Protocols\http_end($content);
        $this->sendToClient($buffer);
    }
}
