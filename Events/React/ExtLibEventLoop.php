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
namespace Workerman\Events\React;
use Workerman\Events\EventInterface;

/**
 * Class ExtLibEventLoop
 * @package Workerman\Events\React
 */
class ExtLibEventLoop extends Base
{
    public function __construct()
    {
        $this->_eventLoop = new \React\EventLoop\ExtLibeventLoop();
    }
}
