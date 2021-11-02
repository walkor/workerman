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
    protected static $_handlerClass = 'Workerman\Protocols\Http\Session\FileSessionHandler';

    /**
     * Parameters of __constructor for session handler class.
     *
     * @var null
     */
    protected static $_handlerConfig = null;

    /**
     * Session.gc_probability
     *
     * @var int
     */
    protected static $_sessionGcProbability = 1;

    /**
     * Session.gc_divisor
     *
     * @var int
     */
    protected static $_sessionGcDivisor = 1000;

    /**
     * Session.gc_maxlifetime
     *
     * @var int
     */
    protected static $_sessionGcMaxLifeTime = 1440;

    /**
     * Session handler instance.
     *
     * @var SessionHandlerInterface
     */
    protected static $_handler = null;

    /**
     * Session data.
     *
     * @var array
     */
    protected $_data = array();

    /**
     * Session changed and need to save.
     *
     * @var bool
     */
    protected $_needSave = false;

    /**
     * Session id.
     *
     * @var null
     */
    protected $_sessionId = null;

    /**
     * Session constructor.
     *
     * @param string $session_id
     */
    public function __construct($session_id)
    {
        static::checkSessionId($session_id);
        if (static::$_handler === null) {
            static::initHandler();
        }
        $this->_sessionId = $session_id;
        if ($data = static::$_handler->read($session_id)) {
            $this->_data = \unserialize($data);
        }
    }

    /**
     * Get session id.
     *
     * @return string
     */
    public function getId()
    {
        return $this->_sessionId;
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
        return isset($this->_data[$name]) ? $this->_data[$name] : $default;
    }

    /**
     * Store data in the session.
     *
     * @param string $name
     * @param mixed $value
     */
    public function set($name, $value)
    {
        $this->_data[$name] = $value;
        $this->_needSave = true;
    }

    /**
     * Delete an item from the session.
     *
     * @param string $name
     */
    public function delete($name)
    {
        unset($this->_data[$name]);
        $this->_needSave = true;
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
     * @param string $key
     * @param mixed|null $value
     */
    public function put($key, $value = null)
    {
        if (!\is_array($key)) {
            $this->set($key, $value);
            return;
        }

        foreach ($key as $k => $v) {
            $this->_data[$k] = $v;
        }
        $this->_needSave = true;
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
                unset($this->_data[$key]);
            }
        }
        $this->_needSave = true;
    }

    /**
     * Retrieve all the data in the session.
     *
     * @return array
     */
    public function all()
    {
        return $this->_data;
    }

    /**
     * Remove all data from the session.
     *
     * @return void
     */
    public function flush()
    {
        $this->_needSave = true;
        $this->_data = array();
    }

    /**
     * Determining If An Item Exists In The Session.
     *
     * @param string $name
     * @return bool
     */
    public function has($name)
    {
        return isset($this->_data[$name]);
    }

    /**
     * To determine if an item is present in the session, even if its value is null.
     *
     * @param string $name
     * @return bool
     */
    public function exists($name)
    {
        return \array_key_exists($name, $this->_data);
    }

    /**
     * Save session to store.
     *
     * @return void
     */
    public function save()
    {
        if ($this->_needSave) {
            if (empty($this->_data)) {
                static::$_handler->destroy($this->_sessionId);
            } else {
                static::$_handler->write($this->_sessionId, \serialize($this->_data));
            }
        }
        $this->_needSave = false;
    }

    /**
     * Refresh session expire time.
     * 
     * @return bool
     */
    public function refresh()
    {
        static::$_handler->updateTimestamp($this->getId());
    }

    /**
     * Init.
     *
     * @return void
     */
    public static function init()
    {
        if ($gc_probability = \ini_get('session.gc_probability')) {
            self::$_sessionGcProbability = (int)$gc_probability;
        }

        if ($gc_divisor = \ini_get('session.gc_divisor')) {
            self::$_sessionGcDivisor = (int)$gc_divisor;
        }

        if ($gc_max_life_time = \ini_get('session.gc_maxlifetime')) {
            self::$_sessionGcMaxLifeTime = (int)$gc_max_life_time;
        }
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
            static::$_handlerClass = $class_name;
        }
        if ($config) {
            static::$_handlerConfig = $config;
        }
        return static::$_handlerClass;
    }

    /**
     * Init handler.
     *
     * @return void
     */
    protected static function initHandler()
    {
        if (static::$_handlerConfig === null) {
            static::$_handler = new static::$_handlerClass();
        } else {
            static::$_handler = new static::$_handlerClass(static::$_handlerConfig);
        }
    }

    /**
     * Try GC sessions.
     *
     * @return void
     */
    public function tryGcSessions()
    {
        if (\rand(1, static::$_sessionGcDivisor) > static::$_sessionGcProbability) {
            return;
        }
        static::$_handler->gc(static::$_sessionGcMaxLifeTime);
    }

    /**
     * __destruct.
     *
     * @return void
     */
    public function __destruct()
    {
        $this->save();
        $this->tryGcSessions();
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