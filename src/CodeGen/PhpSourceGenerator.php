<?php

declare(strict_types=1);

namespace Rephper\CodeGen;

use Rephper\Expr\Expression;
use Rephper\Expr\Literal;
use Rephper\Stmt\BreakStatement;
use Rephper\Stmt\CatchClause;
use Rephper\Stmt\CompiledVariableTarget;
use Rephper\Stmt\ConditionalBranch;
use Rephper\Stmt\ContinueStatement;
use Rephper\Stmt\DoWhileStatement;
use Rephper\Stmt\ExpressionStatement;
use Rephper\Stmt\ForStatement;
use Rephper\Stmt\ForeachStatement;
use Rephper\Stmt\IfStatement;
use Rephper\Stmt\OpaqueStatement;
use Rephper\Stmt\ReturnStatement;
use Rephper\Stmt\Statement;
use Rephper\Stmt\StructuredStatements;
use Rephper\Stmt\SwitchBreakStatement;
use Rephper\Stmt\SwitchCase;
use Rephper\Stmt\SwitchContinueStatement;
use Rephper\Stmt\SwitchStatement;
use Rephper\Stmt\ThrowStatement;
use Rephper\Stmt\TryStatement;
use Rephper\Stmt\WhileStatement;

/**
 * Renders one op-array body as a PHP fragment.
 *
 * The fragment starts with an opening tag and has no closing tag. It is the
 * statements of one op array, not a function, class, namespace, or file.
 * Comments and the original whitespace are not reproduced. An unsupported or
 * unresolved node throws, and no source is returned.
 */
final class PhpSourceGenerator
{
    public function __construct(
        private readonly ?NameQualifier $names = null,
    ) {
    }

    private const IDENTIFIER = '/\A[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*\z/';

    private const QUALIFIED_NAME = '/\A\\\\?[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*(?:\\\\[A-Za-z_\x80-\xff][A-Za-z0-9_\x80-\xff]*)*\z/';

    public function generate(StructuredStatements $structured): string
    {
        $body = $this->renderStatements($structured->statements, 0);
        $source = "<?php\n";
        if ($body !== '') {
            $source .= $body . "\n";
        }

        return $source;
    }

    /**
     * Renders statements without an opening tag.
     *
     * Indent 0 separates statements with a blank line, which is the file and
     * op-array body layout. A nested list uses one newline, which is the
     * layout inside a brace.
     *
     * @param list<Statement> $statements
     */
    public function renderStatements(array $statements, int $indent): string
    {
        $chunks = [];
        foreach ($statements as $statement) {
            if (!$statement instanceof Statement) {
                throw new SourceGenerationException('Cannot generate PHP: a statement list contains a value that is not a statement.');
            }
            $chunks[] = $this->statement($statement, $indent);
        }

        return implode($indent === 0 ? "\n\n" : "\n", $chunks);
    }

    private function statement(Statement $statement, int $indent): string
    {
        if ($statement instanceof ExpressionStatement) {
            return $this->terminated($indent, $this->expression($statement->expression, $indent));
        }
        if ($statement instanceof ReturnStatement) {
            return $this->terminated($indent, 'return ' . $this->expression($statement->value, $indent));
        }
        if ($statement instanceof ThrowStatement) {
            return $this->terminated($indent, 'throw ' . $this->expression($statement->exception, $indent));
        }
        if ($statement instanceof IfStatement) {
            return $this->ifStatement($statement, $indent);
        }
        if ($statement instanceof WhileStatement) {
            return $this->braced(
                'while (' . $this->expression($statement->condition, $indent) . ')',
                $statement->body,
                $indent,
            );
        }
        if ($statement instanceof DoWhileStatement) {
            return $this->braced('do', $statement->body, $indent)
                . ' while (' . $this->expression($statement->condition, $indent) . ');';
        }
        if ($statement instanceof ForStatement) {
            return $this->forStatement($statement, $indent);
        }
        if ($statement instanceof ForeachStatement) {
            return $this->foreachStatement($statement, $indent);
        }
        if ($statement instanceof BreakStatement) {
            return $this->terminated($indent, $this->jump('break', $statement->level, $statement));
        }
        if ($statement instanceof ContinueStatement) {
            return $this->terminated($indent, $this->jump('continue', $statement->level, $statement));
        }
        if ($statement instanceof SwitchStatement) {
            return $this->switchStatement($statement, $indent);
        }
        if ($statement instanceof SwitchBreakStatement) {
            return $this->terminated($indent, $this->jump('break', $statement->level, $statement));
        }
        if ($statement instanceof SwitchContinueStatement) {
            return $this->terminated($indent, $this->jump('continue', $statement->level, $statement));
        }
        if ($statement instanceof TryStatement) {
            return $this->tryStatement($statement, $indent);
        }
        if ($statement instanceof OpaqueStatement) {
            return $this->opaque($statement, $indent);
        }
        if ($statement instanceof SwitchCase) {
            $this->fail($statement, 'a switch case is part of a switch statement');
        }

        $this->fail($statement, 'statement has no PHP representation');
    }

