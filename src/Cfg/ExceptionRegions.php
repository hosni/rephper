<?php

declare(strict_types=1);

namespace Rephper\Cfg;

use Rephper\Ir\Instruction;
use Rephper\Ir\OpArray;
use Rephper\Ir\TryCatch;

/**
 * Locates try/catch regions from try/catch rows, CATCH instructions, and
 * catch_next jumps.
 *
 * The exception edge on a block is only the VM handler boundary. The matching
 * class names come from the CATCH operands, in order. A union catch is a run
 * of CATCH instructions that jump to one body. Separate clauses each own a
 * body. An empty chain whose CATCH instructions share one line and one
 * variable is left unstructured: the compiler emits the same pattern for one
 * empty union and for several empty clauses. A row that has a finally
 * handler, and a try/catch that contains such a row, is not reconstructed.
 * The CFG is not modified. Exception edges are not read as successors.
 */
final class ExceptionRegions
{
    private const LAST_CATCH = 1;

    /**
     * @param list<ExceptionRegion> $regions
     */
    public function __construct(
        public readonly array $regions,
    ) {
    }

    public static function analyze(OpArray $opArray, Graph $graph): self
    {
        if ($graph->opArrayId !== $opArray->id) {
            throw new CfgException(
                'Control-flow graph ' . $graph->opArrayId . ' does not match op array ' . $opArray->id
            );
        }

        $regions = [];
        foreach ($opArray->tryCatch as $index => $row) {
            $regions[] = self::region($opArray, $graph, $index, $row);
        }
        foreach ($regions as $index => $region) {
            if (!$region->isStructured()) {
                continue;
            }
            foreach ($regions as $other) {
                if (!$other->hasFinally || $other->index === $region->index) {
                    continue;
                }
                if ($other->try >= $region->try && $other->try < $region->spanEnd) {
                    $regions[$index] = $region->unstructured(
                        'The region contains a finally handler, which is not reconstructed.'
                    );
                    break;
                }
            }
        }

        return new self($regions);
    }

    /**
     * The outermost region that begins at this opline and is not already open.
     *
     * @param array<int, true> $open
     */
    public function startingAt(int $opline, array $open): ?ExceptionRegion
    {
        $found = null;
        foreach ($this->regions as $region) {
            if ($region->try !== $opline || isset($open[$region->index])) {
                continue;
            }
            if (!$found instanceof ExceptionRegion || $region->spanEnd > $found->spanEnd) {
                $found = $region;
            }
        }

        return $found;
    }

    /**
     * Catch bodies that the ordinary CFG does not reach from the entry.
     *
     * @return list<array{entry: int, end: int}>
     */
    public function handlerRanges(): array
    {
        $ranges = [];
        foreach ($this->regions as $region) {
            if (!$region->isStructured() || $region->clauses === null) {
                continue;
            }
            foreach ($region->clauses as $clause) {
                if ($clause->bodyStart < $clause->bodyEnd) {
                    $ranges[] = ['entry' => $clause->bodyStart, 'end' => $clause->bodyEnd];
                }
            }
        }

        return $ranges;
    }

