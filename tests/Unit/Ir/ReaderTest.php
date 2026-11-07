<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Ir;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Ir\ClassConstant;
use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\ClassProperty;
use Rephper\Ir\Document;
use Rephper\Ir\IrException;
use Rephper\Ir\Reader;
use Rephper\Ir\TraitAlias;
use Rephper\Ir\TraitPrecedence;
use Rephper\Ir\UnsupportedIrVersionException;

final class ReaderTest extends TestCase
{
    private Reader $reader;

    protected function setUp(): void
    {
        $this->reader = new Reader();
    }

    public function testArithmeticDocumentPreservesCompilerMetadataAndOperands(): void
    {
        $document = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Arithmetic.json');

        $this->assertSame(1, $document->irVersion);
        $this->assertSame('PHP_VERSION', $document->php->version);
        $this->assertSame(20240924, $document->php->api);
        $this->assertSame('pre_pass_two', $document->compilation->stage);
        $this->assertSame('absolute_opline', $document->compilation->jumps);

        $calculate = $document->opArrayNamed('calculate');
        $this->assertNotNull($calculate);
        $this->assertSame('ADD', $calculate->instructions[2]->opcode);
        $this->assertSame('cv', $calculate->instructions[2]->op1->kind);
    }

    #[DataProvider('invalidJson')]
    public function testInvalidJsonIsRejected(string $json): void
    {
        $this->expectException(IrException::class);

        $this->reader->readString($json);
    }

    /**
     * @return list<array{string}>
     */
    public static function invalidJson(): array
    {
        return [
            ['{'],
            [''],
            ['not json'],
        ];
    }

    public function testMissingIrVersionNamesTheField(): void
    {
        $this->expectException(IrException::class);
        $this->expectExceptionMessage('ir_version');

        $this->reader->readString('{}');
    }

    public function testStringIrVersionIsRejected(): void
    {
        $this->expectException(IrException::class);

        $this->reader->readString('{"ir_version":"1"}');
    }

    public function testAMissingNamespaceMeansTheGlobalNamespace(): void
    {
        $document = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calculator.json');
        $function = $document->opArrayNamed('calculator');

        $this->assertNotNull($function);
        $this->assertNull($function->namespace);
        $this->assertArrayNotHasKey('namespace', $function->extra);
        $this->assertSame([], $document->namespaces);
        $this->assertArrayNotHasKey('namespaces', $document->extra);
    }

    public function testNamespaceRegionsAreReadWithoutBecomingExtra(): void
    {
        $document = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/FunctionNamespace.json');
        $function = $document->opArrayNamed('App\\calculator');

        $this->assertNotNull($function);
        $this->assertSame('App', $function->namespace);
        $this->assertCount(1, $document->namespaces);
        $this->assertSame('App', $document->namespaces[0]->name);
        $this->assertSame(['calculator'], $document->namespaces[0]->functions);
        $this->assertSame([], $document->namespaces[0]->statements);
        $this->assertArrayNotHasKey('namespaces', $document->extra);
        $this->assertSame([], $document->namespaces[0]->extra);
    }

    public function testAnEmptyNamespaceRegionNameIsRejected(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/FunctionNamespace.json');
        $json = preg_replace('/"name": "App"/', '"name": ""', $json, 1);
        $this->assertIsString($json);
        $this->expectException(IrException::class);
        $this->expectExceptionMessage('namespaces');

        $this->reader->readString($json);
    }

    public function testANamespaceIsReadWithoutBecomingExtra(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calculator.json');
        $json = preg_replace('/"name": "calculator"/', '"name": "calculator", "namespace": "App"', $json, 1);
        $this->assertIsString($json);
        $function = $this->reader->readString($json)->opArrayNamed('calculator');

        $this->assertNotNull($function);
        $this->assertSame('App', $function->namespace);
        $this->assertArrayNotHasKey('namespace', $function->extra);
    }

    public function testAnEmptyNamespaceIsRejected(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calculator.json');
        $json = preg_replace('/"name": "calculator"/', '"name": "calculator", "namespace": ""', $json, 1);
        $this->assertIsString($json);
        $this->expectException(IrException::class);
        $this->expectExceptionMessage('namespace');

        $this->reader->readString($json);
    }