    private function ifStatement(IfStatement $statement, int $indent): string
    {
        if ($statement->branches === []) {
            $this->fail($statement, 'if statement has no branches');
        }

        $text = '';
        foreach ($statement->branches as $index => $branch) {
            if (!$branch instanceof ConditionalBranch) {
                $this->fail($statement, 'if branch is missing');
            }
            $keyword = $index === 0 ? 'if' : 'elseif';
            $header = $keyword . ' (' . $this->expression($branch->condition, $indent) . ')';
            if ($index === 0) {
                $text = $this->braced($header, $branch->statements, $indent);
                continue;
            }
            $text .= $this->continueBlock($header, $branch->statements, $indent);
        }
        if ($statement->elseStatements !== null) {
            $text .= $this->continueBlock('else', $statement->elseStatements, $indent);
        }

        return $text;
    }

    private function forStatement(ForStatement $statement, int $indent): string
    {
        $initializers = $this->commaList($statement->initializers, $indent, $statement);
        $updates = $this->commaList($statement->updates, $indent, $statement);
        $condition = $statement->condition === null ? '' : $this->expression($statement->condition, $indent);
        if ($condition === '') {
            $header = 'for (' . $initializers . ';;' . ($updates === '' ? '' : ' ' . $updates) . ')';
        } elseif ($updates === '') {
            $header = 'for (' . $initializers . '; ' . $condition . ';)';
        } else {
            $header = 'for (' . $initializers . '; ' . $condition . '; ' . $updates . ')';
        }

        return $this->braced($header, $statement->body, $indent);
    }

    /**
     * @param list<Expression> $expressions
     */
    private function commaList(array $expressions, int $indent, Statement $statement): string
    {
        $parts = [];
        foreach ($expressions as $expression) {
            if (!$expression instanceof Expression) {
                $this->fail($statement, 'for loop expression is missing');
            }
            $parts[] = $this->expression($expression, $indent);
        }

        return implode(', ', $parts);
    }

    private function foreachStatement(ForeachStatement $statement, int $indent): string
    {
        $value = $this->target($statement->value);
        if ($statement->byReference) {
            $value = '&' . $value;
        }
        $as = $statement->key === null ? $value : $this->target($statement->key) . ' => ' . $value;
        $header = 'foreach (' . $this->expression($statement->iterable, $indent) . ' as ' . $as . ')';

        return $this->braced($header, $statement->body, $indent);
    }

    private function target(CompiledVariableTarget $target): string
    {
        if (preg_match(self::IDENTIFIER, $target->name) !== 1) {
            throw SourceGenerationException::at(
                'foreach target cannot be rendered',
                $target->opline,
                $target->line,
            );
        }

        return '$' . $target->name;
    }

    private function switchStatement(SwitchStatement $statement, int $indent): string
    {
        // typeFallback is the compiler's loose comparison for the same cases.
        // The switch statement is that comparison, so those arms are not copied.
        $subject = $this->expression($statement->subject, $indent);
        $lines = explode("\n", 'switch (' . $subject . ') {');
        $lines[0] = $this->pad($indent) . $lines[0];
        $casePad = $this->pad($indent + 1);
        foreach ($statement->cases as $case) {
            if (!$case instanceof SwitchCase) {
                $this->fail($statement, 'switch case is missing');
            }
            if ($case->labels === [] && !$case->isDefault) {
                $this->fail($case, 'switch case has no label');
            }
            foreach ($case->labels as $label) {
                if (!$label instanceof Literal) {
                    $this->fail($case, 'switch label is not a literal');
                }
                $text = $this->expression($label, $indent);
                if (str_contains($text, "\n")) {
                    $this->fail($case, 'switch label cannot be rendered');
                }
                $lines[] = $casePad . 'case ' . $text . ':';
            }
            if ($case->isDefault) {
                $lines[] = $casePad . 'default:';
            }
            foreach ($case->body as $body) {
                if (!$body instanceof Statement) {
                    $this->fail($case, 'switch body is missing');
                }
                $lines[] = $this->statement($body, $indent + 2);
            }
        }
        $lines[] = $this->pad($indent) . '}';

        return implode("\n", $lines);
    }

