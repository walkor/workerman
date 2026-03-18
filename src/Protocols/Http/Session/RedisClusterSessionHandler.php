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

declare(strict_types=1);

namespace Workerman\Protocols\Http\Session;

use Redis;
use RedisCluster;
use RedisClusterException;
use RedisException;

class RedisClusterSessionHandler extends RedisSessionHandler
{
    /**
     * @param array $config
     * @throws RedisClusterException
     * @throws RedisException
     */
    public function __construct(array $config)
    {
        parent::__construct($config);
    }

    /**
     * Create redis connection.
     * @param array $config
     * @return Redis|RedisCluster
     * @throws RedisClusterException
     */
    protected function createRedisConnection(array $config): Redis|RedisCluster
    {
        $timeout = $config['timeout'] ?? 2;
        $readTimeout = $config['read_timeout'] ?? $timeout;
        $persistent = $config['persistent'] ?? false;
        $auth = $config['auth'] ?? '';
        $args = [null, $config['host'], $timeout, $readTimeout, $persistent];
        if ($auth) {
            $args[] = $auth;
        }
        $redis = new RedisCluster(...$args);
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }
        $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
        return $redis;
    }
}
