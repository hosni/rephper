<?php

declare(strict_types=1);

namespace Rephper\Cli;

use Rephper\Cfg\Builder;
use Rephper\Cfg\CfgException;
use Rephper\Ir\IrException;
use Rephper\Ir\Reader;

/**
 * Wires the reader, the CFG builder, and the printer.
 */
final class GraphCommand
{
    public function run(array $argv): int
    {
        $arguments = array_slice($argv, 1);
        if ($arguments === [] || $arguments[0] === '--help' || $arguments[0] === 'help') {
            fwrite(STDOUT, $this->usage());

            return $arguments === [] ? 1 : 0;
        }
        if ($arguments[0] !== 'graph') {
            fwrite(STDERR, "Unknown command.\n\n" . $this->usage());

            return 1;
        }

        $file = null;
        $name = null;
        foreach (array_slice($arguments, 1) as $argument) {
            if (str_starts_with($argument, '--op-array=')) {
                $name = substr($argument, strlen('--op-array='));
                continue;
            }
            if ($file !== null) {
                fwrite(STDERR, "Unexpected argument: " . $argument . "\n\n" . $this->usage());

                return 1;
            }
            $file = $argument;
        }
        if ($file === null) {
            fwrite(STDERR, "Missing IR file.\n\n" . $this->usage());

            return 1;
        }

        try {
            $document = (new Reader())->readFile($file);
            $graphs = (new Builder())->buildDocument($document);
        } catch (IrException | CfgException $exception) {
            fwrite(STDERR, $exception->getMessage() . "\n");

            return 1;
        }

        $printer = new GraphPrinter();
        $printed = 0;
        foreach ($graphs as $graph) {
            if ($name !== null && ($graph->name ?? 'main') !== $name) {
                continue;
            }
            fwrite(STDOUT, $printer->print($graph));
            $printed++;
        }
        if ($printed === 0) {
            fwrite(STDERR, "No op array named " . $name . ".\n");

            return 1;
        }

        return 0;
    }

    private function usage(): string
    {
        return <<<'TEXT'
Usage:
  php bin/rephper graph <file.json> [--op-array=<name>]

Read an IR JSON file and print a control-flow graph for each op array.
The name "main" selects the script body. This command does not load the Zend extension.

TEXT;
    }
}
