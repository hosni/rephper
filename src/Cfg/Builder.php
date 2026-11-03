<?php

declare(strict_types=1);

namespace Rephper\Cfg;

use Rephper\Cfg\Php84\Semantics;
use Rephper\Ir\Document;
use Rephper\Ir\Instruction;
use Rephper\Ir\OpArray;
use Rephper\Ir\TryCatch;

/**
 * Builds one control-flow graph per op array.
 *
 * The builder does not parse JSON. Exception edges come from try_catch rows and
 * are stored separately from ordinary successors.
 */
final class Builder
{
    public function __construct(
        private readonly Semantics $semantics = new Semantics(),
    ) {
    }

    /**
     * @return list<Graph>
     */
    public function buildDocument(Document $document): array
    {
        $fixture = $document->php->version === 'PHP_VERSION' && $document->php->versionId === 0;
        $php84 = preg_match('/^8\.4(\.|$)/', $document->php->version) === 1;
        if ((!$php84 && !$fixture) || $document->php->api !== 20240924) {
            throw new UnsupportedSemanticsException(
                'CFG semantics are implemented for PHP 8.4 API 20240924. This IR was compiled by '
                . $document->php->version . ' API ' . $document->php->api
            );
        }
        if ($document->compilation->stage !== 'pre_pass_two' || $document->compilation->jumps !== 'absolute_opline') {
            throw new UnsupportedSemanticsException(
                'CFG semantics are implemented for pre_pass_two absolute opline jumps, got '
                . $document->compilation->stage . ' / ' . $document->compilation->jumps
            );
        }

        $graphs = [];
        foreach ($document->opArrays as $opArray) {
            $graphs[] = $this->build($opArray);
        }

        return $graphs;
    }

    public function build(OpArray $opArray): Graph
    {
        $count = $opArray->instructionCount();
        $resolved = [];
        foreach ($opArray->instructions as $instruction) {
            $successors = $this->semantics->successors($opArray, $instruction);
            foreach ($successors as $successor) {
                $this->checkTarget($opArray, $instruction, $successor->target);
            }
            $resolved[$instruction->index] = $successors;
        }

        $leaders = $this->leaders($opArray, $resolved);
        $blocks = [];
        $byStart = [];
        $leaderCount = count($leaders);
        for ($i = 0; $i < $leaderCount; $i++) {
            $start = $leaders[$i];
            $end = $i + 1 < $leaderCount ? $leaders[$i + 1] : $count;
            $instructions = [];
            for ($opline = $start; $opline < $end; $opline++) {
                $instruction = $opArray->instruction($opline);
                if ($instruction === null) {
                    throw new CfgException('Op array ' . $opArray->id . ' is missing instruction ' . $opline);
                }
                $instructions[] = $instruction;
            }
            $block = new Block('B' . count($blocks), $start, $end, $instructions);
            $blocks[] = $block;
            $byStart[$start] = $block;
        }

        foreach ($blocks as $block) {
            $last = $block->instructions[array_key_last($block->instructions)];
            foreach ($resolved[$last->index] as $successor) {
                $target = $byStart[$successor->target] ?? null;
                if ($target === null) {
                    throw new CfgException(
                        $last->opcode . ' at ' . $last->index . ' targets opline '
                        . $successor->target . ', which is not a block start'
                    );
                }
                $edge = new Edge($successor->kind, $target->id, $successor->case);
                $block->successors[] = $edge;
                if (!in_array($block->id, $target->predecessors, true)) {
                    $target->predecessors[] = $block->id;
                }
            }
        }

        $this->exceptionEdges($opArray, $blocks);

        return new Graph($opArray->id, $opArray->name, $opArray->kind, $blocks);
    }

    /**
     * @param array<int, list<Successor>> $resolved
     * @return list<int>
     */
    private function leaders(OpArray $opArray, array $resolved): array
    {
        $count = $opArray->instructionCount();
        if ($count === 0) {
            return [];
        }

        $leaders = [0];
        foreach ($opArray->instructions as $instruction) {
            $successors = $resolved[$instruction->index];
            $onlyNext = count($successors) === 1
                && $successors[0]->kind === 'fallthrough'
                && $successors[0]->target === $instruction->index + 1;
            if ($onlyNext) {
                continue;
            }
            if ($instruction->index + 1 < $count) {
                $leaders[] = $instruction->index + 1;
            }
            foreach ($successors as $successor) {
                $leaders[] = $successor->target;
            }
        }

        foreach ($opArray->tryCatch as $region) {
            $this->checkRegion($opArray, $region);
            $leaders[] = $region->try;
            if ($region->hasCatch() && $region->catch !== null) {
                $leaders[] = $region->catch;
            }
            if ($region->hasFinally() && $region->finally !== null) {
                $leaders[] = $region->finally;
            }
        }

        if ($opArray->labels !== null) {
            foreach ($opArray->labels as $label) {
                $this->checkTarget($opArray, null, $label->opline);
                $leaders[] = $label->opline;
            }
        }

        $leaders = array_values(array_unique($leaders, SORT_REGULAR));
        sort($leaders);

        return $leaders;
    }

