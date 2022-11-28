<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Commands;

use Symfony\Component\Console\Input\InputOption;

class Reload extends Command
{
    /**
     * Configure the command.
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('restart')
            ->setDescription('Restart workerman')
            ->setHelp('Restart the workerman application. The graceful option will wait that all connections are closed before restarting.')
            ->addOption('graceful', 'g', InputOption::VALUE_OPTIONAL, 'Restart workerman gracefully.')
            ->addOption('timeout', 't', InputOption::VALUE_OPTIONAL, 'Time in seconds to wait before forcing workerman to restart.');
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