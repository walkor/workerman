<?php

declare(strict_types=1);

namespace Rexpl\Workerman;

use Rexpl\Workerman\Exceptions\WorkermanException;
use Rexpl\Workerman\Tools\Helpers;
use Rexpl\Workerman\Tools\Output;

class Master
{
    /**
     * Should continue running.
     * 
     * @var bool
     */
    protected bool $run = true;


    /**
     * Shutdown disabled.
     * 
     * @var bool
     */
    protected bool $disabledShutdown = false;


    /**
     * All workers.
     * 
     * @var array<int,Worker>
     */
    protected array $workers;


    /**
     * @param array $workers
     * 
     * @return void
     */
    public function __construct(array $workers)
    {
        $this->workers = $workers;
    }


    /**
     * @param bool $daemon
     * 
     * @return int
     */
    public function start(bool $daemon): int
    {
        $this->daemon = $daemon;

        register_shutdown_function([$this, 'shutdown']);

        Helpers::setProcessTitle('Workerman master');
        Helpers::installSignalHandler($this, 'signal');

        if ($daemon) Helpers::surpressOuputStream();

        $this->monitor();

        return $this->exit();
    }


    /**
     * Monitor all workers.
     * 
     * @return void
     */
    protected function monitor(): void
    {
        while ($this->run) {

            pcntl_signal_dispatch();

            $status = 0;
            $pid = pcntl_wait($status, WUNTRACED);

            pcntl_signal_dispatch();

            if ($pid > 0) $this->reviveWorker($pid, $status);
        }
    }


    /**
     * Revive a dead worker.
     * 
     * @param int $pid
     * @param int $status
     * 
     * @return void
     */
    protected function reviveWorker(int $pid, int $status): void
    {
        $worker = $this->workers[$pid];
        unset($this->workers[$pid]);

        Output::error(sprintf(
            'Master: Worker %d unexpectedly closed with status %d', $worker->id, $status
        ));
        Output::debug(sprintf('Master: Reviving worker %d', $worker->id));

        $pid = pcntl_fork();

        switch ($pid) {
            case 0:
                
                $this->removeAllMasterActions();
                $worker->start($this->daemon);
                break;

            case -1:

                throw new WorkermanException(
                    'Fork worker failed.'
                );
            
            default:
                
                $this->workers[$pid] = $worker;
                break;
        }
    }


    /**
     * Remove all set actions for master.
     * 
     * @return void
     */
    protected function removeAllMasterActions(): void
    {
        $this->run = false;
        $this->disabledShutdown = true;

        Helpers::removeSignalHandler();
    }


    /**
     * Handles incommming signals.
     *
     * @param int $signal
     * 
     * @return void
     */
    public function signal(int $signal): void
    {
        Output::debug(sprintf('Master: Received signal %d', $signal));

        switch ($signal) {
            case SIGINT:
            case SIGTERM:
            case SIGHUP:
            case SIGTSTP:
                
                $this->stop(false);
                break;

            case SIGQUIT:
            
                $this->stop(true);
                break;
            
            case SIGUSR1:
        
                $this->reload(false);
                break;
            
            case SIGUSR2:
        
                $this->reload(true);
                break;

            case SIGIOT:

                $this->status();
                break;
        }
    }


    /**
     * Dispatch a signal.
     * 
     * @return void
     */
    protected function dispatch(int $signal): void
    {
        Output::debug(sprintf('Master: Dispatch signal %d', $signal));

        foreach ($this->workers as $pid => $worker) {
            
            posix_kill($pid, $signal);
        }
    }


    /**
     * Stop all workers.
     * 
     * @param bool $graceful
     * 
     * @return void
     */
    protected function stop(bool $graceful): void
    {
        $this->run = false;
        $this->disabledShutdown = true;

        if (!$graceful) {
            
            $this->dispatch(SIGQUIT);
            exit(Workerman::EXIT_SUCCESS);
        }


    }


    /**
     * Shutdown handler.
     * 
     * @return void
     */
    protected function shutdown(): void
    {
        if ($this->disabledShutdown) return;

        Output::error('Master: unexpected shutdown, killing all worker processes');

        $this->dispatch(SIGKILL);
    }


    /**
     * Exit the process.
     * 
     * @return int
     */
    protected function exit(): int
    {
        $this->disabledShutdown = true;
    }
}