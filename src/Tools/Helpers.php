<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Tools;

use Rexpl\Workerman\Exceptions\WorkermanException;
use Workerman\Events\EventInterface;

class Helpers
{
    /**
     * Available event loops with extension.
     *
     * @var array<string,string>
     */
    public const EXTENSION_EVENT_LOOPS = [
        'event' => \Workerman\Events\Event::class,
        'libevent' => \Workerman\Events\Libevent::class,
    ];


    /**
     * Default event loop.
     * 
     * @var string
     */
    public const DEFAULT_EVENT_LOOP = \Workerman\Events\Select::class;


    /**
     * @var array<int>
     */
    public const SIGNALS = [
        SIGINT, // Stop
        SIGTERM,
        SIGHUP,
        SIGTSTP,
        SIGQUIT, // Gracefull stop
        SIGUSR1, // Reload
        SIGUSR2, // Gracefull reload
        SIGIOT, // Status
    ];


    /**
     * @var array
     */
    public const EVENT_SIGNALS = [
        SIGHUP,
        SIGQUIT,
        SIGIOT,
    ];


    /**
     * Detect wich event loop to use.
     * 
     * @return string
     */
    public static function detectEventLoop(): string
    {
        foreach (self::EXTENSION_EVENT_LOOPS as $extension => $class) {
            
            if (extension_loaded($extension)) return $class;
        }

        return self::DEFAULT_EVENT_LOOP;
    }


    /**
     * Change the process title.
     * 
     * @param string $title
     * 
     * @return void
     */
    public static function setProcessTitle(string $title): void
    {
        if (cli_set_process_title($title)) return;

        throw new WorkermanException(sprintf(
            'Failed to set process title to %s.', $title
        ));
    }


    /**
     * Remove default output.
     * 
     * @return void
     */
    public static function surpressOuputStream(): void
    {
        if (!is_resource(STDOUT)) return;

        fclose(STDOUT);
        fopen('/dev/null', 'wb');
    }


    /**
     * Remove default error ouput.
     * 
     * @return void
     */
    public static function surpressErrorStream(): void
    {
        if (!is_resource(STDERR)) return;

        fclose(STDERR);
        fopen('/dev/null', 'wb');
    }


    /**
     * Move default error ouput.
     * 
     * @param string $path
     * 
     * @return void
     */
    public static function moveErrorStream(string $path): void
    {
        if (!is_resource(STDERR)) return;

        fclose(STDERR);
        fopen($path, 'wb');
    }


    /**
     * Install signal handler.
     * 
     * @param object $class
     * @param string $method
     * 
     * @return void
     */
    public static function installSignalHandler(object $class, string $method): void
    {
        foreach (self::SIGNALS as $signal) {
            
            pcntl_signal($signal, [$class, $method], false);
        }

        pcntl_signal(SIGPIPE, SIG_IGN, false);
    }


    /**
     * Remove a signal handler.
     * 
     * @return void
     */
    public static function removeSignalHandler(): void
    {
        foreach (self::SIGNALS as $signal) {
            
            pcntl_signal($signal, SIG_IGN, false);
        }
    }


    /**
     * Set signal handler to event loop.
     * 
     * @param EventInterface $eventLoop
     * @param object $class
     * @param string $method
     * 
     * @return void
     */
    public static function eventSignalHandler(EventInterface $eventLoop, object $class, string $method): void
    {
        foreach (self::EVENT_SIGNALS as $signal) {
            
            $eventLoop->add($signal, EventInterface::EV_SIGNAL, [$class, $method]);
        }
    }
}