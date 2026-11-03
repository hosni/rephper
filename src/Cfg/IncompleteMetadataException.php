<?php

declare(strict_types=1);

namespace Rephper\Cfg;

/**
 * The IR is valid, but optional metadata required to resolve a target is absent.
 *
 * breaks and labels are null when the compiler context was not the op array
 * being exported. That is different from a jump that points outside the op array.
 */
class IncompleteMetadataException extends CfgException
{
}
