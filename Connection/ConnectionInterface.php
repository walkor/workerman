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
namespace Workerman\Connection;

/**
 * ConnectionInterface.
 */
abstract class  ConnectionInterface
{
    /**
     * Statistics for status command.
     *
     * @var array
     */
    public static $statistics = array(
        'connection_count' => 0,
        'total_request'    => 0,
        'throw_exception'  => 0,
        'send_fail'        => 0,
    );

    /**
     * Emitted when data is received.
     *
     * @var callable
     */
    public $onMessage = null;

    /**
     * Emitted when the other end of the socket sends a FIN packet.
     *
     * @var callable
     */
    public $onClose = null;

    /**
     * Emitted when an error occurs with connection.
     *
     * @var callable
     */
    public $onError = null;

    /**
     * Sends data on the connection.
     *
     * @param mixed $send_buffer
     * @return void|boolean
     */
    abstract public function send($send_buffer);

    /**
     * Get remote IP.
     *
     * @return string
     */
    abstract public function getRemoteIp();

    /**
     * Get remote port.
     *
     * @return int
     */
    abstract public function getRemotePort();

    /**
     * Get remote address.
     *
     * @return string
     */
    abstract public function getRemoteAddress();

    /**
     * Get local IP.
     *
     * @return string
     */
    abstract public function getLocalIp();

    /**
     * Get local port.
     *
     * @return int
     */
    abstract public function getLocalPort();

    /**
     * Get local address.
     *
     * @return string
     */
    abstract public function getLocalAddress();

    /**
     * Is ipv4.
     *
     * @return bool
     */
    abstract public function isIPv4();

    /**
     * Is ipv6.
     *
     * @return bool
     */
    abstract public function isIPv6();

    /**
     * Close connection.
     *
     * @param $data
     * @return void
     */
    abstract public function close($data = null);
}
