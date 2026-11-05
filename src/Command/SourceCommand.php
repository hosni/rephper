<?php

declare(strict_types=1);

namespace Rephper\Command;

use Rephper\Application\OpArrayPipeline;
use Rephper\Cfg\CfgException;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Expr\ExpressionException;
use Rephper\Ir\Document;
use Rephper\Ir\IrException;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;
use Rephper\Stmt\StatementException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'source',
    description: 'Generate a PHP body for one op array in an IR file.',
)]
final class SourceCommand extends Command
{
    public function __construct(
        private readonly Reader $reader = new Reader(),
        private readonly OpArrayPipeline $pipeline = new OpArrayPipeline(),
        private readonly PhpSourceGenerator $generator = new PhpSourceGenerator(),
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('file', InputArgument::REQUIRED, 'IR JSON file')
            ->addOption('op-array', null, InputOption::VALUE_REQUIRED, 'Op array id or name. An integer selects the id. "main" selects the script body.')
            ->addOption('output', null, InputOption::VALUE_REQUIRED, 'Write the generated PHP to this file instead of stdout.')
            ->setHelp(
                'Read an IR JSON file and generate the PHP body of one op array. '
                . 'An integer --op-array value selects that id. Any other value selects the op array name, and "main" selects the script body. '
                . 'The result is an opening tag and that op array\'s statements. It is not a function, class, namespace, or the original file. '
                . 'This command does not load the Zend extension and does not execute the original PHP file.'
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $file = $input->getArgument('file');
        $selector = $input->getOption('op-array');
        $destination = $input->getOption('output');
        if (!is_string($file) || ($destination !== null && !is_string($destination))) {
            $output->getErrorOutput()->writeln('Invalid source arguments.');

            return Command::FAILURE;
        }
        if (!is_string($selector)) {
            $output->getErrorOutput()->writeln('The --op-array option is required.');

            return Command::FAILURE;
        }

        try {
            $document = $this->reader->readFile($file);
            $opArray = $this->select($document, $selector);
            if (!$opArray instanceof OpArray) {
                $output->getErrorOutput()->writeln($this->missing($selector));

                return Command::FAILURE;
            }
            $source = $this->generator->generate($this->pipeline->structure($document, $opArray));
        } catch (IrException | CfgException | ExpressionException | StatementException | SourceGenerationException $exception) {
            $output->getErrorOutput()->writeln($exception->getMessage());

            return Command::FAILURE;
        }

        if ($destination === null) {
            $output->write($source);

            return Command::SUCCESS;
        }

        return $this->writeFile($destination, $source, $output);
    }

    private function select(Document $document, string $selector): ?OpArray
    {
        if (preg_match('/\A(?:0|[1-9]\d*)\z/', $selector) === 1) {
            foreach ($document->opArrays as $opArray) {
                if ((string) $opArray->id === $selector) {
                    return $opArray;
                }
            }

            return null;
        }

        return $document->opArrayNamed($selector === 'main' ? null : $selector);
    }

    private function missing(string $selector): string
    {
        if (preg_match('/\A(?:0|[1-9]\d*)\z/', $selector) === 1) {
            return 'No op array with id ' . $selector . '.';
        }

        return 'No op array named ' . $selector . '.';
    }

    private function writeFile(string $path, string $source, OutputInterface $output): int
    {
        $directory = dirname($path);
        if (!is_dir($directory)) {
            $output->getErrorOutput()->writeln('Output directory does not exist.');

            return Command::FAILURE;
        }

        $temporary = $directory . DIRECTORY_SEPARATOR . '.rephper-' . getmypid() . '-' . bin2hex(random_bytes(4)) . '.tmp';
        if (file_put_contents($temporary, $source) === false) {
            $output->getErrorOutput()->writeln('Could not write ' . $path . '.');

            return Command::FAILURE;
        }
        if (!rename($temporary, $path)) {
            unlink($temporary);
            $output->getErrorOutput()->writeln('Could not write ' . $path . '.');

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }
}
