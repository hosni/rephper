<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * One natural loop in an existing control-flow graph.
 *
 * Block ids are the graph's ids. This object does not own or copy blocks.
 * `blocks` is sorted by start opline. `oplines` lists the header start, the
 * header's terminating instruction, and each back-edge instruction.
 * `headerLine` is the line of the header's last instruction.
 */
final class NaturalLoop
{
    public const WHILE = 'while';

    public const DO_WHILE = 'do_while';

    public const FOR = 'for';

    public const FOREACH = 'foreach';

    public const UNKNOWN = 'unknown';

    public const REASON_GOTO = 'loop contains GOTO.';

    public const REASON_ENTRY = 'loop is entered outside its header.';

    public const REASON_OVERLAP = 'loop overlaps another loop without nesting.';

    public const REASON_FOREACH_RESET = 'foreach header is not entered from FE_RESET.';

    public const REASON_FOREACH_JUMP = 'foreach loop has no jump back to its header.';

    public const REASON_NO_RECORD = 'loop has no break/continue record to distinguish while from for.';

    public const REASON_MANY_RECORDS = 'loop matches more than one break/continue record.';

    public const REASON_SHAPE = 'loop shape is not a recognized while, do-while, for, or foreach.';

    /**
     * @param list<LoopEdge> $backEdges
     * @param list<string> $blocks
     * @param list<LoopEdge> $entries
     * @param list<LoopEdge> $exits
     * @param list<string> $childIds
     * @param list<int> $oplines
     */
    public function __construct(
        public readonly string $id,
        public readonly string $headerId,
        public readonly int $headerOpline,
        public readonly int $headerLine,
        public readonly array $backEdges,
        public readonly array $blocks,
        public readonly array $entries,
        public readonly array $exits,
        public readonly ?string $parentId,
        public readonly array $childIds,
        public readonly string $kind,
        public readonly ?string $reason,
        public readonly array $oplines,
    ) {
    }

    public function contains(string $blockId): bool
    {
        return in_array($blockId, $this->blocks, true);
    }
}
