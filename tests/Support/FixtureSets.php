<?php

declare(strict_types=1);

namespace Rephper\Tests\Support;

/**
 * Fixture discovery shared by the version checks and the exporter tests.
 */
final class FixtureSets
{
    /** @var list<string> */
    public const SUPPORTED = ['8.4'];

    public static function atLeast(string $actual, string $minimum): bool
    {
        return version_compare($actual, $minimum, '>=');
    }

    /**
     * @param list<string> $supported
     * @return list<string>
     */
    public static function selectable(string $actual, array $supported): array
    {
        $selected = [];
        foreach ($supported as $version) {
            if (self::atLeast($actual, $version)) {
                $selected[] = $version;
            }
        }

        return $selected;
    }

    public static function releaseIsSupported(string $release): bool
    {
        foreach (self::SUPPORTED as $version) {
            $parts = explode('.', $version);
            $upper = $parts[0] . '.' . ((int) $parts[1] + 1);
            if (version_compare($release, $version, '>=') && version_compare($release, $upper, '<')) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<string>
     */
    public static function languageVersions(string $root, string $release): array
    {
        $versions = [];
        foreach (glob($root . '/tests/Fixtures/PHP/*', GLOB_ONLYDIR) ?: [] as $directory) {
            $version = basename($directory);
            if (preg_match('/^\d+\.\d+$/', $version) !== 1) {
                continue;
            }
            if (self::atLeast($release, $version)) {
                $versions[] = $version;
            }
        }

        usort($versions, static function (string $left, string $right): int {
            return version_compare($left, $right);
        });

        return $versions;
    }

    /**
     * @return list<string>
     */
    public static function discover(string $root, string $release): array
    {
        $fixtures = glob($root . '/tests/Fixtures/Common/*.php') ?: [];
        foreach (self::languageVersions($root, $release) as $version) {
            foreach (glob($root . '/tests/Fixtures/PHP/' . $version . '/*.php') ?: [] as $fixture) {
                $fixtures[] = $fixture;
            }
        }

        sort($fixtures, SORT_STRING);

        return $fixtures;
    }

    public static function expected(string $root, string $release, string $fixture, string $suffix): string
    {
        $relative = substr($fixture, strlen($root . '/tests/Fixtures/'));
        $relative = preg_replace('/\.php$/', $suffix, $relative);
        $minor = preg_replace('/^(\d+\.\d+).*$/', '$1', $release);

        return $root . '/tests/Expected/PHP/' . $minor . '/' . $relative;
    }

    /**
     * @return array<string, list<string>>
     */
    public static function snippets(): array
    {
        return [
            'Common/Arithmetic.php' => ['Function: calculate', 'Kind: main', ' ADD ', ' RETURN'],
            'Common/Functions.php' => ['Function: greet', 'Kind: main', 'CONST('],
            'Common/Conditions.php' => ['Function: classify', ' JMPZ '],
            'Common/Loops.php' => ['Function: calculateTotal', ' FE_'],
            'Common/Classes.php' => ['Kind: method', 'Class: Calculator', 'Function: add'],
            'Common/Closures.php' => ['Kind: closure', 'Kind: main'],
            'Common/AnonymousClass.php' => ['Kind: method', 'class@anonymous'],
            'PHP/8.4/PropertyHooks.php' => ['Kind: property-hook', '$value::get', '$value::set', 'Class: Box'],
            'Common/Scalars.php' => ['Function: scale', ' ASSIGN_OP '],
            'Common/ControlFlow.php' => ['Function: walk', ' JMPZ ', ' FE_'],
            'Common/Parameters.php' => ['Function: build', ' RECV_VARIADIC '],
            'Common/ArrowFunctions.php' => ['Kind: closure'],
            'Common/Oop.php' => ['Class: Item', 'Class: Base'],
            'Common/Exceptions.php' => [' CATCH ', ' FAST_CALL '],
            'Common/Generators.php' => [' YIELD '],
            'PHP/8.0/MatchExpression.php' => [' MATCH '],
            'PHP/8.1/Enums.php' => ['Class: Status'],
            'PHP/8.2/Readonly.php' => ['Class: Point', 'Function: __construct'],
            'Common/StructuredFlow.php' => ['Function: nested', ' GOTO ', ' SWITCH_', ' BRK '],
            'Common/TryForms.php' => ['Function: caught', ' CATCH ', ' FAST_RET '],
            'Common/Minimal.php' => ['Kind: main', ' RETURN'],
            'Common/Expressions.php' => ['Function: precedence', ' CONCAT ', ' BOOL_NOT ', ' ASSIGN_OP ', ' BW_NOT ', ' STRLEN ', ' ECHO ', ' FETCH_DIM_R ', ' INIT_FCALL ', ' SEND_VAR ', ' DO_ICALL '],
            'Common/Calls.php' => ['Function: nested', ' INIT_FCALL_BY_NAME ', ' SEND_UNPACK ', ' SEND_REF ', ' INIT_DYNAMIC_CALL ', ' INIT_METHOD_CALL ', ' CALLABLE_CONVERT ', ' DO_UCALL '],
            'Common/Statements.php' => ['Function: assignSequence', 'Function: throwLiteral', ' THROW ', ' DO_ICALL ', ' ECHO '],
            'Common/Conditionals.php' => ['Function: nestedIf', ' JMPZ ', ' JMPZ_EX ', ' JMPNZ_EX ', ' SWITCH_', ' GOTO ', ' BOOL_NOT '],
            'Common/LoopAnalysis.php' => ['Function: simpleWhile', 'Function: simpleForeach', ' FE_FETCH_', ' BRK ', ' CONT ', ' GOTO '],
            'Common/WhileDo.php' => ['Function: emptyWhile', 'Function: switchBreak', 'Function: realSwitch', ' BRK ', ' CONT '],
            'Common/ForLoops.php' => ['Function: simple', 'Function: whileAssign', 'Function: assignCond', 'Function: ambiguous', ' BRK ', ' CONT ', ' COALESCE '],
            'Common/ForeachLoops.php' => ['Function: simple', 'Function: withKey', 'Function: byRef', 'Function: keyRef', ' FE_RESET_', ' FE_FETCH_', ' FE_FREE ', ' BRK ', ' CONT '],
            'Common/Switches.php' => ['Function: simpleInt', 'Function: simpleString', ' SWITCH_LONG ', ' SWITCH_STRING ', ' BRK ', ' CONT '],
            'Common/MatchExpressions.php' => ['Function: simpleInt', 'Function: withoutDefault', ' MATCH ', ' MATCH_ERROR '],
            'Common/TryCatch.php' => ['Function: simple', 'Function: ambiguous', ' CATCH ', ' FAST_RET '],
            'Common/Finally.php' => ['Function: simple', 'Function: chained', ' FAST_CALL ', ' FAST_RET '],
        ];
    }
}
