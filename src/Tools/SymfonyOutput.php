<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Tools;

use Throwable;
use Symfony\Component\Console\Style\SymfonyStyle;

class SymfonyOutput implements OutputInterface
{
    /**
     * @param SymfonyStyle
     * 
     * @return void
     */
    public function __construct(
        protected SymfonyStyle $ouput
    ) {}


    /**
     * Output error.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function error(string|array $message): void
    {
        $this->ouput->error($message);
    }


    /**
     * Output warning.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function warning(string|array $message): void
    {
        $this->ouput->warning($message);
    }


    /**
     * Output info.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function info(string|array $message): void
    {
        $this->ouput->info($message);
    }


    /**
     * Output debug. Debug output is automatically suppressed in daemon mode.
     * 
     * @param string|array $message
     * 
     * @return void
     */
    public function debug(string|array $message): void
    {
        if ($this->ouput->isVerbose()) $this->ouput->writeln($message);
    }


    /**
     * Output an exception.
     * 
     * @param Throwable
     * 
     * @return void
     */
    public function exception(Throwable $th): void
    {
        $this->ouput->block([
            sprintf(
                '%s: %s in %s:%s', get_class($th), $th->getMessage(), $th->getFile(), $th->getLine()
            ),
            $th->getTraceAsString()
        ]);
    }
}