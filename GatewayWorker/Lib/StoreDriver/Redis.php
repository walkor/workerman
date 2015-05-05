<?php
namespace GatewayWorker\Lib\StoreDriver;

/**
 * 
 * Redis
 */

class Redis extends \Redis
{
    public function increment($key)
    {
        return parent::incr($key);
    }
}
