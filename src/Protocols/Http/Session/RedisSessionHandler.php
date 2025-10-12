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
use RedisException;
use RuntimeException;
use Throwable;
use Workerman\Coroutine\Utils\DestructionWatcher;
use Workerman\Events\Fiber;
use Workerman\Protocols\Http\Session;
use Workerman\Timer;
use Workerman\Coroutine\Pool;
use Workerman\Coroutine\Context;
use Workerman\Worker;

/**
 * Class RedisSessionHandler
 * @package Workerman\Protocols\Http\Session
 */
class RedisSessionHandler implements SessionHandlerInterface
{
    /**
     * @var Redis|RedisCluster
     */
    protected Redis|RedisCluster|null $connection = null;

    /**
     * @var array
     */
    protected array $config;

    /**
     * @var Pool|null
     */
    protected static ?Pool $pool = null;

    /**
     * RedisSessionHandler constructor.
     * @param array $config = [
     *  'host'     => '127.0.0.1',
     *  'port'     => 6379,
     *  'timeout'  => 2,
     *  'auth'     => '******',
     *  'database' => 2,
     *  'prefix'   => 'redis_session_',
     *  'ping'     => 55,
     * ]
     * @throws RedisException
     */
    public function __construct(array $config)
    {
        if (false === extension_loaded('redis')) {
            throw new RuntimeException('Please install redis extension.');
        }

        $config['timeout'] ??= 2;
        $this->config = $config;
    }

    /**
     * Get connection.
     * @return Redis
     * @throws Throwable
     */
    protected function connection(): Redis|RedisCluster
    {
        // Cannot switch fibers in current execution context when PHP < 8.4
        if (Worker::$eventLoopClass === Fiber::class && PHP_VERSION_ID < 80400) {
            if (!$this->connection) {
                $this->connection = $this->createRedisConnection($this->config);
                Timer::delay($this->config['pool']['heartbeat_interval'] ?? 55, function () {
                    $this->connection->ping();
                });
            }
            return $this->connection;
        }
        
        $key = 'session.redis.connection';
        /** @var Redis|null $connection */
        $connection = Context::get($key);
        if (!$connection) {
            if (!static::$pool) {
                $poolConfig = $this->config['pool'] ?? [];
                static::$pool = new Pool($poolConfig['max_connections'] ?? 10, $poolConfig);
                static::$pool->setConnectionCreator(function () {
                    return $this->createRedisConnection($this->config);
                });
                static::$pool->setConnectionCloser(function (Redis|RedisCluster $connection) {
                    $connection->close();
                });
                static::$pool->setHeartbeatChecker(function (Redis|RedisCluster $connection) {
                    $connection->ping();
                });
            }
            try {
                $connection = static::$pool->get();
                Context::set($key, $connection);
            } finally {
                $closure = function () use ($connection) {
                    try {
                        $connection && static::$pool && static::$pool->put($connection);
                    } catch (Throwable) {
                        // ignore
                    }
                };
                $obj = Context::get('context.onDestroy');
                if (!$obj) {
                    $obj = new \stdClass();
                    Context::set('context.onDestroy', $obj);
                }
                DestructionWatcher::watch($obj, $closure);
            }
        }
        return $connection;
    }

    /**
     * Create redis connection.
     * @param array $config
     * @return Redis
     */
    protected function createRedisConnection(array $config): Redis|RedisCluster
    {
        $redis = new Redis();
        if (false === $redis->connect($config['host'], $config['port'], $config['timeout'])) {
            throw new RuntimeException("Redis connect {$config['host']}:{$config['port']} fail.");
        }
        if (!empty($config['auth'])) {
            $redis->auth($config['auth']);
        }
        if (!empty($config['database'])) {
            $redis->select((int)$config['database']);
        }
        if (empty($config['prefix'])) {
            $config['prefix'] = 'redis_session_';
        }
        $redis->setOption(Redis::OPT_PREFIX, $config['prefix']);
        return $redis;
    }

    /**
     * {@inheritdoc}
     */
    public function open(string $savePath, string $name): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     * @param string $sessionId
     * @return string|false
     * @throws RedisException
     * @throws Throwable
     */
    public function read(string $sessionId): string|false
    {
        return $this->connection()->get($sessionId);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        return true === $this->connection()->setex($sessionId, Session::$lifetime, $sessionData);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        return true === $this->connection()->expire($sessionId, Session::$lifetime);
    }

    /**
     * {@inheritdoc}
     * @throws RedisException
     */
    public function destroy(string $sessionId): bool
    {
        $this->connection()->del($sessionId);
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function close(): bool
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): bool
    {
        return true;
    }
}
