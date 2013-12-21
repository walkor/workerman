<?php
/**
 * 
 *  RpcClient Rpc客户端
 * @author walkor <worker-man@qq.com>
 */
class RpcClient
{
    const ASYNC_SEND_PREFIX = 'asend_';
    
    const ASYNC_RECV_PREFIX = 'arecv_';
    
    protected static $addressArray = array();
    
    protected static $asyncInstances = array();
    
    protected static $instances = array();
    
    protected  $connection = null;
    
    protected $serviceName = '';
    
    public static function config($address_array = array())
    {
        if(!empty($address_array))
        {
            self::$addressArray = $address_array;
        }
        return self::$addressArray;
    }
    
    public static function instance($service_name)
    {
        if(!isset(self::$instances[$service_name]))
        {
            self::$instances[$service_name] = new self($service_name);
        }
        return self::$instances[$service_name];
    }
    
    protected function __construct($service_name)
    {
        $this->serviceName = $service_name;
    }
    
    public function __call($method, $arguments)
    {
        // 判断是否是异步发送
        if(0 === str_pos($method, self::ASYNC_SEND_PREFIX))
        {
            $real_method = substr($method, strlen(self::ASYNC_SEND_PREFIX));
            $instance_key = $real_method . serialize($arguments);
            if(isset(self::$asyncInstances[$instance_key]))
            {
                throw new Exception($this->serviceName . "->$method(".implode(',', $arguments).") have already been called");
            }
            self::$asyncInstances[$instance_key] = new self($this->serviceName);
            return self::$asyncInstances[$instance_key]->sendData($real_method, $arguments);
        }
        if(0 === str_pos($method, self::ASYNC_RECV_PREFIX))
        {
            $real_method = substr($method, strlen(self::ASYNC_RECV_PREFIX));
            $instance_key = $real_method . serialize($arguments);
            if(!isset(self::$asyncInstances[$instance_key]))
            {
                throw new Exception($this->serviceName . "->arecv_$real_method(".implode(',', $arguments).") have not been called");
            }
            return self::$asyncInstances[$instance_key]->recvData($real_method, $arguments);
        }
    }
    
    public function sendData($method, $arguments)
    {
        $this->openConnection();
        $bin_data = RpcProtocol::encode(array(
                'class'              => $this->serviceName,
                'method'         => $method,
                'param_array'  => $arguments,
                ));
        return fwrite($this->connection, $bin_data);
    }
    
    public function recvData($method, $arguments)
    {
        $ret = fgets($this->connection);
        $this->closeConnection();
        if(!$ret)
        {
            throw new Exception("recvData empty");
        }
    }
    
    protected function openConnection()
    {
        $address = self::$addressArray[array_rand(self::$addressArray)];
        $this->connection = stream_socket_client($address, $err_no, $err_msg);
    }
    
    protected function closeConnection()
    {
        fclose($this->connection);
    }
}

/**
 * RPC 协议解析 相关
 * 协议格式为 [json字符串\n]
 * @author walkor <worker-man@qq.com>
 * */
class RpcProtocol
{
    /**
     * 从socket缓冲区中预读长度
     * @var integer
     */
    const PRREAD_LENGTH = 87380;

    /**
     * 判断数据包是否接收完整
     * @param string $bin_data
     * @param mixed $data
     * @return integer 0代表接收完毕，大于0代表还要接收数据
     */
    public static function dealInput($bin_data)
    {
        $bin_data_length = strlen($bin_data);
        // 判断最后一个字符是否为\n，\n代表一个数据包的结束
        if($bin_data[$bin_data_length-1] !="\n")
        {
            // 再读
            return self::PRREAD_LENGTH;
        }
        return 0;
    }

    /**
     * 将数据打包成Rpc协议数据
     * @param mixed $data
     * @return string
     */
    public static function encode($data)
    {
        return json_encode($data)."\n";
    }

    /**
     * 解析Rpc协议数据
     * @param string $bin_data
     * @return mixed
     */
    public static function decode($bin_data)
    {
        return json_decode(trim($bin_data), true);
    }
}

if(false)
{
    $address_array = array(
            'tcp://127.0.0.1:2015',
            'tcp://127.0.0.1:2015'
            );
    RpcClient::config($address_array);
    var_export(RpcClient::instance('User')->getInfoByUid(123));
}
