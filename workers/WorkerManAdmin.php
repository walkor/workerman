<?php
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'applications/Common/Protocols/Http.php';
require_once WORKERMAN_ROOT_DIR . 'applications/Common/Protocols/Session.php';

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
     * 确定数据是否接收完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return App\Common\Protocols\Http::dealInput($recv_str); 
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
        App\Common\Protocols\Http::decode($recv_str);
        
        var_dump($_GET,$_POST,$_COOKIE);
        $this->sendToClient(App\Common\Protocols\Http::encode(var_export($_COOKIE, true)));
    }
}
