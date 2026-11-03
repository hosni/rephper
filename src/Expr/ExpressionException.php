<?php

declare(strict_types=1);

namespace Rephper\Expr;

use RuntimeException;

/**
 * The IR is not usable for expression reconstruction.
 * A supported document that contains an opcode this pass does not fold is a diagnostic, not this exception.
 */
class ExpressionException extends RuntimeException
{
}