    public function testClassMetadataIsReadWithoutBecomingExtra(): void
    {
        $document = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Classes.json');
        $class = $document->classes[0] ?? null;

        $this->assertInstanceOf(ClassDeclaration::class, $class);
        $this->assertSame('Calculator', $class->name);
        $this->assertSame('class', $class->kind);
        $this->assertSame(['linked'], $class->flags);
        $this->assertNull($class->parent);
        $this->assertSame([], $class->interfaces);
        $this->assertSame([], $class->traits);
        $this->assertSame([], $class->properties);
        $this->assertSame([], $class->constants);
        $this->assertSame([], $class->traitAliases);
        $this->assertSame([], $class->traitPrecedences);
        $this->assertNull($class->docComment);
        $this->assertSame([], $class->extra);
        $this->assertArrayNotHasKey('classes', $document->extra);

        $json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Classes.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $json['classes'][0]['origin'] = 'compiler';
        $parsed = $this->reader->readString(json_encode($json, JSON_THROW_ON_ERROR));
        $this->assertSame(['origin' => 'compiler'], $parsed->classes[0]->extra);

        $readonly = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/PHP/8.2/Readonly.json');
        $point = $readonly->classes[0];
        $this->assertSame('Point', $point->name);
        $this->assertContains('readonly', $point->flags);
        $this->assertSame('x', $point->properties[0]->name);
        $this->assertContains('promoted', $point->properties[0]->flags);
        $this->assertNull($point->properties[0]->defaultValue);
        $this->assertSame('int', $point->properties[0]->type['display'] ?? null);
        $this->assertSame([], $point->properties[0]->attributes);
        $this->assertNull($point->properties[0]->docComment);
        $this->assertSame([], $point->properties[0]->extra);

        $scale = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/ClassConstants.json')->classes[0];
        $this->assertSame('Scale', $scale->name);
        $this->assertSame([], $scale->constants[0]->attributes);
        $this->assertNull($scale->constants[0]->docComment);
        $this->assertSame([], $scale->constants[0]->extra);
    }

    public function testOlderClassDocumentsDefaultTheNewMetadata(): void
    {
        $classes = $this->jsonFixture('Common/Classes.json');
        unset(
            $classes['classes'][0]['trait_aliases'],
            $classes['classes'][0]['trait_precedences'],
            $classes['classes'][0]['doc_comment'],
        );
        $class = $this->reader->readString($this->encode($classes))->classes[0];
        $this->assertSame([], $class->traitAliases);
        $this->assertSame([], $class->traitPrecedences);
        $this->assertNull($class->docComment);
        $this->assertSame([], $class->extra);

        $readonly = $this->jsonFixture('PHP/8.2/Readonly.json');
        unset(
            $readonly['classes'][0]['trait_aliases'],
            $readonly['classes'][0]['trait_precedences'],
            $readonly['classes'][0]['doc_comment'],
            $readonly['classes'][0]['properties'][0]['attributes'],
            $readonly['classes'][0]['properties'][0]['doc_comment'],
        );
        $point = $this->reader->readString($this->encode($readonly))->classes[0];
        $this->assertSame([], $point->properties[0]->attributes);
        $this->assertNull($point->properties[0]->docComment);
        $this->assertSame([], $point->properties[0]->extra);
        $this->assertSame([], $point->extra);

        $constants = $this->jsonFixture('Common/ClassConstants.json');
        unset(
            $constants['classes'][0]['constants'][0]['attributes'],
            $constants['classes'][0]['constants'][0]['doc_comment'],
        );
        $scale = $this->reader->readString($this->encode($constants))->classes[0];
        $this->assertSame([], $scale->constants[0]->attributes);
        $this->assertNull($scale->constants[0]->docComment);
        $this->assertSame([], $scale->constants[0]->extra);
    }

