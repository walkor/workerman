<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Tools;

use Throwable;

interface OutputInterface
{
    /**
     * Output error.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function error(string|array $message): void;


    /**
     * Output warning.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function warning(string|array $message): void;


    /**
     * Output info.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function info(string|array $message): void;


    /**
     * Output debug. Debug output is automatically suppressed in daemon mode.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function debug(string|array $message): void;


    /**
     * Output an exception.
     * 
     * @param Throwable
     * 
     * @return void
     */
    public function exception(Throwable $throwable): void;
}