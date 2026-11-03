<?php

declare(strict_types=1);

namespace Rephper\Cli;

use Rephper\Cfg\Edge;
use Rephper\Cfg\Graph;

/**
 * Renders a control-flow graph. It does not build the graph.
 */
final class GraphPrinter
{
    public function print(Graph $graph): string
    {
        $name = $graph->name ?? 'main';
        $lines = ['OpArray #' . $graph->opArrayId . ' ' . $name . ' (' . $graph->kind . ')', ''];

        if ($graph->blocks === []) {
            $lines[] = '(no blocks)';
            $lines[] = '';

            return implode("\n", $lines);
        }

        foreach ($graph->blocks as $block) {
            $lines[] = 'Block ' . $block->id . ' [' . $block->start . ', ' . $block->end . ')';
            foreach ($block->instructions as $instruction) {
                $lines[] = '    ' . $instruction->index . ': ' . $instruction->opcode;
            }
            $lines[] = '';
            $lines[] = '    Successors:';
            if ($block->successors === []) {
                $lines[] = '        (none)';
            }
            foreach ($block->successors as $edge) {
                $lines[] = '        ' . $this->edge($edge);
            }
            if ($block->predecessors !== []) {
                $lines[] = '    Predecessors:';
                foreach ($block->predecessors as $predecessor) {
                    $lines[] = '        ' . $predecessor;
                }
            }
            if ($block->exceptions !== []) {
                $lines[] = '    Exceptions:';
                foreach ($block->exceptions as $edge) {
                    $lines[] = '        ' . $this->edge($edge);
                }
            }
            $lines[] = '';
        }

        return implode("\n", $lines);
    }

    private function edge(Edge $edge): string
    {
        $case = '';
        if ($edge->case !== null) {
            $encoded = json_encode($edge->case, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $case = ' ' . ($encoded === false ? '?' : $encoded);
        }

        return $edge->to . ' (' . $edge->kind . $case . ')';
    }
}
