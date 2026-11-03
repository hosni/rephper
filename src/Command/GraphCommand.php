<?php

declare(strict_types=1);

namespace Rephper\Command;

use Rephper\Cfg\Builder;
use Rephper\Cfg\CfgException;
use Rephper\Cli\GraphPrinter;
use Rephper\Ir\IrException;
use Rephper\Ir\Reader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'graph',
    description: 'Print a control-flow graph for each op array in an IR file.',
)]
final class GraphCommand extends Command
{
    public function __construct(
        private readonly Reader $reader = new Reader(),
        private readonly Builder $builder = new Builder(),
        private readonly GraphPrinter $printer = new GraphPrinter(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'IR JSON file')
            ->addOption('op-array', null, InputOption::VALUE_REQUIRED, 'Op array name. "main" selects the script body.')
            ->setHelp('Read an IR JSON file and print a control-flow graph for each op array. The name "main" selects the script body. This command does not load the Zend extension.');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $name = $input->getOption('op-array');
        if (!is_string($file) || ($name !== null && !is_string($name))) {
            $output->getErrorOutput()->writeln('Invalid graph arguments.');

            return Command::FAILURE;
        }

        try {
            $document = $this->reader->readFile($file);
            $graphs = $this->builder->buildDocument($document);
        } catch (IrException | CfgException $exception) {
            $output->getErrorOutput()->writeln($exception->getMessage());

            return Command::FAILURE;
        }

        $printed = 0;
        foreach ($graphs as $graph) {
            if ($name !== null && ($graph->name ?? 'main') !== $name) {
                continue;
            }
            $output->write($this->printer->print($graph));
            $printed++;
        }
        if ($printed === 0) {
            $output->getErrorOutput()->writeln('No op array named ' . $name . '.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
