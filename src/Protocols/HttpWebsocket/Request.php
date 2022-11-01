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

namespace Workerman\Protocols\HttpWebsocket;

/**
 * Websocket Request
 * @package Workerman\Protocols\HttpWebsocket
 */
class Request extends \Workerman\Protocols\Http\Request
{

    /**
     * Received message content
     *
     * @var string
     */
    protected $_message;

    /**
     * Request constructor.
     *
     * @param string $buffer
     * @param string $message
     */
    public function __construct($buffer, $message)
    {
        $this->_buffer = $buffer;
        $this->_message = $message;
    }

    /**
     * Get received message content.
     *
     * @return string
     */
    public function message()
    {
        return $this->_message;
    }
}
