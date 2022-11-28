<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Commands;

use Rexpl\Workerman\Exceptions\WorkermanException;
use Rexpl\Workerman\Workerman;
use Symfony\Component\Console\Input\InputOption;
use Throwable;

class Start extends Command
{
    /**
     * Configure the command.
     * 
     * @return void
     */
    protected function configure(): void
    {
        $this->setName('start')
            ->setDescription('Start workerman')
            ->setHelp('Start the workerman application.')
            ->addOption('daemon', 'd', InputOption::VALUE_OPTIONAL, 'Start workerman in daemon mode.');
    }


    /**
     * Execute the command.
     * 
     * @return int
     */
    protected function executeCommand(): int
    {
        try {
            
            return (new Workerman(static::$path))->start($this->input->getOption('daemon') !== null);

        } catch (WorkermanException $e) {

            $this->symfonyStyle->error($e->getMessage());

        } catch (Throwable $th) {

            $this->symfonyStyle->block([
                sprintf('%s: %s', get_class($th), $th->getMessage()),
                sprintf('Thrown in %s:%s', $th->getFile(), $th->getLine()),
                $th->getTraceAsString()
            ]);

        }
        
        return self::FAILURE;
    }   
}