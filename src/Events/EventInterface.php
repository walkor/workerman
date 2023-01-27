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
namespace Workerman\Events;

interface EventInterface
{
    /**
     * Delay the execution of a callback.
     * @param float $delay
     * @param $func
     * @param $args
     * @return int|bool
     */
    public function delay(float $delay, $func, $args = []);

    /**
     * Repeatedly execute a callback.
     * @param float $interval
     * @param $func
     * @param $args
     * @return int|bool
     */
    public function repeat(float $interval, $func, $args = []);

    /**
     * Delete a delay timer.
     * @param $timerId
     * @return bool
     */
    public function offDelay($timerId);

    /**
     * Delete a repeat timer.
     * @param $timerId
     * @return bool
     */
    public function offRepeat($timerId);

    /**
     * Execute a callback when a stream resource becomes readable or is closed for reading.
     * @param $stream
     * @param $func
     * @return void
     */
    public function onReadable($stream, $func);

    /**
     * Cancel a callback of stream readable.
     * @param $stream
     * @return void
     */
    public function offReadable($stream);

    /**
     * Execute a callback when a stream resource becomes writable or is closed for writing.
     * @param $stream
     * @param $func
     * @return void
     */
    public function onWritable($stream, $func);

    /**
     * Cancel a callback of stream writable.
     * @param $stream
     * @return void
     */
    public function offWritable($stream);

    /**
     * Execute a callback when a signal is received.
     * @param $signal
     * @param $func
     * @return void
     */
    public function onSignal($signal, $func);

    /**
     * Cancel a callback of signal.
     * @param $signal
     * @return void
     */
    public function offSignal($signal);

    /**
     * Delete all timer.
     * @return void
     */
    public function deleteAllTimer();

    /**
     * Run the event loop.
     * @return void
     */
    public function run();

    /**
     * Stop event loop.
     * @return void
     */
    public function stop();

    /**
     * 
     * @return int
     */
    public function getTimerCount();
}
