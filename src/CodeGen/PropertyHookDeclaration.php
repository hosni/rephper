<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Ir\OpArray;
use Rephper\Stmt\StructuredStatements;

/**
 * One property hook declared in this file.
 *
 * The op array is the hook metadata: kind, flags, the set parameter, attributes,
 * and the doc comment. The body is that same op array's structured statements.
 * An abstract hook has no statements.
 */
final class PropertyHookDeclaration
{
    public function __construct(
        public readonly OpArray $opArray,
        public readonly StructuredStatements $body,
    ) {
    }
}
