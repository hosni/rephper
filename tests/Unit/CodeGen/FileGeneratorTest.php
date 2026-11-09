<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\CodeGen;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Application\PhpFileGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Tests\Support\Process;

final class FileGeneratorTest extends TestCase
{
    public function testCalculatorIsTheFunctionDeclaration(): void
    {
        $source = $this->generate('Common/Calculator.json');

        $this->assertSame(<<<'PHP'
<?php

function calculator(int $a, int $b): int
{
    return $a + $b;
}

PHP, $source);
        $this->assertSame($source, $this->generate('Common/Calculator.json'));
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertSame(1, substr_count($source, 'function calculator'));
        $this->assertStringNotContainsString('?>', $source);
    }

    public function testCalculatorReturnsTheSum(): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-calc-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $this->generate('Common/Calculator.json'));
        try {
            $result = Process::run([
                'php8.4',
                '-n',
                '-r',
                'require ' . var_export($php, true) . '; echo calculator(1, 2), " ", calculator(10, 20), " ", calculator(-1, 5);',
            ]);
        } finally {
            unlink($php);
        }

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('3 30 4', $result['stdout']);
    }

    public function testStrictTypesIsDeclaredAndEnforced(): void
    {
        $source = $this->generate('Common/FunctionStrict.json');

        $this->assertSame(<<<'PHP'
<?php

declare(strict_types=1);

function calculator(int $a, int $b): int
{
    return $a + $b;
}

PHP, $source);
        $file = tempnam(sys_get_temp_dir(), 'rephper-strict-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source . "try { calculator('1', 2); echo 'coerced'; } catch (TypeError) { echo 'strict'; }\n");
        $result = Process::run(['php8.4', '-n', $php]);
        unlink($php);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('strict', $result['stdout']);

        $weak = tempnam(sys_get_temp_dir(), 'rephper-weak-');
        $this->assertNotFalse($weak);
        $weakPhp = $weak . '.php';
        rename($weak, $weakPhp);
        file_put_contents($weakPhp, $this->generate('Common/Calculator.json') . "echo calculator('1', 2);\n");
        $coerced = Process::run(['php8.4', '-n', $weakPhp]);
        unlink($weakPhp);
        $this->assertSame(0, $coerced['exit'], $coerced['stderr']);
        $this->assertSame('3', $coerced['stdout']);
    }

    public function testAnExistingFunctionAndItsCallSiteStayTogether(): void
    {
        $this->assertSame(<<<'PHP'
<?php

function calculate(int $a, int $b): int
{
    return $a + $b;
}

$result = calculate(10, 20);

PHP, $this->generate('Common/Arithmetic.json'));
        $this->assertSame(<<<'PHP'
<?php

function build(string $name, int $times = 2, string ...$parts): string
{
    return $name;
}

PHP, $this->generate('Common/Parameters.json'));
        $this->assertSame(<<<'PHP'
<?php

function greet(string $name): string
{
    return 'Hello, ' . $name;
}

$message = greet('Rephper');

PHP, $this->generate('Common/Functions.json'));
        $this->assertSame("<?php\n\nreturn null;\n", $this->generate('Common/Minimal.json'));
    }

    public function testSignatureMetadataIsRenderedFromTheIr(): void
    {
        $source = $this->generate('Common/FunctionSignatures.json');
        $again = $this->generate('Common/FunctionSignatures.json');

        $this->assertSame($source, $again);
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertStringNotContainsString('?>', $source);
        $this->assertStringNotContainsString("\r", $source);
        foreach ([
            'function calculator(int $a, int $b): int',
            'function plain($a, $b)',
            'function nullable(?int $a, ?int $b): ?string',
            'function unions(string|int $value): string|int|null',
            'function intersections(Traversable&Countable $value): Traversable&Countable',
            'function dnf((Traversable&Countable)|int $value): (Traversable&Countable)|null',
            'function both((Traversable&Countable)|(Iterator&Stringable)|int|null $value): void',
            'function falseType(false $value): false',
            'function truthy(true $value): true',
            'function mixedish(mixed $value): mixed',
            'function voidish(): void',
            'function iterate(Traversable|array $values): Traversable|array',
            'function neverReturn(Throwable $error): never',
            'function refs(int &$a, string &...$rest): void',
            'function &refReturn(array &$items): array',
            'function collect(int $first, string ...$parts): int',
            'int $max = PHP_INT_MAX',
            'string $eol = PHP_EOL',
            'int $next = PHP_INT_MAX + 1',
            "mixed \$fallback = PHP_EOL ?? 'x'",
            '?int $maybe = null',
            "#[Deprecated(message: 'old', since: '1.0')]\nfunction marked(#[SensitiveParameter] string \$secret = 'x'): void",
            'function nestedFlow(int $n): int',
            'function calls(int $a, int $b): int',
            'return calculator($a, $b);',
            'return match ($n) {',
            'switch ($value) {',
            'try {',
            'catch (RuntimeException $exception) {',
            'finally {',
            '$result = calculator(1, 2);',
        ] as $needle) {
            $this->assertStringContainsString($needle, $source, $needle);
            $this->assertSame(1, substr_count($source, $needle), $needle);
        }

        $position = -1;
        foreach ([
            'function calculator',
            'function plain',
            'function nullable',
            'function unions',
            'function intersections',
            'function dnf',
            'function both',
            'function falseType',
            'function truthy',
            'function mixedish',
            'function voidish',
            'function iterate',
            'function neverReturn',
            'function refs',
            'function &refReturn',
            'function collect',
            'function defaults',
            'function marked',
            'function nestedFlow',
            'function calls',
            'function matched',
            'function switched',
            'function handled',
            '$result = calculator(1, 2);',
        ] as $needle) {
            $found = strpos($source, $needle);
            $this->assertNotFalse($found, $needle);
            $this->assertGreaterThan($position, $found, $needle);
            $position = $found;
        }
    }

    #[DataProvider('rejectedFiles')]
    public function testUnsupportedDeclarationsFailTheFile(string $fixture, string $message): void
    {
        try {
            $source = $this->generate($fixture);
            $this->fail('Generated a file: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function rejectedFiles(): array
    {
        return [
            'anonymous class' => ['Common/AnonymousClass.json', 'anonymous class at line 3 is not supported'],
            'closure' => ['Common/Closures.json', 'closure '],
            'arrow' => ['Common/ArrowFunctions.json', 'closure '],
            'generator' => ['Common/Generators.json', 'generator function sequence is not supported'],
            'namespaced static call' => ['Common/NamespaceStaticCall.json', 'INIT_STATIC_METHOD_CALL'],
            'conditional' => ['Common/FunctionConditional.json', 'function hidden is nested or conditional'],
            'nested' => ['Common/FunctionNested.json', 'function inner is nested or conditional'],
            'const' => ['Common/FileConstant.json', 'const declaration in the main script is not supported'],
            'unreconstructed body' => ['Common/Expressions.json', 'Cannot generate PHP for function'],
        ];
    }

    public function testIncompleteSignatureMetadataFails(): void
    {
        $missingDisplay = $this->mutate('Common/Functions.json', static function (array &$data): void {
            $data['op_arrays'][0]['return_type']['display'] = null;
        });
        $missingReturn = $this->mutate('Common/Functions.json', static function (array &$data): void {
            $data['op_arrays'][0]['return_type'] = null;
        });
        $preferReference = $this->mutate('Common/Parameters.json', static function (array &$data): void {
            $data['op_arrays'][0]['arguments'][0]['send'] = 'prefer_reference';
        });
        $unsupportedDefault = $this->mutate('Common/Parameters.json', static function (array &$data): void {
            $data['op_arrays'][0]['literals'][0] = ['type' => 'unsupported', 'zend_type' => 8];
        });
        $missingTarget = $this->mutate('Common/Calculator.json', static function (array &$data): void {
            $data['op_arrays'][0]['attributes'] = [[
                'name' => 'Deprecated',
                'line' => 3,
                'arguments' => [],
            ]];
        });
        $duplicate = $this->mutate('Common/Calculator.json', static function (array &$data): void {
            $copy = $data['op_arrays'][0];
            $copy['id'] = 2;
            array_splice($data['op_arrays'], 1, 0, [$copy]);
        });
        $disagreement = $this->mutate('Common/FunctionStrict.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'] = array_values(array_filter(
                $data['op_arrays'][0]['flags'],
                static fn (string $flag): bool => $flag !== 'strict_types',
            ));
        });

        $this->assertGenerationFails($missingDisplay, 'display is missing');
        $this->assertGenerationFails($missingReturn, 'return type metadata is incomplete');
        $this->assertGenerationFails($preferReference, 'prefer_reference');
        $this->assertGenerationFails($unsupportedDefault, 'default value of $times cannot be rendered');
        $this->assertGenerationFails($missingTarget, 'attribute target is not in the IR');
        $this->assertGenerationFails($duplicate, 'function calculator is declared more than once');
        $this->assertGenerationFails($disagreement, 'strict_types is not the same');
    }

    #[DataProvider('namespaceFiles')]
    public function testNamespaceFilesAreGeneratedFromTheIr(string $fixture, string $source): void
    {
        $generated = $this->generate($fixture);

        $this->assertSame($source, $generated);
        $this->assertSame($generated, $this->generate($fixture));
        $this->assertSame(1, substr_count($generated, '<?php'));
        $this->assertStringNotContainsString('?>', $generated);
        $this->assertStringNotContainsString('use ', $generated);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function namespaceFiles(): array
    {
        return [
            'unbracketed function' => ['Common/FunctionNamespace.json', <<<'PHP'
<?php

namespace App;

function calculator(int $a, int $b): int
{
    return $a + $b;
}

PHP],
            'unbracketed statement' => ['Common/NamespaceStatement.json', <<<'PHP'
<?php

namespace App;

$x = 1;

PHP],
            'bracketed function' => ['Common/NamespaceBracketed.json', <<<'PHP'
<?php

namespace App;

function calculator(int $a, int $b): int
{
    return $a + $b;
}

PHP],
            'bracketed statements' => ['Common/NamespaceBracketedStatements.json', <<<'PHP'
<?php

namespace App;

$x = 1;

if ($x) {
    $y = 2;
}

$z = $x + 1;

PHP],
            'multiple namespaces' => ['Common/NamespaceMultiple.json', <<<'PHP'
<?php

namespace App {
    function alpha(int $value): int
    {
        return $value + 1;
    }
}

namespace Other {
    function beta(int $value): int
    {
        return $value + 2;
    }
}

PHP],
            'global and named' => ['Common/NamespaceGlobal.json', <<<'PHP'
<?php

namespace {
    function alpha(int $value): int
    {
        return $value + 1;
    }

    $global = alpha(1);
}

namespace App {
    function beta(int $value): int
    {
        return $value + 2;
    }

    $named = beta(1);
}

PHP],
            'reopened namespace' => ['Common/NamespaceReopen.json', <<<'PHP'
<?php

namespace App {
    function alpha(): int
    {
        return 1;
    }
}

namespace Other {
    function beta(): int
    {
        return 2;
    }
}

namespace App {
    function delta(): int
    {
        return 4;
    }

    $value = delta();
}

PHP],
            'function and statement' => ['Common/NamespaceMixed.json', <<<'PHP'
<?php

namespace App;

function calculator(int $a, int $b): int
{
    return $a + $b;
}

$result = calculator(1, 2);

PHP],
            'empty namespace' => ['Common/NamespaceEmpty.json', <<<'PHP'
<?php

namespace App;

PHP],
            'resolved calls' => ['Common/NamespaceCalls.json', <<<'PHP'
<?php

namespace App;

function add(int $a, int $b): int
{
    return $a + $b;
}

function calculate(int $a, int $b): int
{
    return add($a, $b);
}

function absolute(int $a, int $b): int
{
    return \app\add($a, $b);
}

function aliased(int $a, int $b): int
{
    return \app\add($a, $b);
}

PHP],
            'aliased type' => ['Common/NamespaceAlias.json', <<<'PHP'
<?php

namespace App;

function calculate(\App\Services\Calculator $value): int
{
    return 1;
}

PHP],
            'types' => ['Common/NamespaceTypes.json', <<<'PHP'
<?php

namespace App;

function take(\stdClass $value): \RuntimeException
{
    return $value;
}

function local(\App\Widget $value): \App\Widget
{
    return $value;
}

PHP],
            'signature' => ['Common/NamespaceSignature.json', <<<'PHP'
<?php

namespace App;

#[\Deprecated]
function &ref(#[\SensitiveParameter] array &$items, int $max = PHP_INT_MAX, int $full = \PHP_INT_MAX, int $flag = \ArrayObject::STD_PROP_LIST): array
{
    return $items;
}

PHP],
            'strict types' => ['Common/NamespaceStrict.json', <<<'PHP'
<?php

declare(strict_types=1);

namespace App;

function calculator(int $a, int $b): int
{
    return $a + $b;
}

PHP],
            'two unbracketed namespaces' => ['Common/NamespaceUnbracketedMultiple.json', <<<'PHP'
<?php

namespace App {
    $first = 1;
}

namespace Other {
    $second = 2;
}

PHP],
            'same line' => ['Common/NamespaceSameLine.json', <<<'PHP'
<?php

namespace A {
    $x = 1;
}

namespace B {
    $y = 2;
}

PHP],
        ];
    }

    #[DataProvider('classFiles')]
    public function testClassesAreReconstructedFromTheIr(string $fixture, string $source): void
    {
        $generated = $this->generate($fixture);
        $again = $this->generate($fixture);

        $this->assertSame($source, $generated);
        $this->assertSame($generated, $again);
        $this->assertSame(1, substr_count($generated, '<?php'));
        $this->assertStringNotContainsString('?>', $generated);
        $this->assertStringNotContainsString("\r", $generated);
        $file = $this->temporaryPhp($generated);
        try {
            $result = Process::run(['php8.4', '-n', '-l', $file]);
        } finally {
            unlink($file);
        }
        $this->assertSame(0, $result['exit'], $generated . "\n" . $result['stderr']);
        $this->assertStringContainsString('No syntax errors detected', $result['stdout']);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function classFiles(): array
    {
        return [
            'method' => ['Common/Classes.json', <<<'PHP'
<?php

class Calculator
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

PHP],
            'empty' => ['Common/ClassEmpty.json', <<<'PHP'
<?php

class EmptyBox
{
}

PHP],
            'properties' => ['Common/ClassProperties.json', <<<'PHP'
<?php

class Bucket
{
    public $open = null;

    public $openNull = null;

    public int $count = 2;

    protected string $label = 'bucket';

    private bool $ready = true;

    public static int $total = 4;

    public ?int $maybe = null;

    public int $bare;

    public function extra(int $value): int
    {
        return $value + 1;
    }
}

$mark = 1;

PHP],
            'constants' => ['Common/ClassConstants.json', <<<'PHP'
<?php

class Scale
{
    public const int BASE = 10;

    protected const string NAME = 'scale';

    private const FLAG = true;

    public function apply(int $value): int
    {
        return $value + 1;
    }
}

PHP],
            'inheritance' => ['Common/ClassInheritance.json', <<<'PHP'
<?php

$before = 1;

interface Named
{
    public function name(): string;
}

class Base
{
    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

class Item extends Base implements Named
{
    public function name(): string
    {
        return 'item';
    }

    public function mul(int $a, int $b): int
    {
        return $a * $b;
    }
}

$after = $before + 1;

PHP],
            'modifiers' => ['Common/ClassModifiers.json', <<<'PHP'
<?php

abstract class Shape
{
    abstract public function area(): int;

    public function scale(int $factor): int
    {
        return $factor + 1;
    }
}

final class Square extends Shape
{
    public function area(): int
    {
        return 4;
    }
}

PHP],
            'namespace' => ['Common/ClassNamespace.json', <<<'PHP'
<?php

namespace App\Shapes;

class Widget
{
    public function id(): int
    {
        return 7;
    }
}

class Box extends \stdClass
{
    public \App\Shapes\Widget $widget;

    public function label(int $n): int
    {
        return $n + 1;
    }
}

PHP],
            'promotion' => ['Common/ClassPromotion.json', <<<'PHP'
<?php

class Point
{
    public function __construct(public readonly int $x, public int $y = 2, private int $z = 3)
    {
    }

    public function extra(int $value): int
    {
        return $value + 1;
    }
}

PHP],
            'attribute' => ['Common/ClassAttribute.json', <<<'PHP'
<?php

#[AllowDynamicProperties]
class Bag
{
    public function size(int $n): int
    {
        return $n;
    }
}

PHP],
            'readonly' => ['PHP/8.2/Readonly.json', <<<'PHP'
<?php

readonly class Point
{
    public function __construct(public readonly int $x, public readonly int $y)
    {
    }
}

class Measured
{
    public readonly int $width;

    public function __construct(int $width)
    {
        $this->width = $width;
    }
}

PHP],
            'trait declaration' => ['Common/TraitDeclaration.json', <<<'PHP'
<?php

#[Attribute]
class Mark
{
    public function __construct(public string $message)
    {
    }
}

/** Records a label. */
#[Mark('trait')]
trait Label
{
    /** The scale. */
    #[Mark('constant')]
    public const int SCALE = 2;

    /** The stored label. */
    #[Mark('property')]
    public string $label = 'label';

    public function text(): string
    {
        return 'label';
    }
}

class Speaker
{
    use Label;
}

PHP],
            'multiple traits' => ['Common/TraitMultiple.json', <<<'PHP'
<?php

trait One
{
    public function one(): string
    {
        return 'one';
    }
}

trait Two
{
    public function two(): string
    {
        return 'two';
    }
}

class Pair
{
    use One, Two;
}

class Box
{
    use One;

    public string $name = 'box';

    public function label(): string
    {
        return 'box';
    }
}

PHP],
            'trait adaptations' => ['Common/TraitAlias.json', <<<'PHP'
<?php

trait Alpha
{
    public function label(): string
    {
        return 'alpha';
    }

    public function name(): string
    {
        return 'alpha';
    }
}

trait Beta
{
    public function name(): string
    {
        return 'beta';
    }

    public function id(): int
    {
        return 2;
    }
}

trait Gamma
{
    public function name(): string
    {
        return 'gamma';
    }
}

class Item
{
    use Alpha, Beta, Gamma {
        Alpha::name insteadof Beta, Gamma;
        label as title;
        Beta::id as private;
        Alpha::label as heading;
    }

    public string $caption = 'item';

    public function caption(): string
    {
        return 'item';
    }
}

PHP],
            'trait namespace' => ['Common/TraitNamespace.json', <<<'PHP'
<?php

namespace App\Tools;

trait Marker
{
    public function mark(): string
    {
        return 'mark';
    }
}

class Noted
{
    use \App\Tools\Marker;

    public int $count = 1;

    public function extra(): int
    {
        return 4;
    }
}

PHP],
            'trait metadata' => ['Common/ClassTraitMetadata.json', <<<'PHP'
<?php

#[Attribute]
class Note
{
    public function __construct(public string $message)
    {
    }
}

class Base
{
    public int $id = 1;
}

trait Alpha
{
    public string $fromTrait = 'alpha';

    public function label(): string
    {
        return 'alpha';
    }

    public function name(): string
    {
        return 'alpha';
    }
}

trait Beta
{
    public function name(): string
    {
        return 'beta';
    }

    public function id(): int
    {
        return 2;
    }
}

trait Gamma
{
    public function name(): string
    {
        return 'gamma';
    }
}

/** The composed item. */
class Item extends Base
{
    use Alpha, Beta, Gamma {
        Alpha::name insteadof Beta, Gamma;
        label as title;
        Beta::id as private;
    }

    /** The scale. */
    #[Note('unused')]
    public const int SCALE = 1;

    /** The item title. */
    #[Note('use name()')]
    public string $title = 'item';

    public function name(): string
    {
        return 'item';
    }
}

PHP],
            'object properties' => ['Common/Oop.json', <<<'PHP'
<?php

interface Named
{
    public function name(): string;
}

trait Label
{
    public function label(): string
    {
        return 'label';
    }
}

class Base
{
    public int $id = 1;

    public function id(): int
    {
        return $this->id;
    }
}

class Item extends Base implements Named
{
    use Label;

    public string $title = 'item';

    public function name(): string
    {
        return $this->title;
    }
}

PHP],
            'enum' => ['PHP/8.1/Enums.json', <<<'PHP'
<?php

interface Colorful
{
    public function color(): string;
}

enum Status: string implements Colorful
{
    case Open = 'open';

    case Closed = 'closed';

    public function color(): string
    {
        return $this->value;
    }
}

PHP],
            'unit enum' => ['PHP/8.1/EnumUnit.json', <<<'PHP'
<?php

enum Suit
{
    /** Hearts. */
    case Hearts;

    case Diamonds;

    case Clubs;

    case Spades;
}

PHP],
            'empty enum' => ['PHP/8.1/EnumEmpty.json', <<<'PHP'
<?php

enum EmptySuit
{
}

PHP],
            'string enum' => ['PHP/8.1/EnumString.json', <<<'PHP'
<?php

#[Attribute]
class Mark
{
    public function __construct(public string $note = 'n')
    {
    }
}

/** Status values. */
#[Mark('status')]
enum Status: string
{
    /** Open case. */
    #[Deprecated(message: 'old', since: '1.0')]
    #[Mark('open')]
    case Open = 'open';

    case Closed = 'closed';
}

PHP],
            'int enum' => ['PHP/8.1/EnumInt.json', <<<'PHP'
<?php

enum Scale: int
{
    case X = 1;

    public const int N = 2;
}

enum Bits: int
{
    case Zero = 0;

    case Negative = -4;

    case Shifted = 8;

    case Computed = 1 + Scale::N;
}

PHP],
            'enum constants' => ['PHP/8.1/EnumConstants.json', <<<'PHP'
<?php

enum Mix: int
{
    case A = 1;

    public const int N = 2;

    case B = 3;

    final protected const string LABEL = 'mix';

    case C = 4;
}

PHP],
            'enum interfaces' => ['PHP/8.1/EnumInterfaces.json', <<<'PHP'
<?php

interface Colorful
{
    public function color(): string;
}

enum Paint: string implements Colorful, UnitEnum
{
    case Red = 'red';

    public function color(): string
    {
        return 'red';
    }
}

PHP],
            'enum methods' => ['PHP/8.1/EnumMethods.json', <<<'PHP'
<?php

enum Counter
{
    case One;

    case Two;

    public function next(int $n): int
    {
        return $n + 1;
    }

    private static function code(): int
    {
        return 7;
    }
}

PHP],
            'enum trait' => ['PHP/8.1/EnumTrait.json', <<<'PHP'
<?php

trait Label
{
    public function label(): string
    {
        return $this->name;
    }
}

trait Title
{
    public function label(): string
    {
        return 'title';
    }
}

enum Item
{
    use Label, Title {
        Label::label insteadof Title;
        Title::label as heading;
    }

    case Only;
}

PHP],
            'namespaced enum' => ['PHP/8.1/EnumNamespace.json', <<<'PHP'
<?php

namespace App\Domain;

enum Status
{
    case Open;

    case Closed;
}

PHP],
            'enum property fetch' => ['PHP/8.1/EnumFetch.json', <<<'PHP'
<?php

enum Source: int
{
    case X = 4;
}

enum Copy: int
{
    case Y = Source::X->value;
}

PHP],
            'promoted enum property' => ['PHP/8.1/EnumPromoted.json', <<<'PHP'
<?php

enum Token
{
    case One;

    public function __construct(public int $value)
    {
    }
}

PHP],
            'enum scalar mismatch' => ['PHP/8.1/EnumScalar.json', <<<'PHP'
<?php

enum Bad: int
{
    case A = 'no';
}

PHP],
        ];
    }

    public function testGeneratedClassesKeepTheirBehavior(): void
    {
        $this->assertSameBehavior('Common/Classes.php', 'echo (new Calculator())->add(1, 2), " ", (new Calculator())->add(10, 20);');
        $this->assertSameBehavior('Common/ClassEmpty.php', 'echo class_exists(EmptyBox::class) ? "yes" : "no";');
        $this->assertSameBehavior(
            'Common/ClassProperties.php',
            '$bucket = new Bucket(); $mirror = new ReflectionClass(Bucket::class); $bucket->bare = 5; echo ($bucket->open === null ? "n" : "x"), ($bucket->openNull === null ? "n" : "x"), $bucket->count, " ", $mirror->getProperty("label")->getValue($bucket), " ", ($mirror->getProperty("ready")->getValue($bucket) ? "y" : "n"), " ", Bucket::$total, " ", ($bucket->maybe === null ? "n" : "x"), " ", $bucket->bare, " ", $bucket->extra(3), " ", $mark;',
        );
        $this->assertSameBehavior(
            'Common/ClassConstants.php',
            '$scale = new Scale(); $mirror = new ReflectionClass(Scale::class); echo Scale::BASE, " ", $mirror->getReflectionConstant("NAME")->getValue(), " ", ($mirror->getReflectionConstant("FLAG")->getValue() ? "y" : "n"), " ", $scale->apply(4);',
        );
        $this->assertSameBehavior(
            'Common/ClassInheritance.php',
            '$item = new Item(); echo $before, " ", $item->add(2, 3), " ", $item->mul(2, 3), " ", $item->name(), " ", ($item instanceof Named ? "y" : "n"), " ", ($item instanceof Base ? "y" : "n"), " ", $after;',
        );
        $this->assertSameBehavior(
            'Common/ClassModifiers.php',
            '$square = new Square(); try { new Shape(); $made = "made"; } catch (Error) { $made = "abstract"; } echo $square->area(), " ", $square->scale(3), " ", $made, " ", ((new ReflectionClass(Square::class))->isFinal() ? "final" : "open");',
        );
        $this->assertSameBehavior(
            'Common/ClassNamespace.php',
            '$box = new \\App\\Shapes\\Box(); echo (new \\App\\Shapes\\Widget())->id(), " ", $box->label(4), " ", ($box instanceof \\stdClass ? "y" : "n"), " ", (string) (new ReflectionProperty(\\App\\Shapes\\Box::class, "widget"))->getType();',
        );
        $this->assertSameBehavior(
            'Common/ClassPromotion.php',
            '$first = new Point(1); $second = new Point(4, 5, 6); echo $first->x, " ", (new ReflectionProperty(Point::class, "y"))->getValue($first), " ", (new ReflectionProperty(Point::class, "z"))->getValue($first), " ", $first->extra(5), " ", $second->x, " ", (new ReflectionProperty(Point::class, "y"))->getValue($second), " ", (new ReflectionProperty(Point::class, "z"))->getValue($second);',
        );
        $this->assertSameBehavior('Common/ClassAttribute.php', '$bag = new Bag(); $bag->extra = 5; echo $bag->extra, " ", $bag->size(2);');
        $this->assertSame('3 4 8', $this->execute('PHP/8.2/Readonly.json', '$point = new Point(3, 4); $measured = new Measured(8); echo $point->x, " ", $point->y, " ", $measured->width;'));
        $this->assertSameBehavior(
            'Common/TraitDeclaration.php',
            '$speaker = new Speaker(); $label = new ReflectionClass(Label::class); echo $speaker->text(), " ", $speaker->label, " ", Speaker::SCALE, " ", $label->getDocComment(), " ", $label->getProperty("label")->getDocComment(), " ", $label->getReflectionConstant("SCALE")->getDocComment(), " ", $label->getAttributes()[0]->getName(), " ", $label->getAttributes()[0]->getArguments()[0], " ", $label->getProperty("label")->getAttributes()[0]->getArguments()[0], " ", $label->getReflectionConstant("SCALE")->getAttributes()[0]->getArguments()[0];',
        );
        $this->assertSameBehavior(
            'Common/TraitMultiple.php',
            '$pair = new Pair(); $box = new Box(); echo $pair->one(), " ", $pair->two(), " ", $box->one(), " ", $box->label(), " ", $box->name;',
        );
        $this->assertSameBehavior(
            'Common/TraitAlias.php',
            '$item = new Item(); $mirror = new ReflectionClass(Item::class); echo $item->name(), " ", $item->title(), " ", $item->heading(), " ", $item->label(), " ", $item->caption(), " ", $item->caption, " ", ($mirror->getMethod("id")->isPrivate() ? "private" : "public"), " ", $mirror->getMethod("id")->invoke($item);',
        );
        $this->assertSameBehavior(
            'Common/TraitNamespace.php',
            '$noted = new \\App\\Tools\\Noted(); echo $noted->mark(), " ", $noted->extra(), " ", $noted->count;',
        );
        $this->assertSameBehavior(
            'Common/ClassTraitMetadata.php',
            '$item = new Item(); $mirror = new ReflectionClass(Item::class); echo $item->name(), " ", $item->title(), " ", $item->fromTrait, " ", $item->title, " ", Item::SCALE, " ", ($mirror->getMethod("id")->isPrivate() ? "p" : "o"), " ", $mirror->getMethod("id")->invoke($item), " ", $mirror->getDocComment(), " ", $mirror->getProperty("title")->getDocComment(), " ", $mirror->getReflectionConstant("SCALE")->getDocComment();',
        );
        $namespaced = $this->generate('Common/TraitNamespace.json');
        $this->assertStringContainsString('use \\App\\Tools\\Marker;', $namespaced);
        $this->assertStringNotContainsString(' as ', $namespaced);
        $this->assertSameBehavior(
            'Common/Oop.php',
            '$item = new Item(); echo $item->id(), " ", $item->name(), " ", $item->label(), " ", $item->title, " ", $item->id;',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/Enums.php',
            'echo Status::Open->color(), " ", Status::Closed->value, " ", Status::Open->name, " ", (Status::Open instanceof Colorful ? "y" : "n");',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumUnit.php',
            'echo Suit::Hearts->name, " ", count(Suit::cases()), " ", (new ReflectionEnum(Suit::class))->getCase("Hearts")->getDocComment();',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumEmpty.php',
            'echo count(EmptySuit::cases()), " ", ((new ReflectionClass(EmptySuit::class))->hasProperty("name") ? "y" : "n");',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumString.php',
            '$mirror = new ReflectionEnum(Status::class); $open = $mirror->getCase("Open"); echo Status::Open->value, " ", Status::Closed->value, " ", $mirror->getDocComment(), " ", $open->getDocComment(), " ", $mirror->getAttributes()[0]->getName(), " ", $mirror->getAttributes()[0]->getArguments()[0], " ", $open->getAttributes()[0]->getName(), " ", $open->getAttributes()[1]->getArguments()[0];',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumInt.php',
            'echo Bits::Zero->value, " ", Bits::Negative->value, " ", Bits::Shifted->value, " ", Bits::Computed->value;',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumConstants.php',
            '$mirror = new ReflectionClass(Mix::class); $label = $mirror->getReflectionConstant("LABEL"); echo Mix::A->value, " ", Mix::N, " ", Mix::B->value, " ", Mix::C->value, " ", $label->getValue(), " ", ($label->isFinal() ? "f" : "o"), " ", ($label->isProtected() ? "p" : "o");',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumMethods.php',
            '$mirror = new ReflectionClass(Counter::class); echo Counter::One->next(3), " ", Counter::Two->name, " ", $mirror->getMethod("code")->invoke(null);',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumTrait.php',
            'echo Item::Only->label(), " ", Item::Only->heading(), " ", Item::Only->name;',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumNamespace.php',
            'echo \\App\\Domain\\Status::Open->name, " ", \\App\\Domain\\Status::Closed->name;',
        );
        $this->assertSameEnumBehavior(
            'PHP/8.1/EnumFetch.php',
            'echo Copy::Y->value, " ", Source::X->value;',
        );
    }

    public function testRuntimeInvalidEnumsKeepTheirFailure(): void
    {
        $this->assertSameFailure(
            'PHP/8.1/EnumInterfaces.php',
            'echo "ran";',
            'previously implemented interface UnitEnum',
        );
        $this->assertSameFailure(
            'PHP/8.1/EnumPromoted.php',
            'echo "ran";',
            'cannot include properties',
        );
        $this->assertSameEnumBehavior('PHP/8.1/EnumScalar.php', 'echo "loaded";');
        $this->assertSameFailure(
            'PHP/8.1/EnumScalar.php',
            'echo Bad::A->value;',
            'does not match enum backing type',
        );
    }

    public function testMalformedEnumMetadataFails(): void
    {
        $suffix = $this->mutate('PHP/8.1/Enums.json', static function (array &$data): void {
            $data['classes'][1]['interfaces'] = ['Colorful'];
        });
        $unitSuffix = $this->mutate('PHP/8.1/EnumEmpty.json', static function (array &$data): void {
            foreach ($data['classes'] as &$class) {
                if (($class['name'] ?? null) === 'EmptySuit') {
                    $class['interfaces'] = [];
                }
            }
        });
        $property = $this->mutate('PHP/8.1/Enums.json', static function (array &$data): void {
            $extra = $data['classes'][1]['properties'][0];
            $extra['name'] = 'extra';
            $extra['flags'] = ['public'];
            $data['classes'][1]['properties'][] = $extra;
        });
        $engine = $this->mutate('PHP/8.1/Enums.json', static function (array &$data): void {
            $data['classes'][1]['properties'][0]['flags'] = ['public', 'readonly'];
        });
        $value = $this->mutate('PHP/8.1/EnumFetch.json', static function (array &$data): void {
            foreach ($data['classes'] as &$class) {
                if (($class['name'] ?? null) === 'Copy') {
                    $class['constants'][0]['value']['ast']['children'][2]['kind'] = 'NEW';
                }
            }
        });
        $nullsafe = $this->mutate('PHP/8.1/EnumFetch.json', static function (array &$data): void {
            foreach ($data['classes'] as &$class) {
                if (($class['name'] ?? null) === 'Copy') {
                    $class['constants'][0]['value']['ast']['children'][2]['kind'] = 'NULLSAFE_PROP';
                }
            }
        });
        $explicit = $this->mutate('PHP/8.1/Enums.json', static function (array &$data): void {
            array_unshift($data['classes'][1]['interfaces'], 'BackedEnum');
        });

        $this->assertGenerationFails($suffix, 'engine interfaces UnitEnum and BackedEnum are missing');
        $this->assertGenerationFails($unitSuffix, 'engine interface UnitEnum is missing');
        $this->assertGenerationFails($property, 'property $extra cannot be declared on an enum');
        $this->assertGenerationFails($engine, 'property $name does not match the engine property');
        $this->assertGenerationFails($value, 'case Y cannot be rendered');
        $source = (new PhpFileGenerator())->generate($nullsafe);
        $this->assertStringContainsString('case Y = Source::X?->value;', $source);
        $kept = (new PhpFileGenerator())->generate($explicit);
        $this->assertStringContainsString('enum Status: string implements BackedEnum, Colorful', $kept);
        $this->assertStringNotContainsString('final enum', $kept);
    }

    public function testIncompleteClassMetadataFails(): void
    {
        $hooks = $this->mutate('Common/Classes.json', static function (array &$data): void {
            $data['classes'][0]['properties'][] = [
                'name' => 'value',
                'flags' => ['public'],
                'type' => ['display' => 'int', 'nullable' => false, 'kind' => 'builtin'],
                'default' => null,
                'hooks' => ['get'],
            ];
        });
        $adaptations = $this->mutate('Common/Classes.json', static function (array &$data): void {
            $data['classes'][0]['traits'][] = 'Label';
            $data['classes'][0]['trait_aliases'][] = [
                'trait' => null,
                'method' => 'add',
                'alias' => null,
                'modifiers' => [],
            ];
        });
        $interfaceTraits = $this->mutate('Common/ClassInheritance.json', static function (array &$data): void {
            foreach ($data['classes'] as &$class) {
                if (($class['name'] ?? null) === 'Named') {
                    $class['traits'][] = 'Label';
                }
            }
        });
        $traitParent = $this->mutate('Common/Classes.json', static function (array &$data): void {
            $data['classes'][0]['kind'] = 'trait';
            $data['classes'][0]['parent'] = 'Base';
            $data['op_arrays'][0]['scope']['kind'] = 'trait';
        });
        $docComment = $this->mutate('Common/Classes.json', static function (array &$data): void {
            $data['classes'][0]['doc_comment'] = 'not a comment';
        });
        $propertyAttribute = $this->mutate('Common/ClassProperties.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['attributes'] = [[
                'name' => 'Note',
                'line' => 3,
                'offset' => 1,
                'arguments' => [],
            ]];
        });
        $conditional = $this->mutate('Common/ClassInheritance.json', static function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['kind'] ?? null) === 'main') {
                    $opArray['instructions'][0]['opcode'] = 'JMPZ';
                }
            }
        });

        $this->assertGenerationFails($hooks, 'property $value lists hook get, which is not declared in this class');
        $this->assertGenerationFails($adaptations, 'does not change the method');
        $this->assertGenerationFails($interfaceTraits, 'cannot use traits');
        $this->assertGenerationFails($traitParent, 'cannot extend or implement');
        $this->assertGenerationFails($docComment, 'doc comment cannot be rendered');
        $this->assertGenerationFails($propertyAttribute, 'attribute target is not on this declaration');
        $this->assertGenerationFails($conditional, 'conditional class declaration is not supported');
    }

    public function testTraitRegistrationIsNotAnExecutableStatement(): void
    {
        $associated = $this->mutate('Common/Classes.json', function (array &$data): void {
            $data['classes'][0]['traits'][] = 'Label';
            $this->prefixMain($data, [
                $this->registration('ADD_TRAIT', 0, 4),
                $this->registration('BIND_TRAITS', 1, 5),
            ]);
        });
        $conditional = $this->mutate('Common/Classes.json', function (array &$data): void {
            $data['classes'][0]['traits'][] = 'Label';
            $this->prefixMain($data, [
                $this->registration('JMPZ', 0, 2),
                $this->registration('ADD_TRAIT', 1, 4),
            ]);
        });
        $method = $this->mutate('Common/Classes.json', function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['kind'] ?? null) === 'method') {
                    $opArray['instructions'][] = $this->registration('ADD_TRAIT', count($opArray['instructions']), 6);
                }
            }
        });
        $function = $this->mutate('Common/Calculator.json', function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['kind'] ?? null) === 'function') {
                    $opArray['instructions'][] = $this->registration('BIND_TRAITS', count($opArray['instructions']), 3);
                }
            }
        });
        $outside = $this->mutate('Common/Classes.json', function (array &$data): void {
            $data['classes'][0]['traits'][] = 'Label';
            $this->prefixMain($data, [$this->registration('ADD_TRAIT', 0, 1)]);
        });
        $unused = $this->mutate('Common/Classes.json', function (array &$data): void {
            $this->prefixMain($data, [$this->registration('BIND_TRAITS', 0, 4)]);
        });
        $partial = $this->mutate('Common/Classes.json', function (array &$data): void {
            $data['classes'][0]['traits'][] = 'Label';
            $this->prefixMain($data, [
                $this->registration('ADD_TRAIT', 0, 4),
                $this->registration('BIND_TRAITS', 1, 1),
            ]);
        });

        $this->assertSame(<<<'PHP'
<?php

class Calculator
{
    use Label;

    public function add(int $a, int $b): int
    {
        return $a + $b;
    }
}

PHP, (new PhpFileGenerator())->generate($associated));
        $this->assertGenerationFails($conditional, 'conditional trait use is not supported');
        $this->assertGenerationFails($method, 'trait use in method Calculator::add is not supported');
        $this->assertGenerationFails($function, 'trait use in function calculator is not supported');
        $this->assertGenerationFails($outside, 'cannot be associated with a class declaration');
        $this->assertGenerationFails($unused, 'cannot be associated with a class declaration');
        $this->assertGenerationFails($partial, 'cannot be associated with a class declaration');
    }

    public function testNamespaceMetadataMustAgree(): void
    {
        $missing = $this->mutate('Common/FunctionNamespace.json', static function (array &$data): void {
            unset($data['namespaces']);
        });
        $disagrees = $this->mutate('Common/FunctionNamespace.json', static function (array &$data): void {
            $data['op_arrays'][0]['namespace'] = 'Other';
        });
        $unowned = $this->mutate('Common/NamespaceStatement.json', static function (array &$data): void {
            $data['namespaces'][0]['statements'] = [[99]];
        });
        $extra = $this->mutate('Common/NamespaceStatement.json', static function (array &$data): void {
            $data['namespaces'][0]['statements'] = [];
        });
        $missingFunction = $this->mutate('Common/FunctionNamespace.json', static function (array &$data): void {
            $data['namespaces'][0]['functions'][] = 'missing';
        });

        $this->assertGenerationFails($missing, 'namespace App is not recorded as a namespace region');
        $this->assertGenerationFails($disagrees, 'does not match namespace App at line');
        $this->assertGenerationFails($unowned, 'cannot own the statement at line');
        $this->assertGenerationFails($extra, 'is not in a namespace region');
        $this->assertGenerationFails($missingFunction, 'lists function missing, which is not in the IR');
    }

    public function testNamespacedFunctionsKeepTheirResults(): void
    {
        $this->assertSameBehavior('Common/NamespaceMixed.php', 'echo $result;');
        $this->assertSameBehavior(
            'Common/NamespaceCalls.php',
            'echo \\App\\add(2, 3), " ", \\App\\calculate(2, 3), " ", \\App\\absolute(2, 3), " ", \\App\\aliased(2, 3);',
        );
        $this->assertSameBehavior('Common/NamespaceGlobal.php', 'echo $global, " ", $named, " ", \\alpha(4), " ", \\App\\beta(4);');
        $this->assertSameBehavior('Common/NamespaceMultiple.php', 'echo \\App\\alpha(10), " ", \\Other\\beta(10);');
        $this->assertSameBehavior('Common/NamespaceReopen.php', 'echo \\App\\alpha(), " ", \\Other\\beta(), " ", \\App\\delta(), " ", $value;');
        $this->assertSameBehavior('Common/NamespaceSameLine.php', 'echo $x, " ", $y;');
        $this->assertSameBehavior('Common/NamespaceBracketedStatements.php', 'echo $x, " ", $y, " ", $z;');
        $call = "try { \\App\\calculator('1', 2); echo 'coerced'; } catch (\\TypeError) { echo 'strict'; }\n";
        $original = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Fixtures/Common/NamespaceStrict.php') . $call;
        $generated = $this->generate('Common/NamespaceStrict.json') . $call;
        $this->assertSame('strict', $this->executeSource($original));
        $this->assertSame($this->executeSource($original), $this->executeSource($generated));
    }

    private function assertGenerationFails(Document $document, string $message): void
    {
        try {
            $source = (new PhpFileGenerator())->generate($document);
            $this->fail('Generated a file: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
            $this->assertStringNotContainsString('<?php', $exception->getMessage());
        }
    }

    private function generate(string $fixture): string
    {
        return (new PhpFileGenerator())->generate($this->document($fixture));
    }

    private function assertSameBehavior(string $fixture, string $code): void
    {
        $original = dirname(__DIR__, 3) . '/tests/Fixtures/' . $fixture;
        $generated = preg_replace('/\.php$/', '.json', 'Common/' . basename($fixture));
        $this->assertIsString($generated);
        $this->assertSame($this->executePath($original, $code), $this->execute($generated, $code));
    }

    private function assertSameEnumBehavior(string $fixture, string $code): void
    {
        $original = dirname(__DIR__, 3) . '/tests/Fixtures/' . $fixture;
        $generated = preg_replace('/\.php$/', '.json', $fixture);
        $this->assertIsString($generated);
        $this->assertSame($this->executePath($original, $code), $this->execute($generated, $code));
    }

    private function assertSameFailure(string $fixture, string $code, string $message): void
    {
        $original = dirname(__DIR__, 3) . '/tests/Fixtures/' . $fixture;
        $generated = preg_replace('/\.php$/', '.json', $fixture);
        $this->assertIsString($generated);
        $copy = $this->temporaryPhp($this->generate($generated));
        try {
            $left = $this->runFile($original, $code);
            $right = $this->runFile($copy, $code);
        } finally {
            unlink($copy);
        }
        $this->assertNotSame(0, $left['exit'], $left['stdout'] . $left['stderr']);
        $this->assertNotSame(0, $right['exit'], $right['stdout'] . $right['stderr']);
        $this->assertStringContainsString($message, $left['stdout'] . $left['stderr']);
        $this->assertStringContainsString($message, $right['stdout'] . $right['stderr']);
    }

    /**
     * @return array{exit: int, stdout: string, stderr: string}
     */
    private function runFile(string $path, string $code): array
    {
        return Process::run([
            'php8.4',
            '-n',
            '-d',
            'display_errors=1',
            '-r',
            'require ' . var_export($path, true) . '; ' . $code,
        ]);
    }

    private function execute(string $fixture, string $code): string
    {
        $file = $this->temporaryPhp($this->generate($fixture));
        try {
            return $this->executePath($file, $code);
        } finally {
            unlink($file);
        }
    }

    private function executePath(string $path, string $code): string
    {
        $result = Process::run([
            'php8.4',
            '-n',
            '-r',
            'require ' . var_export($path, true) . '; ' . $code,
        ]);
        $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);

        return $result['stdout'];
    }

    private function executeSource(string $source): string
    {
        $file = $this->temporaryPhp($source);
        try {
            $result = Process::run(['php8.4', '-n', $file]);
            $this->assertSame(0, $result['exit'], $result['stderr'] . $result['stdout']);

            return $result['stdout'];
        } finally {
            unlink($file);
        }
    }

    /**
     * @param array<string, mixed> $data
     * @param list<array<string, mixed>> $prefix
     */
    private function prefixMain(array &$data, array $prefix): void
    {
        foreach ($data['op_arrays'] as &$opArray) {
            if (($opArray['kind'] ?? null) !== 'main') {
                continue;
            }
            $existing = $opArray['instructions'];
            $this->assertIsArray($existing);
            foreach ($existing as $offset => &$instruction) {
                $this->assertIsArray($instruction);
                $instruction['index'] = count($prefix) + $offset;
            }
            unset($instruction);
            $opArray['instructions'] = [...$prefix, ...$existing];

            return;
        }
        $this->fail('the fixture has no main op array');
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(string $opcode, int $index, int $line): array
    {
        return [
            'index' => $index,
            'opcode' => $opcode,
            'opcode_number' => 0,
            'line' => $line,
            'op1' => ['kind' => 'unused'],
            'op2' => ['kind' => 'unused'],
            'result' => ['kind' => 'unused'],
            'extended_value' => 0,
        ];
    }

    private function temporaryPhp(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-ns-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source);

        return $php;
    }

    private function document(string $fixture): Document
    {
        return (new Reader())->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture);
    }

    /**
     * @param callable(array<string, mixed>): void $change
     */
    private function mutate(string $fixture, callable $change): Document
    {
        $data = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($data);
        $change($data);

        return (new Reader())->readString(json_encode($data, JSON_THROW_ON_ERROR));
    }
}
