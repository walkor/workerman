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
        /**
         * 解析http协议，生成$_POST $_GET $_COOKIE
         */
        App\Common\Protocols\http_start($recv_str);
        //App\Common\Protocols\session_start();
        ob_start();
        $pos = strpos($_SERVER['REQUEST_URI'], '?');
        $file = $_SERVER['REQUEST_URI'];
        if($pos !== false)
        {
            $file = substr($_SERVER['REQUEST_URI'], 0, $pos);
        }
        $file = WORKERMAN_ROOT_DIR . 'applications/Wordpress/'.$file;
        if(!is_file($file))
        {
            $file = WORKERMAN_ROOT_DIR . 'applications/Wordpress/index.php';
        }
        if(is_file($file))
        {
            $extension = pathinfo($file, PATHINFO_EXTENSION);
            if($extension == 'php')
            {
                include $file;
            }
            else
            {
                if(isset(self::$typeMap[$extension]))
                {
                    App\Common\Protocols\header('Content-Type: '. self::$typeMap[$extension]);
                }
                echo file_get_contents($file);
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
