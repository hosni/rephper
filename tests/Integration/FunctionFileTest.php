<?php

declare(strict_types=1);

namespace Rephper\Tests\Integration;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Rephper\Application\PhpFileGenerator;
use Rephper\CodeGen\SourceGenerationException;
use Rephper\Ir\Document;
use Rephper\Ir\Reader;
use Rephper\Tests\Support\Process;

final class FunctionFileTest extends TestCase
{
    #[DataProvider('files')]
    public function testGeneratedFileLintsAndIsDeterministic(string $fixture): void
    {
        $source = $this->generate($fixture);
        $again = $this->generate($fixture);

        $this->assertSame($source, $again);
        $this->assertSame(1, substr_count($source, '<?php'));
        $this->assertStringStartsWith("<?php\n\n", $source);
        $this->assertStringNotContainsString('?>', $source);
        $this->assertStringNotContainsString("\r", $source);
        $this->lint($source);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function files(): array
    {
        return [
            'calculator' => ['Common/Calculator.json'],
            'signatures' => ['Common/FunctionSignatures.json'],
            'strict' => ['Common/FunctionStrict.json'],
            'arithmetic' => ['Common/Arithmetic.json'],
            'parameters' => ['Common/Parameters.json'],
            'functions' => ['Common/Functions.json'],
            'minimal' => ['Common/Minimal.json'],
            'namespace function' => ['Common/FunctionNamespace.json'],
            'namespace statement' => ['Common/NamespaceStatement.json'],
            'bracketed namespace' => ['Common/NamespaceBracketed.json'],
            'bracketed statements' => ['Common/NamespaceBracketedStatements.json'],
            'multiple namespaces' => ['Common/NamespaceMultiple.json'],
            'global and named' => ['Common/NamespaceGlobal.json'],
            'reopened namespace' => ['Common/NamespaceReopen.json'],
            'namespace mixed' => ['Common/NamespaceMixed.json'],
            'empty namespace' => ['Common/NamespaceEmpty.json'],
            'namespace types' => ['Common/NamespaceTypes.json'],
            'namespace calls' => ['Common/NamespaceCalls.json'],
            'namespace alias' => ['Common/NamespaceAlias.json'],
            'namespace signature' => ['Common/NamespaceSignature.json'],
            'namespace strict' => ['Common/NamespaceStrict.json'],
            'unbracketed namespaces' => ['Common/NamespaceUnbracketedMultiple.json'],
            'same line namespaces' => ['Common/NamespaceSameLine.json'],
            'class' => ['Common/Classes.json'],
            'empty class' => ['Common/ClassEmpty.json'],
            'class properties' => ['Common/ClassProperties.json'],
            'class constants' => ['Common/ClassConstants.json'],
            'class inheritance' => ['Common/ClassInheritance.json'],
            'class modifiers' => ['Common/ClassModifiers.json'],
            'class namespace' => ['Common/ClassNamespace.json'],
            'constructor promotion' => ['Common/ClassPromotion.json'],
            'class attribute' => ['Common/ClassAttribute.json'],
            'readonly class' => ['PHP/8.2/Readonly.json'],
            'enum' => ['PHP/8.1/Enums.json'],
            'unit enum' => ['PHP/8.1/EnumUnit.json'],
            'empty enum' => ['PHP/8.1/EnumEmpty.json'],
            'string enum' => ['PHP/8.1/EnumString.json'],
            'int enum' => ['PHP/8.1/EnumInt.json'],
            'enum constants' => ['PHP/8.1/EnumConstants.json'],
            'enum interfaces' => ['PHP/8.1/EnumInterfaces.json'],
            'enum methods' => ['PHP/8.1/EnumMethods.json'],
            'enum trait' => ['PHP/8.1/EnumTrait.json'],
            'namespaced enum' => ['PHP/8.1/EnumNamespace.json'],
            'enum property fetch' => ['PHP/8.1/EnumFetch.json'],
            'promoted enum property' => ['PHP/8.1/EnumPromoted.json'],
            'enum scalar mismatch' => ['PHP/8.1/EnumScalar.json'],
            'trait declaration' => ['Common/TraitDeclaration.json'],
            'multiple traits' => ['Common/TraitMultiple.json'],
            'trait adaptations' => ['Common/TraitAlias.json'],
            'trait namespace' => ['Common/TraitNamespace.json'],
            'trait metadata' => ['Common/ClassTraitMetadata.json'],
            'property hooks' => ['PHP/8.4/PropertyHooks.json'],
            'get hook' => ['PHP/8.4/PropertyHookGet.json'],
            'set hook' => ['PHP/8.4/PropertyHookSet.json'],
            'hook order' => ['PHP/8.4/PropertyHookOrder.json'],
            'virtual hook' => ['PHP/8.4/PropertyHookVirtual.json'],
            'abstract hook' => ['PHP/8.4/PropertyHookAbstract.json'],
            'final hook' => ['PHP/8.4/PropertyHookFinal.json'],
            'set parameter' => ['PHP/8.4/PropertyHookSetType.json'],
            'untyped hook' => ['PHP/8.4/PropertyHookUntyped.json'],
            'hook attributes' => ['PHP/8.4/PropertyHookAttributes.json'],
            'hooked members' => ['PHP/8.4/PropertyHookMembers.json'],
            'hook control flow' => ['PHP/8.4/PropertyHookControl.json'],
            'namespaced hook' => ['PHP/8.4/PropertyHookNamespace.json'],
            'hook inheritance' => ['PHP/8.4/PropertyHookInheritance.json'],
            'interface hook' => ['PHP/8.4/PropertyHookInterface.json'],
            'hook visibility' => ['PHP/8.4/PropertyHookVisibility.json'],
            'reference hook' => ['PHP/8.4/PropertyHookReference.json'],
            'promoted hook' => ['PHP/8.4/PropertyHookPromoted.json'],
        ];
    }

    public function testCalculatorReturnsTheSum(): void
    {
        $source = $this->generate('Common/Calculator.json');
        $file = tempnam(sys_get_temp_dir(), 'rephper-calc-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source);
        $result = Process::run([
            'php8.4',
            '-n',
            '-r',
            'require ' . var_export($php, true) . '; echo calculator(1, 2);',
        ]);
        unlink($php);

        $this->assertSame(0, $result['exit'], $result['stderr']);
        $this->assertSame('3', $result['stdout']);
        $this->lint($source);
    }

    public function testAnUnsupportedDeclarationDoesNotLeaveASourceFile(): void
    {
        $output = tempnam(sys_get_temp_dir(), 'rephper-file-');
        $this->assertNotFalse($output);
        unlink($output);

        try {
            $source = $this->generate('Common/AnonymousClass.json');
            file_put_contents($output, $source);
            $this->fail('Generated a file that omitted an anonymous class.');
        } catch (SourceGenerationException $exception) {
            $this->assertStringContainsString('anonymous class at line 3 is not supported', $exception->getMessage());
            $this->assertFileDoesNotExist($output);
        }
    }

    private function generate(string $fixture): string
    {
        return (new PhpFileGenerator())->generate($this->document($fixture));
    }

    private function document(string $fixture): Document
    {
        return (new Reader())->readFile(dirname(__DIR__, 2) . '/tests/Expected/PHP/8.4/' . $fixture);
    }

    private function lint(string $source): void
    {
        $file = tempnam(sys_get_temp_dir(), 'rephper-generated-');
        $this->assertNotFalse($file);
        $php = $file . '.php';
        rename($file, $php);
        file_put_contents($php, $source);
        $result = Process::run(['php8.4', '-n', '-l', $php]);
        unlink($php);
        $this->assertSame(0, $result['exit'], $source . "\n" . $result['stdout'] . $result['stderr']);
        $this->assertStringContainsString('No syntax errors detected', $result['stdout']);
    }
}
