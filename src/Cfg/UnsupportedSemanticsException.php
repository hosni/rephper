<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * The IR is valid, and this PHP version's control-flow semantics do not cover the opcode.
 */
class UnsupportedSemanticsException extends CfgException
{
}