    public function testTraitAdaptationsAreReadWithoutBecomingExtra(): void
    {
        $json = $this->jsonFixture('Common/Classes.json');
        $json['classes'][0]['doc_comment'] = '/** The composed item. */';
        $json['classes'][0]['trait_aliases'] = [
            ['trait' => null, 'method' => 'label', 'alias' => 'title', 'modifiers' => []],
            ['trait' => 'Label', 'method' => 'label', 'alias' => null, 'modifiers' => ['protected']],
        ];
        $json['classes'][0]['trait_precedences'] = [
            ['trait' => 'Label', 'method' => 'name', 'instead_of' => ['Id', 'Other']],
        ];
        $json['classes'][0]['properties'] = [[
            'name' => 'title',
            'flags' => ['public'],
            'type' => null,
            'default' => null,
            'hooks' => [],
            'attributes' => [[
                'name' => 'Note',
                'line' => 30,
                'offset' => 0,
                'arguments' => [[
                    'name' => null,
                    'value' => ['type' => 'string', 'value' => 'use name()'],
                ]],
            ]],
            'doc_comment' => '/** The item title. */',
        ]];
        $json['classes'][0]['constants'] = [[
            'name' => 'SCALE',
            'flags' => ['public'],
            'type' => null,
            'value' => ['type' => 'int', 'value' => 1],
            'attributes' => [[
                'name' => 'Note',
                'line' => 34,
                'offset' => 0,
                'arguments' => [],
            ]],
            'doc_comment' => '/** The scale. */',
        ]];

        $class = $this->reader->readString($this->encode($json))->classes[0];
        $this->assertSame('/** The composed item. */', $class->docComment);
        $this->assertSame([], $class->extra);
        $this->assertCount(2, $class->traitAliases);
        $this->assertInstanceOf(TraitAlias::class, $class->traitAliases[0]);
        $this->assertNull($class->traitAliases[0]->trait);
        $this->assertSame('label', $class->traitAliases[0]->method);
        $this->assertSame('title', $class->traitAliases[0]->alias);
        $this->assertSame([], $class->traitAliases[0]->modifiers);
        $this->assertSame('Label', $class->traitAliases[1]->trait);
        $this->assertNull($class->traitAliases[1]->alias);
        $this->assertSame(['protected'], $class->traitAliases[1]->modifiers);
        $this->assertInstanceOf(TraitPrecedence::class, $class->traitPrecedences[0]);
        $this->assertSame('Label', $class->traitPrecedences[0]->trait);
        $this->assertSame('name', $class->traitPrecedences[0]->method);
        $this->assertSame(['Id', 'Other'], $class->traitPrecedences[0]->insteadOf);
        $this->assertSame('/** The item title. */', $class->properties[0]->docComment);
        $this->assertSame('Note', $class->properties[0]->attributes[0]['name'] ?? null);
        $this->assertSame(0, $class->properties[0]->attributes[0]['offset'] ?? null);
        $this->assertSame([], $class->properties[0]->extra);
        $this->assertSame('/** The scale. */', $class->constants[0]->docComment);
        $this->assertSame('Note', $class->constants[0]->attributes[0]['name'] ?? null);
        $this->assertSame([], $class->constants[0]->extra);
    }

    public function testCapturedTraitMetadataIsReadWithoutBecomingExtra(): void
    {
        $document = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/ClassTraitMetadata.json');
        $item = $this->classNamed($document, 'Item');
        $alpha = $this->classNamed($document, 'Alpha');
        $base = $this->classNamed($document, 'Base');

        $this->assertSame([], $item->flags);
        $this->assertSame('Base', $item->parent);
        $this->assertSame(['Alpha', 'Beta'], $item->traits);
        $this->assertSame('/** The composed item. */', $item->docComment);
        $this->assertNull($item->traitAliases[0]->trait);
        $this->assertSame('title', $item->traitAliases[0]->alias);
        $this->assertSame([], $item->traitAliases[0]->modifiers);
        $this->assertSame('Beta', $item->traitAliases[1]->trait);
        $this->assertSame('id', $item->traitAliases[1]->method);
        $this->assertNull($item->traitAliases[1]->alias);
        $this->assertSame(['private'], $item->traitAliases[1]->modifiers);
        $this->assertSame(['Beta', 'Alpha'], $item->traitPrecedences[0]->insteadOf);
        $this->assertSame('Alpha', $item->traitPrecedences[0]->trait);
        $this->assertSame('name', $item->traitPrecedences[0]->method);
        $this->assertSame(
            ['title'],
            array_map(static fn (ClassProperty $property): string => $property->name, $item->properties),
        );
        $this->assertSame('/** The item title. */', $item->properties[0]->docComment);
        $this->assertSame('Note', $item->properties[0]->attributes[0]['name'] ?? null);
        $this->assertSame(
            ['SCALE'],
            array_map(static fn (ClassConstant $constant): string => $constant->name, $item->constants),
        );
        $this->assertSame('/** The scale. */', $item->constants[0]->docComment);
        $this->assertSame([], $item->extra);
        $this->assertSame([], $item->properties[0]->extra);
        $this->assertSame([], $item->constants[0]->extra);
        $this->assertSame(
            ['id'],
            array_map(static fn (ClassProperty $property): string => $property->name, $base->properties),
        );
        $this->assertSame(
            ['fromTrait'],
            array_map(static fn (ClassProperty $property): string => $property->name, $alpha->properties),
        );
        $this->assertContains('linked', $alpha->flags);
        $this->assertSame([], $alpha->traitAliases);
        $this->assertSame([], $alpha->traitPrecedences);
        $this->assertNull($alpha->docComment);
    }

