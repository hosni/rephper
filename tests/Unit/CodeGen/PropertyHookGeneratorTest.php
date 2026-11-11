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

final class PropertyHookGeneratorTest extends TestCase
{
    #[DataProvider('sources')]
    public function testHookedPropertiesAreReconstructed(string $fixture, string $expected): void
    {
        $source = $this->generate($fixture);

        $this->assertSame($expected, $source);
        $this->assertSame($source, $this->generate($fixture));
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertStringNotContainsString('?>', $source);
        $this->assertStringNotContainsString('function $', $source);
        $this->assertStringNotContainsString(' virtual ', $source);
        $this->assertStringNotContainsString('public get', $source);
        $this->assertStringNotContainsString('private get', $source);
        $this->assertStringNotContainsString('protected get', $source);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function sources(): array
    {
        return [
            'both hooks' => ['PHP/8.4/PropertyHooks.json', <<<'PHP'
<?php

class Box
{
    public int $value = 1 {
        get
        {
            return $this->value + 1;
        }
        set(int $value)
        {
            $this->value = $value;
        }
    }
}

PHP],
            'get only' => ['PHP/8.4/PropertyHookGet.json', <<<'PHP'
<?php

class GetOnly
{
    public int $value = 1 {
        get
        {
            return $this->value + 1;
        }
    }
}

PHP],
            'set only' => ['PHP/8.4/PropertyHookSet.json', <<<'PHP'
<?php

class SetOnly
{
    public int $value = 1 {
        set(int $value)
        {
            $this->value = $value * 2;
        }
    }
}

PHP],
            'hook order' => ['PHP/8.4/PropertyHookOrder.json', <<<'PHP'
<?php

class SetThenGet
{
    public int $value = 1 {
        set(int $value)
        {
            $this->value = $value;
        }
        get
        {
            return $this->value + 1;
        }
    }
}

class OneLine
{
    public int $value = 1 {
        get
        {
            return $this->value;
        }
        set(int $value)
        {
            $this->value = $value;
        }
    }
}

PHP],
            'virtual' => ['PHP/8.4/PropertyHookVirtual.json', <<<'PHP'
<?php

class VirtualGet
{
    public int $value {
        get
        {
            return 7;
        }
    }
}

PHP],
            'abstract' => ['PHP/8.4/PropertyHookAbstract.json', <<<'PHP'
<?php

abstract class AbstractGet
{
    abstract public int $value {
        get;
    }
}

abstract class AbstractMixed
{
    abstract public int $value {
        get
        {
            return 1;
        }
        set(int $value);
    }
}

PHP],
            'final' => ['PHP/8.4/PropertyHookFinal.json', <<<'PHP'
<?php

class FinalHook
{
    public int $value = 1 {
        final get
        {
            return $this->value + 1;
        }
        set(int $value)
        {
            $this->value = $value;
        }
    }
}

class FinalHookChild extends FinalHook
{
    public int $value = 1 {
        set(int $value)
        {
            $this->value = $value + 2;
        }
    }
}

class FinalProperty
{
    final public int $value = 1 {
        get
        {
            return $this->value;
        }
    }
}

PHP],
            'set type' => ['PHP/8.4/PropertyHookSetType.json', <<<'PHP'
<?php

declare(strict_types=1);

class WiderSet
{
    public int $value = 1 {
        set(string|int $incoming)
        {
            $this->value = 4;
        }
    }
}

PHP],
            'untyped' => ['PHP/8.4/PropertyHookUntyped.json', <<<'PHP'
<?php

class UntypedHook
{
    public $value = 1 {
        get
        {
            return $this->value;
        }
        set($value)
        {
            $this->value = $value;
        }
    }
}

PHP],
            'attributes' => ['PHP/8.4/PropertyHookAttributes.json', <<<'PHP'
<?php

#[Attribute]
class Note
{
    public function __construct(public string $text)
    {
    }
}

class Attributed
{
    /** The property. */
    #[Note('property')]
    public int $value = 1 {
        /** The getter. */
        #[Note('get')]
        get
        {
            return $this->value;
        }
        /** The setter. */
        #[Note('set')]
        set(#[Note('param')] int $value)
        {
            $this->value = $value;
        }
    }
}

PHP],
            'members' => ['PHP/8.4/PropertyHookMembers.json', <<<'PHP'
<?php

class MixedMembers
{
    public int $plain = 1;

    public int $hooked = 2 {
        get
        {
            return $this->hooked + $this->plain;
        }
    }

    public string $label = 'a';
}

PHP],
            'control' => ['PHP/8.4/PropertyHookControl.json', <<<'PHP'
<?php

class Control
{
    public int $value = 0 {
        get
        {
            $extra = $this->plain;
            if (0 < $extra) {
                return $this->value + $extra;
            }
            return match ($this->value) {
                0 => 1,
                1 => 2,
                2 => 3,
                default => $this->value,
            };
        }
        set(int $value)
        {
            if ($value < 0) {
                $value = 0;
            }
            $this->value = $value;
        }
    }

    public int $plain = 2;
}

PHP],
            'namespace' => ['PHP/8.4/PropertyHookNamespace.json', <<<'PHP'
<?php

namespace App\Domain;

class Box
{
    public int $value = 1 {
        get
        {
            return $this->value;
        }
    }
}

class Child extends \App\Domain\Box
{
    public int $value = 1 {
        set(int $value)
        {
            $this->value = $value + 1;
        }
    }
}

PHP],
            'inheritance' => ['PHP/8.4/PropertyHookInheritance.json', <<<'PHP'
<?php

class ParentBox
{
    public int $value = 1 {
        get
        {
            return $this->value + 1;
        }
        set(int $value)
        {
            $this->value = $value * 2;
        }
    }
}

class ChildGet extends ParentBox
{
    public int $value = 1 {
        get
        {
            return $this->value + 3;
        }
    }
}

class ChildSet extends ParentBox
{
    public int $value = 1 {
        set(int $value)
        {
            $this->value = $value + 4;
        }
    }
}

class ChildSame extends ParentBox
{
    public int $value = 2;
}

PHP],
            'interface' => ['PHP/8.4/PropertyHookInterface.json', <<<'PHP'
<?php

interface Readable
{
    public int $value {
        get;
    }
}

interface Pair
{
    public int $value {
        get;
        set(int $value);
    }
}

class ImplementsReadable implements Readable
{
    public int $value = 1 {
        get
        {
            return $this->value;
        }
    }
}

class ImplementsPair implements Pair
{
    public int $value = 1 {
        get
        {
            return $this->value;
        }
        set(int $value)
        {
            $this->value = $value;
        }
    }
}

PHP],
            'visibility' => ['PHP/8.4/PropertyHookVisibility.json', <<<'PHP'
<?php

class Asymmetric
{
    public private(set) int $locked = 1 {
        set(int $value)
        {
            $this->locked = $value;
        }
    }

    public protected(set) int $guarded = 2 {
        get
        {
            return $this->guarded;
        }
    }

    final public protected(set) int $marked = 3 {
        get
        {
            return $this->marked;
        }
    }
}

class Visibilities
{
    public int $pub = 1 {
        get
        {
            return $this->pub;
        }
    }

    protected int $pro = 2 {
        get
        {
            return $this->pro;
        }
    }

    private int $pri = 3 {
        get
        {
            return $this->pri;
        }
    }

    public function read(): string
    {
        return $this->pub . $this->pro . $this->pri;
    }
}

PHP],
            'reference' => ['PHP/8.4/PropertyHookReference.json', <<<'PHP'
<?php

class ByRef
{
    public int $value = 1 {
        &get
        {
            return $this->value;
        }
    }
}

class FinalRef
{
    public int $value = 1 {
        final &get
        {
            return $this->value;
        }
    }
}

PHP],
            'promoted' => ['PHP/8.4/PropertyHookPromoted.json', <<<'PHP'
<?php

class Promoted
{
    public function __construct(public int $value = 1 {
        get
        {
            return $this->value + 1;
        }
        set(int $value)
        {
            $this->value = $value;
        }
    }, public string $name = 'n')
    {
    }
}

PHP],
        ];
    }

    #[DataProvider('behavior')]
    public function testHookedPropertiesKeepTheirBehavior(string $fixture, string $code): void
    {
        $original = dirname(__DIR__, 3) . '/tests/Fixtures/' . $fixture;
        $generated = preg_replace('/\.php$/', '.json', $fixture);
        $this->assertIsString($generated);

        $this->assertSame($this->executePath($original, $code), $this->execute($generated, $code));
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function behavior(): array
    {
        return [
            'both hooks' => ['PHP/8.4/PropertyHooks.php', '$b = new Box(); echo $b->value; $b->value = 5; echo $b->value;'],
            'get only' => ['PHP/8.4/PropertyHookGet.php', '$o = new GetOnly(); echo $o->value; $o->value = 5; echo $o->value;'],
            'set only' => ['PHP/8.4/PropertyHookSet.php', '$o = new SetOnly(); echo $o->value; $o->value = 3; echo $o->value;'],
            'hook order' => ['PHP/8.4/PropertyHookOrder.php', '$a = new SetThenGet(); echo $a->value; $a->value = 4; echo $a->value; $b = new OneLine(); echo $b->value; $b->value = 8; echo $b->value;'],
            'virtual' => ['PHP/8.4/PropertyHookVirtual.php', '$o = new VirtualGet(); echo $o->value; try { $o->value = 1; echo "wrote"; } catch (Error) { echo "nowrite"; }'],
            'abstract' => ['PHP/8.4/PropertyHookAbstract.php', '$g = new ReflectionProperty(AbstractGet::class, "value"); $m = new ReflectionProperty(AbstractMixed::class, "value"); echo $g->isAbstract() ? "ag" : "ng", $g->isVirtual() ? "v" : "b", isset($g->getHooks()["get"]) ? "G" : "-"; echo $m->isAbstract() ? "am" : "nm"; $hooks = $m->getHooks(); echo isset($hooks["get"]) && !$hooks["get"]->isAbstract() ? "gc" : "ga"; echo isset($hooks["set"]) && $hooks["set"]->isAbstract() ? "sa" : "sc";'],
            'final' => ['PHP/8.4/PropertyHookFinal.php', '$c = new FinalHookChild(); echo $c->value; $c->value = 5; echo $c->value; $p = new FinalProperty(); echo $p->value, (new ReflectionProperty(FinalProperty::class, "value"))->isFinal() ? "F" : "f"; echo (new ReflectionProperty(FinalHook::class, "value"))->getHooks()["get"]->isFinal() ? "H" : "h";'],
            'set type' => ['PHP/8.4/PropertyHookSetType.php', '$w = new WiderSet(); $w->value = "nope"; echo $w->value; $w->value = 3; echo $w->value; echo (string) (new ReflectionProperty(WiderSet::class, "value"))->getHook(PropertyHookType::Set)->getParameters()[0]->getType();'],
            'untyped' => ['PHP/8.4/PropertyHookUntyped.php', '$u = new UntypedHook(); echo $u->value; $u->value = "x"; echo $u->value;'],
            'attributes' => ['PHP/8.4/PropertyHookAttributes.php', '$p = new ReflectionProperty(Attributed::class, "value"); echo $p->getDocComment(), $p->getAttributes()[0]->getArguments()[0]; $get = $p->getHook(PropertyHookType::Get); echo $get->getDocComment(), $get->getAttributes()[0]->getArguments()[0]; $set = $p->getHook(PropertyHookType::Set); echo $set->getDocComment(), $set->getParameters()[0]->getAttributes()[0]->getArguments()[0]; $a = new Attributed(); echo $a->value; $a->value = 6; echo $a->value;'],
            'members' => ['PHP/8.4/PropertyHookMembers.php', '$m = new MixedMembers(); echo $m->hooked, $m->plain, $m->label;'],
            'control' => ['PHP/8.4/PropertyHookControl.php', '$c = new Control(); echo $c->value; $c->plain = 0; echo $c->value; $c->value = -5; echo $c->value; $c->value = 3; echo $c->value; $c->value = 1; echo $c->value;'],
            'namespace' => ['PHP/8.4/PropertyHookNamespace.php', '$b = new App\\Domain\\Box(); echo $b->value; $b->value = 4; echo $b->value; $c = new App\\Domain\\Child(); echo $c->value; $c->value = 4; echo $c->value;'],
            'inheritance' => ['PHP/8.4/PropertyHookInheritance.php', '$p = new ParentBox(); echo $p->value; $p->value = 3; echo $p->value; $g = new ChildGet(); echo $g->value; $g->value = 3; echo $g->value; $s = new ChildSet(); echo $s->value; $s->value = 3; echo $s->value; $c = new ChildSame(); echo $c->value; $c->value = 3; echo $c->value;'],
            'interface' => ['PHP/8.4/PropertyHookInterface.php', '$r = new ReflectionProperty(Readable::class, "value"); echo $r->isAbstract() ? "a" : "c", isset($r->getHooks()["get"]) ? "g" : "-"; $i = new ImplementsReadable(); echo $i->value; $p = new ImplementsPair(); echo $p->value; $p->value = 9; echo $p->value;'],
            'visibility' => ['PHP/8.4/PropertyHookVisibility.php', '$a = new Asymmetric(); echo $a->locked, $a->guarded, $a->marked; try { $a->locked = 9; echo "W"; } catch (Error) { echo "L"; } try { $a->guarded = 9; echo "W"; } catch (Error) { echo "G"; } echo (new ReflectionProperty(Asymmetric::class, "marked"))->isFinal() ? "F" : "f"; $v = new Visibilities(); echo $v->read(), $v->pub; try { $v->pro; echo "O"; } catch (Error) { echo "P"; } try { $v->pri; echo "O"; } catch (Error) { echo "R"; }'],
            'reference' => ['PHP/8.4/PropertyHookReference.php', '$b = new ByRef(); $ref = &$b->value; $ref = 9; echo $b->value; $f = new FinalRef(); $r = &$f->value; $r = 4; echo $f->value; $hooks = (new ReflectionProperty(FinalRef::class, "value"))->getHooks(); echo $hooks["get"]->isFinal() ? "F" : "f"; echo (new ReflectionProperty(ByRef::class, "value"))->getHooks()["get"]->returnsReference() ? "&" : "=";'],
            'promoted' => ['PHP/8.4/PropertyHookPromoted.php', '$p = new Promoted(); echo $p->value, $p->name; $p->value = 4; echo $p->value; $q = new Promoted(3, "z"); echo $q->value, $q->name;'],
        ];
    }

    public function testMalformedPropertyHooksFailClosed(): void
    {
        $missingScope = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['scope'] = null;
        });
        $missingKind = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['property_hook'] = null;
        });
        $unknownKind = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['property_hook']['hook'] = 'unset';
            $data['op_arrays'][0]['name'] = '$value::unset';
        });
        $inconsistentName = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['name'] = 'value';
        });
        $missingProperty = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['property_hook']['property'] = 'other';
            $data['op_arrays'][0]['name'] = '$other::get';
        });
        $duplicate = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['property_hook']['hook'] = 'get';
            $data['op_arrays'][1]['name'] = '$value::get';
        });
        $getParameters = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['num_args'] = 1;
            $data['op_arrays'][0]['required_num_args'] = 1;
            $data['op_arrays'][0]['arguments'][] = $data['op_arrays'][1]['arguments'][0];
        });
        $setNone = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['num_args'] = 0;
            $data['op_arrays'][1]['required_num_args'] = 0;
            $data['op_arrays'][1]['arguments'] = [];
        });
        $setTwo = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['num_args'] = 2;
            $data['op_arrays'][1]['required_num_args'] = 2;
            $data['op_arrays'][1]['arguments'][] = $data['op_arrays'][1]['arguments'][0];
        });
        $setReference = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['arguments'][0]['send'] = 'reference';
        });
        $setVariadic = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['arguments'][0]['variadic'] = true;
        });
        $setDefault = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['arguments'][0]['default_literal'] = 0;
        });
        $getReturn = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['return_type']['display'] = 'string';
        });
        $setReturn = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][1]['return_type']['display'] = 'int';
        });
        $missingGetReturn = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['return_type'] = null;
            $data['op_arrays'][0]['flags'] = array_values(array_filter(
                $data['op_arrays'][0]['flags'],
                static fn (string $flag): bool => $flag !== 'has_return_type',
            ));
        });
        $untypedReturn = $this->mutate('PHP/8.4/PropertyHookUntyped.json', static function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['property_hook']['hook'] ?? null) === 'get') {
                    $opArray['return_type'] = ['display' => 'int', 'nullable' => false, 'kind' => 'builtin'];
                    $opArray['flags'][] = 'has_return_type';
                }
            }
        });
        $virtualDefault = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'virtual';
        });
        $abstractConcrete = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['flags'][] = 'abstract';
            $data['classes'][0]['flags'][] = 'implicit_abstract';
            $data['classes'][0]['properties'][0]['flags'][] = 'abstract';
        });
        $interface = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['kind'] = 'interface';
            $data['classes'][0]['properties'][0]['hooks'] = [];
            $data['op_arrays'] = array_values(array_filter(
                $data['op_arrays'],
                static fn (array $opArray): bool => ($opArray['kind'] ?? null) !== 'property-hook',
            ));
        });
        $finalAbstract = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'][] = 'final';
            $data['op_arrays'][0]['flags'][] = 'abstract';
        });
        $generator = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'][] = 'generator';
        });
        $closure = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['instructions'][0]['opcode'] = 'DECLARE_LAMBDA_FUNCTION';
            $data['op_arrays'][0]['instructions'][0]['opcode_number'] = 142;
        });
        $anonymous = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['flags'][] = 'anonymous';
        });
        $conditional = $this->mutate('PHP/8.4/PropertyHooks.json', function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['kind'] ?? null) !== 'main') {
                    continue;
                }
                $branch = [
                    'index' => 0,
                    'opcode' => 'JMPZ',
                    'opcode_number' => 43,
                    'line' => 2,
                    'op1' => ['kind' => 'unused'],
                    'op2' => ['kind' => 'jump', 'target' => 2],
                    'result' => ['kind' => 'unused'],
                    'extended_value' => 0,
                ];
                $declaration = $branch;
                $declaration['index'] = 1;
                $declaration['opcode'] = 'DECLARE_CLASS';
                $declaration['opcode_number'] = 144;
                $declaration['line'] = 3;
                $declaration['op2'] = ['kind' => 'unused'];
                $existing = $opArray['instructions'];
                $this->assertIsArray($existing);
                foreach ($existing as $offset => &$instruction) {
                    $this->assertIsArray($instruction);
                    $instruction['index'] = 2 + $offset;
                }
                unset($instruction);
                $opArray['instructions'] = [$branch, $declaration, ...$existing];
            }
        });
        $parentCall = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['instructions'][0]['opcode'] = 'INIT_PARENT_PROPERTY_HOOK_CALL';
            $data['op_arrays'][0]['instructions'][0]['opcode_number'] = 209;
        });
        $unlisted = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['hooks'] = ['set'];
        });
        $unknownList = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['hooks'][] = 'unset';
        });
        $duplicateList = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            array_unshift($data['classes'][0]['properties'][0]['hooks'], 'get');
        });
        $readonly = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'readonly';
        });
        $static = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'static';
        });
        $orphan = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['scope']['name'] = 'Missing';
        });
        $functionKind = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['kind'] = 'function';
        });
        $virtualNone = $this->mutate('PHP/8.4/PropertyHookVirtual.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['hooks'] = [];
            $data['op_arrays'] = array_values(array_filter(
                $data['op_arrays'],
                static fn (array $opArray): bool => ($opArray['kind'] ?? null) !== 'property-hook',
            ));
        });
        $finalPrivate = $this->mutate('PHP/8.4/PropertyHookVisibility.json', static function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) === '$pri::get') {
                    $opArray['flags'][] = 'final';
                }
            }
        });
        $finalOnPrivateProperty = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'] = array_values(array_filter(
                $data['classes'][0]['properties'][0]['flags'],
                static fn (string $flag): bool => $flag !== 'public',
            ));
            $data['classes'][0]['properties'][0]['flags'][] = 'private';
            $data['op_arrays'][0]['flags'][] = 'final';
        });
        $backedReference = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'][] = 'return_reference';
        });
        $inheritedReference = $this->mutate('PHP/8.4/PropertyHookInheritance.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'][] = 'return_reference';
            $data['classes'][0]['properties'][0]['hooks'] = ['get'];
            $data['op_arrays'] = array_values(array_filter(
                $data['op_arrays'],
                static function (array $opArray): bool {
                    return ($opArray['name'] ?? null) !== '$value::set'
                        || ($opArray['scope']['name'] ?? null) !== 'ParentBox';
                },
            ));
        });
        $readOnlyAsymmetric = $this->mutate('PHP/8.4/PropertyHookVirtual.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'protected_set';
        });
        $writeOnlyAsymmetric = $this->mutate('PHP/8.4/PropertyHookSet.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'virtual';
            $data['classes'][0]['properties'][0]['flags'][] = 'private_set';
            $data['classes'][0]['properties'][0]['default'] = null;
        });

        $this->assertGenerationFails($missingScope, 'property hook value::get has no class scope');
        $this->assertGenerationFails($missingKind, 'property hook $value::get has no hook kind');
        $this->assertGenerationFails($unknownKind, 'has hook kind unset, which is not supported');
        $this->assertGenerationFails($inconsistentName, 'property hook value::get metadata is inconsistent');
        $this->assertGenerationFails($missingProperty, 'property hook other::get does not match a property');
        $this->assertGenerationFails($duplicate, 'property $value declares hook get more than once');
        $this->assertGenerationFails($getParameters, 'property hook Box::$value::get: has parameters');
        $this->assertGenerationFails($setNone, 'property hook Box::$value::set: must have one parameter');
        $this->assertGenerationFails($setTwo, 'property hook Box::$value::set: must have one parameter');
        $this->assertGenerationFails($setReference, 'parameter is not passed by value');
        $this->assertGenerationFails($setVariadic, 'parameter is variadic');
        $this->assertGenerationFails($setDefault, 'parameter has a default value');
        $this->assertGenerationFails($getReturn, 'property hook Box::$value::get: return type metadata is inconsistent');
        $this->assertGenerationFails($setReturn, 'property hook Box::$value::set: return type metadata is inconsistent');
        $this->assertGenerationFails($missingGetReturn, 'property hook Box::$value::get: return type metadata is inconsistent');
        $this->assertGenerationFails($untypedReturn, 'property hook UntypedHook::$value::get: return type metadata is inconsistent');
        $this->assertGenerationFails($virtualDefault, 'property $value is virtual and has a default value');
        $this->assertGenerationFails($abstractConcrete, 'property $value is abstract and has no abstract hook');
        $this->assertGenerationFails($interface, 'interface property $value has no hooks');
        $this->assertGenerationFails($finalAbstract, 'property hook Box::$value::get: is final and abstract');
        $this->assertGenerationFails($generator, 'property hook value::get contains a generator, which is not supported');
        $this->assertGenerationFails($closure, 'closure in property hook value::get is not supported');
        $this->assertGenerationFails($anonymous, 'anonymous class at line 3 is not supported');
        $this->assertGenerationFails($conditional, 'conditional class declaration is not supported');
        $this->assertGenerationFails($parentCall, 'INIT_PARENT_PROPERTY_HOOK_CALL');
        $this->assertGenerationFails($unlisted, 'declares hook get, which is not listed on the property');
        $this->assertGenerationFails($unknownList, 'has hook unset, which is not supported');
        $this->assertGenerationFails($duplicateList, 'lists hook get more than once');
        $this->assertGenerationFails($readonly, 'property $value is readonly and has hooks');
        $this->assertGenerationFails($static, 'property $value is static and has hooks');
        $this->assertGenerationFails($orphan, 'property hook value::get does not belong to a class in this file');
        $this->assertGenerationFails($functionKind, 'property hook value::get metadata is inconsistent');
        $this->assertGenerationFails($virtualNone, 'property $value is virtual and has no hooks');
        $this->assertGenerationFails($finalPrivate, 'property hook Visibilities::$pri::get: is final and private');
        $this->assertGenerationFails($finalOnPrivateProperty, 'property hook Box::$value::get: is final and private');
        $this->assertGenerationFails($backedReference, 'property $value combines a by-reference get with a set hook');
        $this->assertGenerationFails($inheritedReference, 'class ChildSet property $value combines a by-reference get with a set hook');
        $this->assertGenerationFails($readOnlyAsymmetric, 'read-only virtual property $value must not specify asymmetric visibility');
        $this->assertGenerationFails($writeOnlyAsymmetric, 'write-only virtual property $value must not specify asymmetric visibility');
    }

    public function testLegalHookCombinationsStillGenerate(): void
    {
        $virtualReference = $this->mutate('PHP/8.4/PropertyHookVirtual.json', static function (array &$data): void {
            $data['op_arrays'][0]['flags'][] = 'return_reference';
        });
        $virtualReferenceSource = (new PhpFileGenerator())->generate($virtualReference);
        $this->assertStringContainsString('&get', $virtualReferenceSource);
        $this->assertStringNotContainsString('set(', $virtualReferenceSource);

        $virtualBoth = $this->mutate('PHP/8.4/PropertyHooks.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'virtual';
            $data['classes'][0]['properties'][0]['flags'][] = 'private_set';
            $data['classes'][0]['properties'][0]['default'] = null;
            $data['op_arrays'][0]['flags'][] = 'return_reference';
        });
        $virtualBothSource = (new PhpFileGenerator())->generate($virtualBoth);
        $this->assertStringContainsString('private(set)', $virtualBothSource);
        $this->assertStringContainsString('&get', $virtualBothSource);
        $this->assertStringContainsString('set(int $value)', $virtualBothSource);

        $finalProtected = $this->mutate('PHP/8.4/PropertyHookVisibility.json', static function (array &$data): void {
            foreach ($data['op_arrays'] as &$opArray) {
                if (($opArray['name'] ?? null) === '$pro::get') {
                    $opArray['flags'][] = 'final';
                }
            }
        });
        $finalProtectedSource = (new PhpFileGenerator())->generate($finalProtected);
        $this->assertStringContainsString('protected int $pro', $finalProtectedSource);
        $this->assertStringContainsString('final get', $finalProtectedSource);

        $symmetricSet = $this->mutate('PHP/8.4/PropertyHookVirtual.json', static function (array &$data): void {
            $data['classes'][0]['properties'][0]['flags'][] = 'public_set';
        });
        $symmetricSource = (new PhpFileGenerator())->generate($symmetricSet);
        $this->assertStringContainsString('public public(set) int $value', $symmetricSource);
    }

    private function assertGenerationFails(Document $document, string $message): void
    {
        try {
            $source = (new PhpFileGenerator())->generate($document);
            $this->fail('Generated a file: ' . $source);
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString($message, $exception->getMessage());
        }
    }

    private function generate(string $fixture): string
    {
        return (new PhpFileGenerator())->generate($this->document($fixture));
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

    private function temporaryPhp(string $source): string
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-hook-');
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
