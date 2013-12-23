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
        return App\Common\Protocols\http_deal_input($recv_str); 
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
        App\Common\Protocols\http_response_begin();
        App\Common\Protocols\http_requset_parse($recv_str);
        App\Common\Protocols\session_start();
        ob_start();
        echo 'cookie';var_export($_COOKIE);
        echo 'session';var_export($_SESSION);
        $_SESSION['abc'] = 1333;
        $_SESSION['ddd'] = array('a'=>2,3=>0);
        $content = ob_get_clean();
        App\Common\Protocols\http_response_finish();
        $buffer = App\Common\Protocols\http_encode($content);
        $this->sendToClient($buffer);
    }
}
