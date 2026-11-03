<?php

declare(strict_types=1);

namespace Rephper\Ir;

final class PhpBuild
{
    public function __construct(
        public readonly string $version,
        public readonly int $versionId,
        public readonly int $api,
        public readonly int $zendModuleApi,
        public readonly int $zendExtensionApi,
        public readonly bool $zts,
        public readonly bool $debug,
    ) {
    }
}
