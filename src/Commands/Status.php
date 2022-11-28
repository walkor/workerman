<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Commands;

use Symfony\Component\Console\Input\InputOption;

class Status extends Command
{
    /**
     * Configure the command.
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('status')
            ->setDescription('Workerman status')
            ->setHelp('Display the status of the workerman application.');
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