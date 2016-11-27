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

/**
 * Class LibEventLoop
 * @package Workerman\Events\React
 */
class LibEventLoop extends \React\EventLoop\LibEventLoop
{
    /**
     * Event base.
     *
     * @var event_base resource
     */
    protected $_eventBase = null;

    /**
     * All signal Event instances.
     *
     * @var array
     */
    protected $_signalEvents = array();

    /**
     * Construct.
     */
    public function __construct()
    {
        parent::__construct();
        $class = new \ReflectionClass('\React\EventLoop\LibEventLoop');
        $property = $class->getProperty('eventBase');
        $property->setAccessible(true);
        $this->_eventBase = $property->getValue($this);
    }

    /**
     * Add signal handler.
     *
     * @param $signal
     * @param $callback
     * @return bool
     */
    public function addSignal($signal, $callback)
    {
        $event = event_new();
        $this->_signalEvents[$signal] = $event;
        event_set($event, $signal, EV_SIGNAL | EV_PERSIST, $callback);
        event_base_set($event, $this->_eventBase);
        event_add($event);
    }

    /**
     * Remove signal handler.
     *
     * @param $signal
     */
    public function removeSignal($signal)
    {
        if (isset($this->_signalEvents[$signal])) {
            $event = $this->_signalEvents[$signal];
            event_del($event);
            unset($this->_signalEvents[$signal]);
        }
    }
}
