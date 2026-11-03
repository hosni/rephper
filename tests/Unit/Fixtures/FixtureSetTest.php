<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Fixtures;

use PHPUnit\Framework\TestCase;
use Rephper\Tests\Support\FixtureSets;

final class FixtureSetTest extends TestCase
{
    public function testVersionSelectionKeepsEarlierLanguageFixtures(): void
    {
        $this->assertTrue(FixtureSets::atLeast('8.4.27', '8.4'));
        $this->assertTrue(FixtureSets::atLeast('8.4.0', '8.0'));
        $this->assertFalse(FixtureSets::atLeast('8.3.30', '8.4'));
        $this->assertTrue(version_compare('8.10', '8.9', '>'));
        $this->assertSame(['8.4'], FixtureSets::selectable('8.4.27', ['8.4']));
        $this->assertSame(['8.0', '8.3', '8.4'], FixtureSets::selectable('8.4.27', ['8.0', '8.3', '8.4']));
        $this->assertSame(['8.4'], FixtureSets::selectable('8.4.27', ['8.4', '8.5']));
        $this->assertTrue(FixtureSets::releaseIsSupported('8.4.27'));
        $this->assertFalse(FixtureSets::releaseIsSupported('8.3.30'));
        $this->assertFalse(FixtureSets::releaseIsSupported('8.5.0'));
    }

    public function testPhp84SelectsCommonAndEarlierLanguageFixtures(): void
    {
        $root = dirname(__DIR__, 3);
        $this->assertSame(['8.0', '8.1', '8.2', '8.4'], FixtureSets::languageVersions($root, '8.4.27'));
        $fixtures = FixtureSets::discover($root, '8.4.27');
        $this->assertNotEmpty($fixtures);
        $this->assertNotFalse(array_filter(
            $fixtures,
            static fn (string $path): bool => str_ends_with($path, 'Common/Arithmetic.php')
        ));
        $this->assertNotFalse(array_filter(
            $fixtures,
            static fn (string $path): bool => str_ends_with($path, 'PHP/8.0/MatchExpression.php')
        ));
    }
}
