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

namespace Workerman\Protocols\Http;

use Workerman\Protocols\Http\Session\FileSessionHandler;
use Workerman\Protocols\Http\Session\SessionHandlerInterface;

/**
 * Class Session
 * @package Workerman\Protocols\Http
 */
class Session
{
    /**
     * Session andler class which implements SessionHandlerInterface.
     *
     * @var string
     */
    protected static $handlerClass = FileSessionHandler::class;

    /**
     * Parameters of __constructor for session handler class.
     *
     * @var null
     */
    protected static $handlerConfig = null;

    /**
     * Session name.
     *
     * @var string
     */
    public static $name = 'PHPSID';

    /**
     * Auto update timestamp.
     *
     * @var bool
     */
    public static $autoUpdateTimestamp = false;

    /**
     * Session lifetime.
     *
     * @var int
     */
    public static $lifetime = 1440;

    /**
     * Cookie lifetime.
     *
     * @var int
     */
    public static $cookieLifetime = 1440;

    /**
     * Session cookie path.
     *
     * @var string
     */
    public static $cookiePath = '/';

    /**
     * Session cookie domain.
     *
     * @var string
     */
    public static $domain = '';

    /**
     * HTTPS only cookies.
     *
     * @var bool
     */
    public static $secure = false;

    /**
     * HTTP access only.
     *
     * @var bool
     */
    public static $httpOnly = true;

    /**
     * Same-site cookies.
     *
     * @var string
     */
    public static $sameSite = '';

    /**
     * Gc probability.
     *
     * @var int[]
     */
    public static $gcProbability = [1, 1000];

    /**
     * Session handler instance.
     *
     * @var SessionHandlerInterface
     */
    protected static $handler = null;

    /**
     * Session data.
     *
     * @var array
     */
    protected $data = [];

    /**
     * Session changed and need to save.
     *
     * @var bool
     */
    protected $needSave = false;

    /**
     * Session id.
     *
     * @var null
     */
    protected $sessionId = null;

    /**
     * Session constructor.
     *
     * @param string $session_id
     */
    public function __construct($session_id)
    {
        static::checkSessionId($session_id);
        if (static::$handler === null) {
            static::initHandler();
        }
        $this->sessionId = $session_id;
        if ($data = static::$handler->read($session_id)) {
            $this->data = \unserialize($data);
        }
    }

    /**
     * Get session id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->sessionId;
    }

    /**
     * Get session.
     *
     * @param string $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function get($name, $default = null)
    {
        return $this->data[$name] ?? $default;
    }

    /**
     * Store data in the session.
     *
     * @param string $name
     * @param mixed $value
     */
    public function set($name, $value)
    {
        $this->data[$name] = $value;
        $this->needSave = true;
    }

    /**
     * Delete an item from the session.
     *
     * @param string $name
     */
    public function delete($name)
    {
        unset($this->data[$name]);
        $this->needSave = true;
    }

    /**
     * Retrieve and delete an item from the session.
     *
     * @param string $name
     * @param mixed|null $default
     * @return mixed|null
     */
    public function pull($name, $default = null)
    {
        $value = $this->get($name, $default);
        $this->delete($name);
        return $value;
    }

    /**
     * Store data in the session.
     *
     * @param string|array $key
     * @param mixed|null $value
     */
    public function put($key, $value = null)
    {
        if (!\is_array($key)) {
            $this->set($key, $value);
            return;
        }

        foreach ($key as $k => $v) {
            $this->data[$k] = $v;
        }
        $this->needSave = true;
    }

    /**
     * Remove a piece of data from the session.
     *
     * @param string $name
     */
    public function forget($name)
    {
        if (\is_scalar($name)) {
            $this->delete($name);
            return;
        }
        if (\is_array($name)) {
            foreach ($name as $key) {
                unset($this->data[$key]);
            }
        }
        $this->needSave = true;
    }

    /**
     * Retrieve all the data in the session.
     *
     * @return array
     */
    public function all()
    {
        return $this->data;
    }

    /**
     * Remove all data from the session.
     *
     * @return void
     */
    public function flush()
    {
        $this->needSave = true;
        $this->data = [];
    }

    /**
     * Determining If An Item Exists In The Session.
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->data[$name]);
    }

    /**
     * To determine if an item is present in the session, even if its value is null.
     *
     * @param string $name
     * @return bool
     */
    public function exists($name)
    {
        return \array_key_exists($name, $this->data);
    }

    /**
     * Save session to store.
     *
     * @return void
     */
    public function save()
    {
        if ($this->needSave) {
            if (empty($this->data)) {
                static::$handler->destroy($this->sessionId);
            } else {
                static::$handler->write($this->sessionId, \serialize($this->data));
            }
        } elseif (static::$autoUpdateTimestamp) {
            static::refresh();
        }
        $this->needSave = false;
    }

    /**
     * Refresh session expire time.
     *
     * @return bool
     */
    public function refresh()
    {
        return static::$handler->updateTimestamp($this->getId());
    }

    /**
     * Init.
     *
     * @return void
     */
    public static function init()
    {
        if (($gc_probability = (int)\ini_get('session.gc_probability')) && ($gc_divisor = (int)\ini_get('session.gc_divisor'))) {
            static::$gcProbability = [$gc_probability, $gc_divisor];
        }

        if ($gc_max_life_time = \ini_get('session.gc_maxlifetime')) {
            self::$lifetime = (int)$gc_max_life_time;
        }

        $session_cookie_params = \session_get_cookie_params();
        static::$cookieLifetime = $session_cookie_params['lifetime'];
        static::$cookiePath = $session_cookie_params['path'];
        static::$domain = $session_cookie_params['domain'];
        static::$secure = $session_cookie_params['secure'];
        static::$httpOnly = $session_cookie_params['httponly'];
    }

    /**
     * Set session handler class.
     *
     * @param mixed|null $class_name
     * @param mixed|null $config
     * @return string
     */
    public static function handlerClass($class_name = null, $config = null)
    {
        if ($class_name) {
            static::$handlerClass = $class_name;
        }
        if ($config) {
            static::$handlerConfig = $config;
        }
        return static::$handlerClass;
    }

    /**
     * Get cookie params.
     *
     * @return array
     */
    public static function getCookieParams()
    {
        return [
            'lifetime' => static::$cookieLifetime,
            'path' => static::$cookiePath,
            'domain' => static::$domain,
            'secure' => static::$secure,
            'httponly' => static::$httpOnly,
            'samesite' => static::$sameSite,
        ];
    }

    /**
     * Init handler.
     *
     * @return void
     */
    protected static function initHandler()
    {
        if (static::$handlerConfig === null) {
            static::$handler = new static::$handlerClass();
        } else {
            static::$handler = new static::$handlerClass(static::$handlerConfig);
        }
    }

    /**
     * GC sessions.
     *
     * @return void
     */
    public function gc()
    {
        static::$handler->gc(static::$lifetime);
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->save();
        if (\random_int(1, static::$gcProbability[1]) <= static::$gcProbability[0]) {
            $this->gc();
        }
    }

    /**
     * Check session id.
     *
     * @param string $session_id
     */
    protected static function checkSessionId($session_id)
    {
        if (!\preg_match('/^[a-zA-Z0-9]+$/', $session_id)) {
            throw new SessionException("session_id $session_id is invalid");
        }
    }
}

/**
 * Class SessionException
 * @package Workerman\Protocols\Http
 */
class SessionException extends \RuntimeException
{

}

// Init session.
Session::init();
