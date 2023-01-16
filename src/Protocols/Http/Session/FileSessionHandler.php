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

/**
 * Class FileSessionHandler
 * @package Workerman\Protocols\Http\Session
 */
class FileSessionHandler implements SessionHandlerInterface
{
    /**
     * Session save path.
     *
     * @var string
     */
    protected static $sessionSavePath = null;

    /**
     * Session file prefix.
     *
     * @var string
     */
    protected static $sessionFilePrefix = 'session_';

    /**
     * Init.
     */
    public static function init()
    {
        $savePath = @\session_save_path();
        if (!$savePath || \strpos($savePath, 'tcp://') === 0) {
            $savePath = \sys_get_temp_dir();
        }
        static::sessionSavePath($savePath);
    }

    /**
     * FileSessionHandler constructor.
     * @param array $config
     */
    public function __construct($config = [])
    {
        if (isset($config['save_path'])) {
            static::sessionSavePath($config['save_path']);
        }
    }

    /**
     * {@inheritdoc}
     */
    public function open($savePath, $name)
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function read($sessionId)
    {
        $sessionFile = static::sessionFile($sessionId);
        \clearstatcache();
        if (\is_file($sessionFile)) {
            if (\time() - \filemtime($sessionFile) > Session::$lifetime) {
                \unlink($sessionFile);
                return '';
            }
            $data = \file_get_contents($sessionFile);
            return $data ?: '';
        }
        return '';
    }

    /**
     * {@inheritdoc}
     */
    public function write($sessionId, $sessionData)
    {
        $tempFile = static::$sessionSavePath . uniqid(bin2hex(random_bytes(8)), true);
        if (!\file_put_contents($tempFile, $sessionData)) {
            return false;
        }
        return \rename($tempFile, static::sessionFile($sessionId));
    }

    /**
     * Update sesstion modify time.
     *
     * @see https://www.php.net/manual/en/class.sessionupdatetimestamphandlerinterface.php
     * @see https://www.php.net/manual/zh/function.touch.php
     *
     * @param string $id Session id.
     * @param string $data Session Data.
     *
     * @return bool
     */
    public function updateTimestamp($id, $data = "")
    {
        $sessionFile = static::sessionFile($id);
        if (!file_exists($sessionFile)) {
            return false;
        }
        // set file modify time to current time
        $setModifyTime = \touch($sessionFile);
        // clear file stat cache
        \clearstatcache();
        return $setModifyTime;
    }

    /**
     * {@inheritdoc}
     */
    public function close()
    {
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function destroy($sessionId)
    {
        $sessionFile = static::sessionFile($sessionId);
        if (\is_file($sessionFile)) {
            \unlink($sessionFile);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc($maxlifetime)
    {
        $timeNow = \time();
        foreach (\glob(static::$sessionSavePath . static::$sessionFilePrefix . '*') as $file) {
            if (\is_file($file) && $timeNow - \filemtime($file) > $maxlifetime) {
                \unlink($file);
            }
        }
    }

    /**
     * Get session file path.
     *
     * @param string $sessionId
     * @return string
     */
    protected static function sessionFile($sessionId)
    {
        return static::$sessionSavePath . static::$sessionFilePrefix . $sessionId;
    }

    /**
     * Get or set session file path.
     *
     * @param string $path
     * @return string
     */
    public static function sessionSavePath($path)
    {
        if ($path) {
            if ($path[\strlen($path) - 1] !== DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }
            static::$sessionSavePath = $path;
            if (!\is_dir($path)) {
                \mkdir($path, 0777, true);
            }
        }
        return $path;
    }
}

FileSessionHandler::init();