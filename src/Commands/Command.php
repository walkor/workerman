<?php

declare(strict_types=1);

namespace Rexpl\Workerman\Commands;

use Rexpl\Workerman\Tools\SymfonyOutput;
use Rexpl\Workerman\Workerman;
use Symfony\Component\Console\Command\Command as SymfonyCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

abstract class Command extends SymfonyCommand
{
    /**
     * Root path.
     * 
     * @var string
     */
    public static string $path;


    /**
     * Symfony style.
     * 
     * @var SymfonyStyle
     */
    protected SymfonyStyle $symfonyStyle;


    /**
     * Input arguments.
     * 
     * @var InputInterface
     */
    protected InputInterface $input;


    /**
     * Execute the command.
     * 
     * @return int
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->input = $input;
        $this->symfonyStyle = new SymfonyStyle($input, $output);

        Workerman::addOutput(new SymfonyOutput($this->symfonyStyle));

        return $this->executeCommand();
    }


    abstract protected function executeCommand(): int;
}