    private function tryStatement(TryStatement $statement, int $indent): string
    {
        $text = $this->braced('try', $statement->body, $indent);
        foreach ($statement->catches as $catch) {
            if (!$catch instanceof CatchClause) {
                $this->fail($statement, 'catch clause is missing');
            }
            $text .= $this->continueBlock('catch (' . $this->catchHeader($catch) . ')', $catch->body, $indent);
        }
        if ($statement->finally !== null) {
            $text .= $this->continueBlock('finally', $statement->finally->body, $indent);
        }

        return $text;
    }

    private function catchHeader(CatchClause $catch): string
    {
        if ($catch->types === []) {
            throw SourceGenerationException::at('catch clause has no type', $catch->opline, $catch->line);
        }
        $types = [];
        foreach ($catch->types as $type) {
            if (!is_string($type) || preg_match(self::QUALIFIED_NAME, $type) !== 1) {
                throw SourceGenerationException::at('catch type cannot be rendered', $catch->opline, $catch->line);
            }
            $types[] = $type;
        }
        $header = implode('|', $types);
        if ($catch->variable !== null) {
            if (preg_match(self::IDENTIFIER, $catch->variable) !== 1) {
                throw SourceGenerationException::at(
                    'catch variable cannot be rendered',
                    $catch->opline,
                    $catch->line,
                );
            }
            $header .= ' $' . $catch->variable;
        }

        return $header;
    }

    private function opaque(OpaqueStatement $statement, int $indent): string
    {
        $instruction = $statement->instruction;
        if ($instruction->opcode !== 'ECHO') {
            $this->fail($statement, 'opcode is not rendered as a statement', $instruction->opcode);
        }
        $operand = $instruction->op1->resolved;
        if (!$operand instanceof Expression) {
            $this->fail($statement, 'ECHO has no operand', 'ECHO');
        }

        return $this->terminated($indent, 'echo ' . $this->expression($operand, $indent));
    }

    private function jump(string $keyword, int $level, Statement $statement): string
    {
        if ($level < 1) {
            $this->fail($statement, 'jump level ' . $level . ' cannot be rendered');
        }
        if ($level === 1) {
            return $keyword;
        }

        return $keyword . ' ' . $level;
    }

    /**
     * @param list<Statement> $statements
     */
    private function braced(string $header, array $statements, int $indent): string
    {
        $text = $this->prefixFirst($indent, $header) . " {\n";
        $inner = $this->lines($statements, $indent + 1);
        if ($inner !== '') {
            $text .= $inner . "\n";
        }
        $text .= $this->pad($indent) . '}';

        return $text;
    }

    /**
     * @param list<Statement> $statements
     */
    private function continueBlock(string $header, array $statements, int $indent): string
    {
        $text = ' ' . $header . " {\n";
        $inner = $this->lines($statements, $indent + 1);
        if ($inner !== '') {
            $text .= $inner . "\n";
        }
        $text .= $this->pad($indent) . '}';

        return $text;
    }

    /**
     * @param list<Statement> $statements
     */
    private function lines(array $statements, int $indent): string
    {
        $rendered = [];
        foreach ($statements as $statement) {
            if (!$statement instanceof Statement) {
                throw new SourceGenerationException('Cannot generate PHP: a statement list contains a value that is not a statement.');
            }
            $rendered[] = $this->statement($statement, $indent);
        }

        return implode("\n", $rendered);
    }

    private function expression(Expression $expression, int $indent): string
    {
        return (new ExpressionRenderer($indent, $this->names))->render($expression);
    }

    private function terminated(int $indent, string $code): string
    {
        return $this->prefixFirst($indent, $code) . ';';
    }

    private function prefixFirst(int $indent, string $code): string
    {
        return $this->pad($indent) . $code;
    }

    private function pad(int $indent): string
    {
        return str_repeat('    ', $indent);
    }

    private function fail(Statement $statement, string $reason, ?string $opcode = null): never
    {
        throw SourceGenerationException::at($reason, $statement->opline, $statement->line, $opcode);
    }
}
