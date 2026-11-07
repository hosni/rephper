<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Ir\OpArray;
use Rephper\Stmt\StructuredStatements;

/**
 * One ordinary named function.
 *
 * The op array is the signature: name, flags, parameters, and return type.
 * Those fields are not copied. The body is the structured statements of that
 * same op array.
 */
final class FunctionDeclaration
{
    public function __construct(
        public readonly OpArray $opArray,
        public readonly StructuredStatements $body,
    ) {
    }
}