    /**
     * @param list<Block> $blocks
     */
    private function exceptionEdges(OpArray $opArray, array $blocks): void
    {
        foreach ($blocks as $block) {
            $edge = $this->exceptionEdge($opArray, $block->start);
            if ($edge === null) {
                continue;
            }
            $target = null;
            foreach ($blocks as $candidate) {
                if ($candidate->start === $edge['target']) {
                    $target = $candidate;
                    break;
                }
            }
            if ($target === null) {
                throw new CfgException('Exception handler opline ' . $edge['target'] . ' is not a block start');
            }
            $block->exceptions[] = new Edge($edge['kind'], $target->id);
            if (!in_array($block->id, $target->exceptionPredecessors, true)) {
                $target->exceptionPredecessors[] = $block->id;
            }
        }
    }

    /**
     * @return array{kind: string, target: int}|null
     */
    private function exceptionEdge(OpArray $opArray, int $opline): ?array
    {
        $innermost = null;
        foreach ($opArray->tryCatch as $index => $region) {
            if ($this->regionContains($region, $opline)) {
                $innermost = $index;
            }
        }
        if ($innermost === null) {
            return null;
        }

        for ($index = $innermost; $index >= 0; $index--) {
            $region = $opArray->tryCatch[$index];
            if ($opline < $region->try) {
                continue;
            }
            if ($region->hasCatch() && $region->catch !== null && $opline < $region->catch) {
                return ['kind' => 'catch', 'target' => $region->catch];
            }
            if ($region->hasFinally() && $region->finally !== null && $opline < $region->finally) {
                return ['kind' => 'finally', 'target' => $region->finally];
            }
            $end = $region->finallyEnd;
            if ($end !== null && $end !== 0 && $opline < $end) {
                continue;
            }
        }

        return null;
    }

    private function regionContains(TryCatch $region, int $opline): bool
    {
        if ($opline < $region->try) {
            return false;
        }
        if ($region->hasCatch() && $region->catch !== null && $opline < $region->catch) {
            return true;
        }
        $end = $region->finallyEnd;

        return $end !== null && $end !== 0 && $opline < $end;
    }

    private function checkRegion(OpArray $opArray, TryCatch $region): void
    {
        $count = $opArray->instructionCount();
        if ($region->try < 0 || $region->try >= $count) {
            throw new CfgException('Try opline ' . $region->try . ' is outside the op array');
        }
        if (!$region->hasCatch() && !$region->hasFinally()) {
            throw new CfgException('Try at opline ' . $region->try . ' has neither catch nor finally');
        }
        if ($region->hasCatch()) {
            $catch = $region->catch;
            if ($catch === null || $catch <= $region->try || $catch >= $count) {
                throw new CfgException('Catch opline ' . (string) $catch . ' is not inside the op array after the try');
            }
            $instruction = $opArray->instruction($catch);
            if ($instruction === null || $instruction->opcode !== 'CATCH') {
                throw new CfgException('Catch opline ' . $catch . ' is not a CATCH instruction');
            }
        }
        if ($region->hasFinally()) {
            $finally = $region->finally;
            if ($finally === null || $finally <= $region->try || $finally >= $count) {
                throw new CfgException('Finally opline ' . (string) $finally . ' is not inside the op array after the try');
            }
            $end = $region->finallyEnd;
            if ($end === null || $end === 0 || $end < $finally || $end >= $count) {
                throw new CfgException('Finally end ' . (string) $end . ' does not cover the finally body');
            }
            $ret = $opArray->instruction($end);
            if ($ret === null || $ret->opcode !== 'FAST_RET') {
                throw new CfgException('Finally end ' . $end . ' is not FAST_RET');
            }
        }
    }

    private function checkTarget(OpArray $opArray, ?Instruction $instruction, int $target): void
    {
        if ($target < 0 || $target >= $opArray->instructionCount()) {
            $where = $instruction === null
                ? 'A label'
                : $instruction->opcode . ' at ' . $instruction->index;
            throw new CfgException($where . ' jumps to opline ' . $target . ', which is outside the op array');
        }
    }
}
