<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Commands;

use Symfony\Component\Console\Input\InputOption;

class Stop extends Command
{
    /**
     * Configure the command.
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('stop')
            ->setDescription('Stop workerman')
            ->setHelp('Stop the workerman application. The graceful option will wait that all connections are closed before stoping.')
            ->addOption('graceful', 'g', InputOption::VALUE_OPTIONAL, 'Stop workerman gracefully.')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Time in seconds to wait before forcing workerman to stop.');
    }


    /**
     * Execute the command.
     * 
     * @return int
     */
    protected function executeCommand(): int
    {
        return 0;
    }   
}