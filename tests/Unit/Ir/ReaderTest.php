<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Ir;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Ir\ClassDeclaration;
use Rephper\Ir\IrException;
use Rephper\Ir\Reader;
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
        $this->assertSame([], $point->properties[0]->extra);
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
        ];
    }

    public function testUnsupportedIrVersionIsDistinct(): void
    {
        $this->expectException(UnsupportedIrVersionException::class);

        $this->reader->readString('{"ir_version":2}');
    }
}
