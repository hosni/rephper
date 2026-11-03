<?php

declare(strict_types=1);

namespace Rephper\Stmt;

use RuntimeException;

/**
 * The op array, graph, and expression reconstruction do not describe the same function.
 * An unsupported statement is a diagnostic and an opaque statement, not this exception.
 */
class StatementException extends RuntimeException
{
}
