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

use Exception;
use Workerman\Protocols\Http\Session;
use function clearstatcache;
use function file_get_contents;
use function file_put_contents;
use function filemtime;
use function glob;
use function is_dir;
use function is_file;
use function mkdir;
use function rename;
use function session_save_path;
use function strlen;
use function sys_get_temp_dir;
use function time;
use function touch;
use function unlink;

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
    protected static string $sessionSavePath;

    /**
     * Session file prefix.
     *
     * @var string
     */
    protected static string $sessionFilePrefix = 'session_';

    /**
     * Init.
     */
    public static function init()
    {
        $savePath = @session_save_path();
        if (!$savePath || str_starts_with($savePath, 'tcp://')) {
            $savePath = sys_get_temp_dir();
        }
        static::sessionSavePath($savePath);
    }

    /**
     * FileSessionHandler constructor.
     * @param array $config
     */
    public function __construct(array $config = [])
    {
        if (isset($config['save_path'])) {
            static::sessionSavePath($config['save_path']);
        }
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
     */
    public function read(string $sessionId): string|false
    {
        $sessionFile = static::sessionFile($sessionId);
        clearstatcache();
        if (is_file($sessionFile)) {
            if (time() - filemtime($sessionFile) > Session::$lifetime) {
                unlink($sessionFile);
                return false;
            }
            $data = file_get_contents($sessionFile);
            return $data ?: false;
        }
        return false;
    }

    /**
     * {@inheritdoc}
     * @throws Exception
     */
    public function write(string $sessionId, string $sessionData): bool
    {
        $tempFile = static::$sessionSavePath . uniqid(bin2hex(random_bytes(8)), true);
        if (!file_put_contents($tempFile, $sessionData)) {
            return false;
        }
        return rename($tempFile, static::sessionFile($sessionId));
    }

    /**
     * Update session modify time.
     *
     * @see https://www.php.net/manual/en/class.sessionupdatetimestamphandlerinterface.php
     * @see https://www.php.net/manual/zh/function.touch.php
     *
     * @param string $sessionId Session id.
     * @param string $data Session Data.
     *
     * @return bool
     */
    public function updateTimestamp(string $sessionId, string $data = ""): bool
    {
        $sessionFile = static::sessionFile($sessionId);
        if (!file_exists($sessionFile)) {
            return false;
        }
        // set file modify time to current time
        $setModifyTime = touch($sessionFile);
        // clear file stat cache
        clearstatcache();
        return $setModifyTime;
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
    public function destroy(string $sessionId): bool
    {
        $sessionFile = static::sessionFile($sessionId);
        if (is_file($sessionFile)) {
            unlink($sessionFile);
        }
        return true;
    }

    /**
     * {@inheritdoc}
     */
    public function gc(int $maxLifetime): bool
    {
        $timeNow = time();
        foreach (glob(static::$sessionSavePath . static::$sessionFilePrefix . '*') as $file) {
            if (is_file($file) && $timeNow - filemtime($file) > $maxLifetime) {
                unlink($file);
            }
        }
        return true;
    }

    /**
     * Get session file path.
     *
     * @param string $sessionId
     * @return string
     */
    protected static function sessionFile(string $sessionId): string
    {
        return static::$sessionSavePath . static::$sessionFilePrefix . $sessionId;
    }

    /**
     * Get or set session file path.
     *
     * @param string $path
     * @return string
     */
    public static function sessionSavePath(string $path): string
    {
        if ($path) {
            if ($path[strlen($path) - 1] !== DIRECTORY_SEPARATOR) {
                $path .= DIRECTORY_SEPARATOR;
            }
            static::$sessionSavePath = $path;
            if (!is_dir($path)) {
                mkdir($path, 0777, true);
            }
        }
        return $path;
    }
}

FileSessionHandler::init();