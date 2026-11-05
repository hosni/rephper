<?php

declare(strict_types=1);

namespace Rephper\Application;

use Rephper\Cfg\Builder;
use Rephper\Cfg\Graph;
use Rephper\Cfg\LoopAnalyzer;
use Rephper\Expr\Reconstructor as ExpressionReconstructor;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Stmt\ExceptionStructurer;
use Rephper\Stmt\Reconstructor as StatementReconstructor;
use Rephper\Stmt\StatementException;
use Rephper\Stmt\StructuredStatements;

/**
 * Reconstructs structured statements for one op array.
 *
 * The steps are the existing CFG, expression, statement, and structuring
 * passes. This does not load the Zend extension, read opcodes again, or
 * render PHP.
 */
final class OpArrayPipeline
{
    public function __construct(
        private readonly Builder $builder = new Builder(),
        private readonly ExpressionReconstructor $expressions = new ExpressionReconstructor(),
        private readonly StatementReconstructor $statements = new StatementReconstructor(),
        private readonly LoopAnalyzer $loops = new LoopAnalyzer(),
        private readonly ExceptionStructurer $exceptions = new ExceptionStructurer(),
    ) {
    }

    public function structure(Document $document, OpArray $opArray): StructuredStatements
    {
        $index = null;
        foreach ($document->opArrays as $position => $candidate) {
            if ($candidate === $opArray) {
                $index = $position;
                break;
            }
        }
        if ($index === null) {
            throw new StatementException('Op array ' . $opArray->id . ' is not in the document.');
        }

        $graphs = $this->builder->buildDocument($document);
        $graph = $graphs[$index] ?? null;
        if (!$graph instanceof Graph || $graph->opArrayId !== $opArray->id) {
            throw new StatementException('Op array ' . $opArray->id . ' does not match the control-flow graph.');
        }

        $expressions = $this->expressions->reconstruct($opArray, $graph);
        $statements = $this->statements->reconstruct($opArray, $graph, $expressions);
        $analysis = $this->loops->analyze($graph, $opArray);

        return $this->exceptions->structure($opArray, $graph, $analysis, $statements);
    }
}