    #[DataProvider('malformedClasses')]
    public function testMalformedClassMetadataIsRejected(string $fixture, string $message, callable $change): void
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);
        $change($json);

        $this->expectException(IrException::class);
        $this->expectExceptionMessage($message);
        $this->reader->readString(json_encode($json, JSON_THROW_ON_ERROR));
    }

    /**
     * @return array<string, array{string, string, callable(array<string, mixed>): void}>
     */
    public static function malformedClasses(): array
    {
        return [
            'row is a list' => ['Common/Classes.json', 'classes[0] must be an object', static function (array &$data): void {
                $data['classes'][0] = ['Calculator'];
            }],
            'name is missing' => ['Common/Classes.json', 'classes[0].name is missing', static function (array &$data): void {
                unset($data['classes'][0]['name']);
            }],
            'kind is unknown' => ['Common/Classes.json', 'classes[0].kind is not a class', static function (array &$data): void {
                $data['classes'][0]['kind'] = 'widget';
            }],
            'parent is an integer' => ['Common/Classes.json', 'classes[0].parent must be a string or null', static function (array &$data): void {
                $data['classes'][0]['parent'] = 1;
            }],
            'interface name is empty' => ['Common/Oop.json', 'classes[3].interfaces[0] must be a non-empty string', static function (array &$data): void {
                $data['classes'][3]['interfaces'][0] = '';
            }],
            'line range is reversed' => ['Common/Classes.json', 'classes[0] has an invalid line range', static function (array &$data): void {
                $data['classes'][0]['line_end'] = 1;
                $data['classes'][0]['line_start'] = 4;
            }],
            'property is a list' => ['PHP/8.2/Readonly.json', 'classes[0].properties[0] must be an object', static function (array &$data): void {
                $data['classes'][0]['properties'][0] = ['x'];
            }],
            'property default is a string' => ['PHP/8.2/Readonly.json', 'classes[0].properties[0].default must be an object or null', static function (array &$data): void {
                $data['classes'][0]['properties'][0]['default'] = 'none';
            }],
            'property type is incomplete' => ['PHP/8.2/Readonly.json', 'classes[0].properties[0].type.display is missing', static function (array &$data): void {
                unset($data['classes'][0]['properties'][0]['type']['display']);
            }],
            'constant value is null' => ['PHP/8.1/Enums.json', 'classes[1].constants[0].value must be an object', static function (array &$data): void {
                $data['classes'][1]['constants'][0]['value'] = null;
            }],
            'enum backing is empty' => ['PHP/8.1/Enums.json', 'classes[1].enum_backing must not be empty', static function (array &$data): void {
                $data['classes'][1]['enum_backing'] = '';
            }],
            'trait aliases is null' => ['Common/Classes.json', 'classes[0].trait_aliases must be an array', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = null;
            }],
            'alias is a list' => ['Common/Classes.json', 'classes[0].trait_aliases[0] must be an object', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [['label']];
            }],
            'alias trait is an integer' => ['Common/Classes.json', 'classes[0].trait_aliases[0].trait must be a string or null', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [self::alias(['trait' => 1])];
            }],
            'alias method is empty' => ['Common/Classes.json', 'classes[0].trait_aliases[0].method must not be empty', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [self::alias(['method' => ''])];
            }],
            'alias name is an integer' => ['Common/Classes.json', 'classes[0].trait_aliases[0].alias must be a string or null', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [self::alias(['alias' => 1])];
            }],
            'alias modifier is static' => ['Common/Classes.json', 'classes[0].trait_aliases[0].modifiers[0] must be public, protected, private, or final', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [self::alias(['modifiers' => ['static']])];
            }],
            'alias has two modifiers' => ['Common/Classes.json', 'classes[0].trait_aliases[0].modifiers must contain at most one modifier', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [self::alias(['modifiers' => ['public', 'final']])];
            }],
            'alias has an unknown field' => ['Common/Classes.json', 'classes[0].trait_aliases[0].note is not a trait alias field', static function (array &$data): void {
                $data['classes'][0]['trait_aliases'] = [self::alias(['note' => 'later'])];
            }],
            'precedence trait is null' => ['Common/Classes.json', 'classes[0].trait_precedences[0].trait must be a string', static function (array &$data): void {
                $data['classes'][0]['trait_precedences'] = [self::precedence(['trait' => null])];
            }],
            'precedence excludes nothing' => ['Common/Classes.json', 'classes[0].trait_precedences[0].instead_of must not be empty', static function (array &$data): void {
                $data['classes'][0]['trait_precedences'] = [self::precedence(['instead_of' => []])];
            }],
            'precedence exclude is empty' => ['Common/Classes.json', 'classes[0].trait_precedences[0].instead_of[0] must be a non-empty string', static function (array &$data): void {
                $data['classes'][0]['trait_precedences'] = [self::precedence(['instead_of' => ['']])];
            }],
            'precedence has an unknown field' => ['Common/Classes.json', 'classes[0].trait_precedences[0].alias is not a trait precedence field', static function (array &$data): void {
                $data['classes'][0]['trait_precedences'] = [self::precedence(['alias' => 'title'])];
            }],
            'class doc comment is an integer' => ['Common/Classes.json', 'classes[0].doc_comment must be a string or null', static function (array &$data): void {
                $data['classes'][0]['doc_comment'] = 1;
            }],
            'property attributes is a string' => ['PHP/8.2/Readonly.json', 'classes[0].properties[0].attributes must be an array', static function (array &$data): void {
                $data['classes'][0]['properties'][0]['attributes'] = 'Note';
            }],
            'property doc comment is an integer' => ['PHP/8.2/Readonly.json', 'classes[0].properties[0].doc_comment must be a string or null', static function (array &$data): void {
                $data['classes'][0]['properties'][0]['doc_comment'] = 1;
            }],
            'constant attribute is a string' => ['Common/ClassConstants.json', 'classes[0].constants[0].attributes[0] must be an object', static function (array &$data): void {
                $data['classes'][0]['constants'][0]['attributes'] = ['Note'];
            }],
            'constant doc comment is an integer' => ['Common/ClassConstants.json', 'classes[0].constants[0].doc_comment must be a string or null', static function (array &$data): void {
                $data['classes'][0]['constants'][0]['doc_comment'] = 1;
            }],
        ];
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function alias(array $overrides): array
    {
        return array_merge([
            'trait' => null,
            'method' => 'label',
            'alias' => 'title',
            'modifiers' => [],
        ], $overrides);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private static function precedence(array $overrides): array
    {
        return array_merge([
            'trait' => 'Label',
            'method' => 'name',
            'instead_of' => ['Id'],
        ], $overrides);
    }

    /**
     * @return array<string, mixed>
     */
    private function jsonFixture(string $fixture): array
    {
        $json = json_decode((string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $fixture), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($json);

        return $json;
    }

    /**
     * @param array<string, mixed> $json
     */
    private function encode(array $json): string
    {
        return json_encode($json, JSON_THROW_ON_ERROR);
    }

    private function classNamed(Document $document, string $name): ClassDeclaration
    {
        foreach ($document->classes as $class) {
            if ($class->name === $name) {
                return $class;
            }
        }

        $this->fail('Missing class ' . $name);
    }

    public function testUnsupportedIrVersionIsDistinct(): void
    {
        $this->expectException(UnsupportedIrVersionException::class);

        $this->reader->readString('{"ir_version":2}');
    }
}
