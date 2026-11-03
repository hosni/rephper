<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use Rephper\Cfg\Graph;
use Rephper\Expr\Assignment;
use Rephper\Expr\Diagnostic;
use Rephper\Expr\Expression;
use Rephper\Expr\Opaque;
use Rephper\Expr\ReturnValue;
use Rephper\Expr\Reconstruction as ExpressionReconstruction;
use Rephper\Ir\OpArray;

/**
 * Turns expression roots into statements, one basic block at a time.
 *
 * Folded temporaries stay inside the expression that uses them. Compiler
 * bookkeeping and a bare control transfer do not become statements.
 */
final class Reconstructor
{
    /** @var list<string> */
    private const COMPILER_ONLY = ['RECV', 'RECV_INIT', 'VERIFY_RETURN_TYPE'];

    /**
     * Control transfers. A transfer that still carries a value is kept.
     *
     * @var list<string>
     */
    private const CONTROL = [
        'JMP', 'JMPZ', 'JMPNZ', 'JMP_NULL', 'JMPZ_EX', 'JMPNZ_EX', 'JMP_SET',
        'COALESCE', 'FE_RESET_R', 'FE_RESET_RW', 'FE_FETCH_R', 'FE_FETCH_RW',
        'SWITCH_LONG', 'SWITCH_STRING', 'MATCH', 'MATCH_ERROR',
        'BRK', 'CONT', 'GOTO', 'FAST_CALL', 'FAST_RET', 'CATCH',
        'ASSERT_CHECK', 'BIND_INIT_STATIC_OR_JMP', 'JMP_FRAMELESS',
    ];

    /**
     * The compiler's unreachable return uses (uint32)-1 as extended_value.
     */
    private const EPILOGUE_RETURN = 4294967295;

    public function reconstruct(OpArray $opArray, Graph $graph, ExpressionReconstruction $expressions): Reconstruction
    {
        if ($graph->opArrayId !== $opArray->id) {
            throw new StatementException(
                'Graph ' . $graph->opArrayId . ' does not belong to op array ' . $opArray->id
            );
        }
        if (count($graph->blocks) !== count($expressions->blocks)) {
            throw new StatementException(
                'Expression reconstruction has ' . count($expressions->blocks)
                . ' blocks for op array ' . $opArray->id . ' and the graph has ' . count($graph->blocks)
            );
        }

        $blocks = [];
        $diagnostics = [];
        foreach ($graph->blocks as $index => $block) {
            $source = $expressions->blocks[$index];
            if ($source->blockId !== $block->id || $source->start !== $block->start || $source->end !== $block->end) {
                throw new StatementException(
                    'Expression block ' . $source->blockId . ' does not match graph block ' . $block->id
                );
            }
            $statements = [];
            foreach ($source->expressions as $expression) {
                $statement = $this->statement($opArray, $block->id, $expression, $diagnostics);
                if ($statement !== null) {
                    $statements[] = $statement;
                }
            }
            $blocks[] = new BlockStatements($block->id, $block->start, $block->end, $statements);
        }

        return new Reconstruction($blocks, $diagnostics);
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private function statement(OpArray $opArray, string $blockId, Expression $expression, array &$diagnostics): ?Statement
    {
        if ($expression instanceof Assignment) {
            return new ExpressionStatement($expression->opline, $expression->line, $expression);
        }
        if ($expression instanceof ReturnValue) {
            return $this->returnStatement($opArray, $expression);
        }
        if ($expression instanceof Opaque) {
            return $this->opaque($blockId, $expression, $diagnostics);
        }

        return null;
    }

    private function returnStatement(OpArray $opArray, ReturnValue $expression): ?ReturnStatement
    {
        $instruction = $opArray->instruction($expression->opline);
        if ($instruction === null || $instruction->opcode !== 'RETURN') {
            throw new StatementException('Return at opline ' . $expression->opline . ' does not match a RETURN instruction');
        }
        if ($instruction->extendedValue === self::EPILOGUE_RETURN) {
            return null;
        }
        if ($expression->value === null) {
            throw new StatementException('RETURN at opline ' . $expression->opline . ' has no value');
        }

        return new ReturnStatement($expression->opline, $expression->line, $expression->value);
    }

    /**
     * @param list<Diagnostic> $diagnostics
     */
    private function opaque(string $blockId, Opaque $expression, array &$diagnostics): ?Statement
    {
        if (in_array($expression->opcode, self::COMPILER_ONLY, true)) {
            return null;
        }
        if ($expression->opcode === 'THROW') {
            if ($expression->op1->resolved === null) {
                $diagnostics[] = $this->diagnostic($blockId, $expression, 'THROW has no exception value');

                return new OpaqueStatement($expression->opline, $expression->line, $expression);
            }

            return new ThrowStatement($expression->opline, $expression->line, $expression->op1->resolved);
        }
        if (
            in_array($expression->opcode, self::CONTROL, true)
            && $expression->op1->resolved === null
            && $expression->op2->resolved === null
        ) {
            return null;
        }

        $diagnostics[] = $this->diagnostic($blockId, $expression, 'opcode is not reconstructed as a statement');

        return new OpaqueStatement($expression->opline, $expression->line, $expression);
    }

    private function diagnostic(string $blockId, Opaque $expression, string $reason): Diagnostic
    {
        return new Diagnostic(
            'unsupported',
            $blockId,
            $expression->opline,
            $expression->line,
            $expression->opcode,
            $reason,
        );
    }
}
