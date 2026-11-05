<?php

declare(strict_types=1);

namespace Rephper\Tests\Unit\CodeGen;

use PHPUnit\Framework\TestCase;
use Rephper\Application\OpArrayPipeline;
use Rephper\CodeGen\PhpSourceGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\Document;
use Rephper\Ir\OpArray;
use Rephper\Ir\Reader;

final class PipelineTest extends TestCase
{
    public function testCalculateRendersTheAddition(): void
    {
        $source = $this->generate('Common/Arithmetic.json', 'calculate');

        $this->assertSame("<?php\nreturn \$a + \$b;\n", $source);
        $again = $this->generate('Common/Arithmetic.json', 'calculate');
        $this->assertSame($source, $again);
    }

    public function testScalarLiteralsRoundTripFromIr(): void
    {
        $source = $this->generate('Common/Scalars.json', 'main');
        $file = tempnam(sys_get_temp_dir(), 'rephper-scalars-');
        $this->assertNotFalse($file);
        file_put_contents($file, $source);
        try {
            $returned = include $file;
        } finally {
            unlink($file);
        }

        $this->assertSame(1, $returned);
        $this->assertSame([null, true, false, ['inner' => [1, 2]]], $flags);
        $this->assertSame('ab', $label);
        $this->assertSame("\xFF", $binary);
        $this->assertSame("a\0b", $embedded);
        $this->assertIsFloat($negativeZero);
        $this->assertSame(pack('d', -0.0), pack('d', $negativeZero));
        $this->assertIsArray($special);
        $this->assertTrue(is_nan($special[0]));
        $this->assertSame(INF, $special[1]);
        $this->assertStringNotContainsString('?>', $source);
    }

    public function testAnUnresolvedBodyFailsInsteadOfReturningSource(): void
    {
        $this->expectException(SourceGenerationException::class);
        $this->expectExceptionMessage('STRLEN');

        $this->generate('Common/Expressions.json', 'callThenAdd');
    }

    private function generate(string $relative, string $selector): string
    {
        $document = $this->document($relative);
        $opArray = $selector === 'main' ? $document->opArrayNamed(null) : $document->opArrayNamed($selector);
        $this->assertInstanceOf(OpArray::class, $opArray);

        return (new PhpSourceGenerator())->generate((new OpArrayPipeline())->structure($document, $opArray));
    }

    private function document(string $relative): Document
    {
        $json = (string) file_get_contents(dirname(__DIR__, 3) . '/tests/Expected/PHP/8.4/' . $relative);
        $json = str_replace('"version": "PHP_VERSION"', '"version": "8.4.27"', $json);
        $json = str_replace('"version_id": 0', '"version_id": 80427', $json);

        return (new Reader())->readString($json);
    }
}
