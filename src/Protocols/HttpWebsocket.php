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

namespace Workerman\Protocols;

use Workerman\Connection\TcpConnection;

/**
 * Http and Websocket Protocol.
 */
class HttpWebsocket
{
    /**
     * Judge whether it is a Websocket protocol
     *
     * @param mixed $buffer 
     * @param TcpConnection $connection 
     * @return bool
     */
    static function isWebsocket($buffer, $connection): bool
    {
        if (isset($connection->context->isWebsocket)) {
            return true;
        }
        if (substr($buffer, 0, 4) === "GET " && strpos($buffer, "\nUpgrade: websocket") !== false) {
            $connection->context->isWebsocket = true;
            $connection->context->websocketHandshakeBuffer = $buffer;
            return true;
        }
        return false;
    }

    /**
     * Check the integrity of the package.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return int
     */
    public static function input($buffer, $connection)
    {
        $isWebsocket = self::isWebsocket($buffer, $connection);
        if ($isWebsocket) {
            return Websocket::input($buffer, $connection);
        } else {
            return Http::input($buffer, $connection);
        }
    }

    /**
     * Encode.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function encode($buffer, $connection)
    {
        $isWebsocket = isset($connection->context->isWebsocket);
        if ($isWebsocket) {
            return Websocket::encode($buffer, $connection);
        } else {
            return Http::encode($buffer, $connection);
        }
    }

    /**
     * Decode.
     *
     * @param string $buffer
     * @param TcpConnection $connection
     * @return string
     */
    public static function decode($buffer, $connection)
    {
        $isWebsocket = isset($connection->context->isWebsocket);
        if ($isWebsocket) {
            $message = Websocket::decode($buffer, $connection);
            return new \Workerman\Protocols\HttpWebsocket\Request($connection->context->websocketHandshakeBuffer, $message);
        } else {
            return Http::decode($buffer, $connection);
        }
    }
}
