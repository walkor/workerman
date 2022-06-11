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

namespace Workerman\Protocols\Http\Session;

use Workerman\Protocols\Http\Session;

class RedisClusterSessionHandler extends RedisSessionHandler
{
    public function __construct($config)
    {
        $timeout = isset($config['timeout']) ? $config['timeout'] : 2;
        $read_timeout = isset($config['read_timeout']) ? $config['read_timeout'] : $timeout;
        $persistent = isset($config['persistent']) ? $config['persistent'] : false;
        $auth = isset($config['auth']) ? $config['auth'] : '';
        if ($auth) {
            $this->_redis = new \RedisCluster(null, $config['host'], $timeout, $read_timeout, $persistent, $auth);
        } else {
            $this->_redis = new \RedisCluster(null, $config['host'], $timeout, $read_timeout, $persistent);
        }
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }
        $this->_redis->setOption(\Redis::OPT_PREFIX, $config['prefix']);
    }

    /**
     * {@inheritdoc}
     */
    public function read($session_id)
    {
        return $this->_redis->get($session_id);
    }

}
