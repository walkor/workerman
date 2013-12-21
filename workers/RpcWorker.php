<?php
require_once WORKERMAN_ROOT_DIR . 'man/Core/SocketWorker.php';
require_once WORKERMAN_ROOT_DIR . 'applications/Common/Protocols/RpcProtocol.php';

/**
 * 
 *  RpcWorker，Rpc服务的入口文件
 *  根据客户端传递参数调用 applications/Rpc/Services/目录下的文件的类的方法
 *  
 * @author walkor <worker-man@qq.com>
 */
class RpcWorker extends Man\Core\SocketWorker
{
    /**
     * 确定数据是否接收完整
     * @see Man\Core.SocketWorker::dealInput()
     */
    public function dealInput($recv_str)
    {
        return RpcProtocol::dealInput($recv_str); 
    }

    /**
     * 数据接收完整后处理业务逻辑
     * @see Man\Core.SocketWorker::dealProcess()
     */
    public function dealProcess($recv_str)
    {
        /**
         * data的数据格式为
         * ['class'=>xx, 'method'=>xx, 'param_array'=>array(xx)]
         * @var array
         */
        $data = RpcProtocol::decode($recv_str);
        // 判断数据是否正确
        if(empty($data['class']) || empty($data['method']) || !isset($data['param_array']))
        {
            // 发送数据给客户端，请求包错误
            return $this->sendToClient(RpcProtocol::encode(array('code'=>400, 'msg'=>'bad request', 'data'=>null)));
        }
        // 获得要调用的类、方法、及参数
        $class = $data['class'];
        $method = $data['method'];
        $param_array = $data['param_array'];
        
        // 判断类对应文件是否载入
        if(!class_exists($class))
        {
            $include_file = WORKERMAN_ROOT_DIR . "applications/Rpc/Services/$class.php";
            if(!is_file($include_file))
            {
                // 发送数据给客户端 类不存在
                return $this->sendToClient(RpcProtocol::encode(array('code'=>404, 'msg'=>'class not found', 'data'=>null)));
            }
            require_once $include_file;
        }
        
        // 调用类的方法
        try 
        {
            $ret = call_user_func_array(array($class, $method), $param_array);
            // 发送数据给客户端，调用成功，data下标对应的元素即为调用结果
            return $this->sendToClient(RpcProtocol::encode(array('code'=>0, 'msg'=>'ok', 'data'=>$ret)));
        }
        // 有异常
        catch(Exception $e)
        {
            // 发送数据给客户端，发生异常，调用失败
            return $this->sendToClient(RpcProtocol::encode(array('code'=>500, 'msg'=>$e->getMessage(), 'data'=>$e)));
        }
    }
}