    private static function region(OpArray $opArray, Graph $graph, int $index, TryCatch $row): ExceptionRegion
    {
        $spanEnd = self::spanEnd($opArray, $index, $row);
        $finally = $row->hasFinally() ? $row->finally : null;
        $finallyEnd = $row->hasFinally() ? $row->finallyEnd : null;
        $catch = $row->hasCatch() ? $row->catch : null;
        if ($row->hasFinally()) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $spanEnd,
                true,
                $finally,
                $finallyEnd,
                $catch,
                null,
                'finally is not reconstructed.',
            );
        }
        if ($catch === null) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $spanEnd,
                false,
                null,
                null,
                null,
                null,
                'Try at opline ' . $row->try . ' has no catch handler.',
            );
        }

        $exit = $opArray->instruction($catch - 1);
        if ($exit === null || $exit->opcode !== 'JMP' || $exit->op1->kind !== 'jump' || !is_int($exit->op1->target)) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $spanEnd,
                false,
                null,
                null,
                $catch,
                null,
                'The try at opline ' . $row->try . ' does not end with a jump to the continuation.',
            );
        }
        $continuation = $exit->op1->target;
        if ($continuation <= $catch) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $spanEnd,
                false,
                null,
                null,
                $catch,
                null,
                'The try at opline ' . $row->try . ' jumps backward from the catch dispatch.',
            );
        }
        if ($graph->blockStartingAt($row->try) === null || $graph->blockStartingAt($catch) === null) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $continuation,
                false,
                null,
                null,
                $catch,
                null,
                'The try at opline ' . $row->try . ' does not begin on a block boundary.',
            );
        }
        if ($continuation < $opArray->instructionCount() && $graph->blockStartingAt($continuation) === null) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $continuation,
                false,
                null,
                null,
                $catch,
                null,
                'The continuation after try at opline ' . $row->try . ' is not a block boundary.',
            );
        }

        $clauses = self::clauses($opArray, $catch, $continuation);
        if (is_string($clauses)) {
            return new ExceptionRegion(
                $index,
                $row->try,
                $continuation,
                false,
                null,
                null,
                $catch,
                null,
                $clauses,
            );
        }

        return new ExceptionRegion(
            $index,
            $row->try,
            $continuation,
            false,
            null,
            null,
            $catch,
            $clauses,
            null,
        );
    }

    private static function spanEnd(OpArray $opArray, int $index, TryCatch $row): int
    {
        if ($row->hasFinally() && $row->finallyEnd !== null) {
            return $row->finallyEnd + 1;
        }
        if ($row->hasCatch() && $row->catch !== null) {
            $exit = $opArray->instruction($row->catch - 1);
            if ($exit !== null && $exit->opcode === 'JMP' && is_int($exit->op1->target) && $exit->op1->target > $row->catch) {
                return $exit->op1->target;
            }
        }

        $end = $opArray->instructionCount();
        foreach ($opArray->tryCatch as $otherIndex => $other) {
            if ($otherIndex !== $index && $other->try > $row->try && $other->try < $end) {
                $end = $other->try;
            }
        }

        return $end;
    }

    /**
     * @return list<CatchDispatch>|string
     */
    private static function clauses(OpArray $opArray, int $ip, int $continuation): array|string
    {
        $clauses = [];
        $guard = 0;
        while ($guard++ < 64) {
            if (self::ambiguousEmptyChain($opArray, $ip, $continuation)) {
                return 'Empty catch clauses cannot be distinguished from a union catch.';
            }
            $parsed = self::clause($opArray, $ip, $continuation);
            if (is_string($parsed)) {
                return $parsed;
            }
            $clauses[] = $parsed['clause'];
            if ($parsed['next'] === null) {
                return $clauses;
            }
            $ip = $parsed['next'];
        }

        return 'Catch dispatch at opline ' . $ip . ' does not reach the last catch.';
    }

    /**
     * @return array{clause: CatchDispatch, next: ?int}|string
     */
    private static function clause(OpArray $opArray, int $ip, int $continuation): array|string
    {
        $types = [];
        $oplines = [];
        $variable = null;
        $known = false;
        $line = 0;
        $origin = $ip;
        $guard = 0;
        while ($guard++ < 64) {
            $catch = $opArray->instruction($ip);
            if ($catch === null || $catch->opcode !== 'CATCH') {
                return 'Catch dispatch at opline ' . $ip . ' is not a CATCH instruction.';
            }
            $name = self::className($opArray, $catch);
            if ($name === null) {
                return 'CATCH at opline ' . $catch->index . ' has no exception class name.';
            }
            $caught = self::variable($catch);
            if ($caught['error'] !== null) {
                return $caught['error'];
            }
            if ($known && $caught['name'] !== $variable) {
                return 'CATCH instructions at opline ' . $origin . ' do not agree on the caught variable.';
            }
            $variable = $caught['name'];
            $known = true;
            if ($types === []) {
                $line = $catch->line;
            }
            $types[] = $name;
            $oplines[] = $catch->index;
            $last = ($catch->extendedValue & self::LAST_CATCH) !== 0;
            if ($last) {
                $bodyStart = $catch->index + 1;
                if ($bodyStart > $continuation) {
                    return 'CATCH at opline ' . $catch->index . ' starts after the continuation.';
                }

                return [
                    'clause' => new CatchDispatch($origin, $line, $types, $oplines, $variable, $bodyStart, $continuation),
                    'next' => null,
                ];
            }
            if ($catch->op2->kind !== 'jump' || !is_int($catch->op2->target)) {
                return 'CATCH at opline ' . $catch->index . ' is not the last catch and has no catch_next jump.';
            }
            $next = $catch->op2->target;
            if ($next <= $catch->index || $next >= $continuation) {
                return 'catch_next of CATCH at opline ' . $catch->index . ' does not land on a later catch.';
            }
            $follower = $opArray->instruction($catch->index + 1);
            $linked = $follower !== null
                && $follower->opcode === 'JMP'
                && $follower->op1->kind === 'jump'
                && is_int($follower->op1->target)
                && $next === $catch->index + 2
                && $opArray->instruction($next)?->opcode === 'CATCH';
            if ($linked) {
                $target = $follower->op1->target;
                $nextCatch = $opArray->instruction($next);
                $nextVariable = $nextCatch instanceof Instruction ? self::variable($nextCatch) : ['name' => null, 'error' => 'CATCH at opline ' . $next . ' is missing.'];
                $sharesBody = is_int($target)
                    && $target !== $continuation
                    && self::unionBody($opArray, $next, $target) === $target;
                if ($sharesBody) {
                    if (
                        !$nextCatch instanceof Instruction
                        || $nextCatch->line !== $catch->line
                        || $nextVariable['error'] !== null
                        || $nextVariable['name'] !== $variable
                    ) {
                        return 'CATCH instructions at opline ' . $origin . ' do not agree on a shared catch body.';
                    }
                    $ip = $next;
                    continue;
                }
                if ($target !== $continuation) {
                    return 'CATCH at opline ' . $catch->index . ' jumps to opline ' . (string) $target . ', which is not its catch body.';
                }
                if ($nextVariable['error'] !== null) {
                    return $nextVariable['error'];
                }

                return [
                    'clause' => new CatchDispatch($origin, $line, $types, $oplines, $variable, $catch->index + 1, $next),
                    'next' => $next,
                ];
            }
            if ($opArray->instruction($next)?->opcode !== 'CATCH') {
                return 'catch_next of CATCH at opline ' . $catch->index . ' does not land on a CATCH instruction.';
            }

            return [
                'clause' => new CatchDispatch($origin, $line, $types, $oplines, $variable, $catch->index + 1, $next),
                'next' => $next,
            ];
        }

        return 'Catch dispatch at opline ' . $origin . ' does not reach the last catch.';
    }

    private static function unionBody(OpArray $opArray, int $ip, int $target): ?int
    {
        $guard = 0;
        while ($guard++ < 64) {
            $catch = $opArray->instruction($ip);
            if ($catch === null || $catch->opcode !== 'CATCH') {
                return null;
            }
            if ($catch->index + 1 === $target) {
                return $target;
            }
            if (($catch->extendedValue & self::LAST_CATCH) !== 0) {
                return null;
            }
            $jump = $opArray->instruction($catch->index + 1);
            if (
                $jump === null
                || $jump->opcode !== 'JMP'
                || $jump->op1->kind !== 'jump'
                || $jump->op1->target !== $target
                || $catch->op2->kind !== 'jump'
                || $catch->op2->target !== $catch->index + 2
            ) {
                return null;
            }
            $ip = $catch->index + 2;
        }

        return null;
    }

    private static function ambiguousEmptyChain(OpArray $opArray, int $ip, int $continuation): bool
    {
        $first = $opArray->instruction($ip);
        if ($first === null || $first->opcode !== 'CATCH') {
            return false;
        }
        $variable = self::variable($first);
        if ($variable['error'] !== null) {
            return false;
        }
        $line = $first->line;
        $count = 0;
        $guard = 0;
        while ($guard++ < 64) {
            $catch = $opArray->instruction($ip);
            if ($catch === null || $catch->opcode !== 'CATCH' || $catch->line !== $line) {
                return false;
            }
            $caught = self::variable($catch);
            if ($caught['error'] !== null || $caught['name'] !== $variable['name']) {
                return false;
            }
            $count++;
            if (($catch->extendedValue & self::LAST_CATCH) !== 0) {
                return $count >= 2 && $catch->index + 1 === $continuation;
            }
            $jump = $opArray->instruction($catch->index + 1);
            if (
                $jump === null
                || $jump->opcode !== 'JMP'
                || $jump->op1->kind !== 'jump'
                || $jump->op1->target !== $continuation
                || $catch->op2->kind !== 'jump'
                || $catch->op2->target !== $catch->index + 2
                || $opArray->instruction($catch->index + 2)?->opcode !== 'CATCH'
            ) {
                return false;
            }
            $ip = $catch->index + 2;
        }

        return false;
    }

    private static function className(OpArray $opArray, Instruction $instruction): ?string
    {
        if ($instruction->op1->kind !== 'const' || !is_int($instruction->op1->index)) {
            return null;
        }
        $literal = $opArray->literal($instruction->op1->index);
        if ($literal === null || $literal->type !== 'string' || !is_string($literal->value) || $literal->value === '') {
            return null;
        }

        return $literal->value;
    }

    /**
     * @return array{name: ?string, error: ?string}
     */
    private static function variable(Instruction $instruction): array
    {
        if ($instruction->result->kind === 'unused') {
            return ['name' => null, 'error' => null];
        }
        if ($instruction->result->kind === 'cv' && is_string($instruction->result->name) && $instruction->result->name !== '') {
            return ['name' => $instruction->result->name, 'error' => null];
        }

        return [
            'name' => null,
            'error' => 'CATCH at opline ' . $instruction->index . ' does not name the caught variable.',
        ];
    }
}
