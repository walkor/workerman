<?php

/**
 * This file is part of workerman.
 *
 * Licensed under The MIT License
 * For full copyright and license information, please see the MIT-LICENSE.txt
 * Redistributions of files must retain the above copyright notice.
 *
 * @author    walkor<walkor@workerman.net>
 * @copyright walkor<walkor@workerman.net>
 * @link      http://www.workerman.net/
 * @license   http://www.opensource.org/licenses/mit-license.php MIT License
 */
namespace Workerman\Connection\Traits;

trait SerializeTrait
{
    public function jsonSerialize()
    {
        $data = [];
        if ($this->transport === 'tcp') {
            $data = [
                'id' => $this->id,
                'status' => $this->getStatus(),
            ];
        }
        
        return $data + [
            'transport' => $this->transport,
            'getRemoteIp' => $this->getRemoteIp(),
            'remotePort' => $this->getRemotePort(),
            'getRemoteAddress' => $this->getRemoteAddress(),
            'getLocalIp' => $this->getLocalIp(),
            'getLocalPort' => $this->getLocalPort(),
            'getLocalAddress' => $this->getLocalAddress(),
            'isIpV4' => $this->isIpV4(),
            'isIpV6' => $this->isIpV6(),
        ];
    }

    public function serialize()
    {
        return serialize($this->jsonSerialize());
    }

    public function unserialize(string $data)
    {
        // 仅仅打印信息，不做操作，进程数据不可进行改变
        var_export(sprintf("unserialize %s \n", get_class($this)));
        var_export(unserialize($data));
    }
}
