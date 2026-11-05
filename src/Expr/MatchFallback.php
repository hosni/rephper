<?php

declare(strict_types=1);

namespace Rephper\Expr;

use RuntimeException;

/**
 * A jump-table match that this pass will not turn into a MatchExpression.
 */
final class MatchFallback extends RuntimeException
{
}
