<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\Ir;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
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
        $document = $this->reader->readFile(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calculatror.json');
        $function = $document->opArrayNamed('calculatror');

        $this->assertNotNull($function);
        $this->assertNull($function->namespace);
        $this->assertArrayNotHasKey('namespace', $function->extra);
    }

    public function testANamespaceIsReadWithoutBecomingExtra(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calculatror.json');
        $json = preg_replace('/"name": "calculatror"/', '"name": "calculatror", "namespace": "App"', $json, 1);
        $this->assertIsString($json);
        $function = $this->reader->readString($json)->opArrayNamed('calculatror');

        $this->assertNotNull($function);
        $this->assertSame('App', $function->namespace);
        $this->assertArrayNotHasKey('namespace', $function->extra);
    }

    public function testAnEmptyNamespaceIsRejected(): void
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/Common/Calculatror.json');
        $json = preg_replace('/"name": "calculatror"/', '"name": "calculatror", "namespace": ""', $json, 1);
        $this->assertIsString($json);
        $this->expectException(IrException::class);
        $this->expectExceptionMessage('namespace');

        $this->reader->readString($json);
    }

    public function testUnsupportedIrVersionIsDistinct(): void
    {
        $this->expectException(UnsupportedIrVersionException::class);

        $this->reader->readString('{"ir_version":2}');
    }
}
