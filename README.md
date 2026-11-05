# Rephper

Rephper inspects PHP source after the Zend Engine has compiled it. A PHP 8.4 Zend extension prints compiled op arrays and writes them as versioned JSON. A separate PHP program reads that JSON and prints a control-flow graph for each op array. `Rephper\Expr\Reconstructor` turns the instructions inside one basic block into expression trees. `Rephper\Stmt\Reconstructor` turns those trees into straight-line statements, still one basic block at a time. `Rephper\Stmt\ConditionalStructurer` rebuilds `if` / `elseif` / `else` from the control-flow graph and those statements. `Rephper\Cfg\DominatorAnalysis` and `Rephper\Cfg\LoopAnalyzer` find natural loops in that graph and classify each one as `while`, `do-while`, `for`, `foreach`, or `unknown`. `Rephper\Stmt\LoopStructurer` turns the `while`, `do-while`, `for`, and `foreach` loops into statements, including an `if` nested in a loop and a loop nested in an `if`. It also rebuilds a jump-table `switch` (`SWITCH_LONG` and `SWITCH_STRING`). A jump-table `match` is reconstructed as one expression. `Rephper\Stmt\ExceptionStructurer` rebuilds `try` / `catch`, `try` / `finally`, and `try` / `catch` / `finally`, including nested handlers and a conditional, loop, switch, or match inside a body. `Rephper\CodeGen\PhpSourceGenerator` prints that structured body as PHP. The result is one op array, not the original file.

## Objectives

1. Accept PHP source.
2. Compile it with the Zend Engine.
3. Read the compiled opcodes and their metadata from Zend structures.
4. Serialize that representation as IR JSON.
5. Read the JSON without loading the Zend extension, and build a control-flow graph for each op array.
6. Reconstruct expression trees from the instructions inside each basic block.
7. Reconstruct straight-line statements from those trees, still grouped by basic block.
8. Reconstruct `if`, `elseif`, and `else` from the control-flow graph.
9. Analyze natural loops from the control-flow graph and reconstruct `while`, `do-while`, `for`, and `foreach` statements.
10. Reconstruct jump-table `switch` statements (`SWITCH_LONG` and `SWITCH_STRING`).
11. Reconstruct jump-table `match` expressions.
12. Reconstruct `try` / `catch`, `try` / `finally`, and `try` / `catch` / `finally`.
13. Generate the PHP body of one op array from the structured statements.

The extension does not parse another tool's text output. It reads `zend_op_array` and `zend_op` directly.

## Architecture

```text
PHP source
    |
    |  php -l, or any other compile
    v
Zend Engine pass_two()
    |
    |  op_array_handler(zend_op_array *)
    v
extension/src/Compatibility/php84/
    |
    +--> inspect.c     human-readable dump on stderr
    |
    +--> export.c      IR JSON, when REPHPER_IR is set
              |
              v
         src/Ir/Reader.php          validates IR JSON
              |
              v
         IR model (src/Ir/)
              |
              v
         src/Cfg/Builder.php        one graph per op array
              |
              v
         control-flow graph
              |
              +--> src/Cfg/DominatorAnalysis.php
              |         |
              |         v
              |    dominators of reachable blocks
              |
              +--> src/Cfg/LoopAnalyzer.php
              |         |
              |         v
              |    natural loops and break/continue targets
              |
              v
         src/Expr/Reconstructor.php
              |
              v
         expression trees for one basic block
              |
              +--> src/Expr/MatchReconstructor.php
              |         |
              |         v
              |    jump-table match expressions
              |
              v
         src/Stmt/Reconstructor.php
              |
              v
         statements for one basic block
              |
              +--> src/Stmt/ConditionalStructurer.php
              |         |
              |         v
              |    if / elseif / else
              |    (a loop in the op array stays unstructured here)
              |
              v
         src/Stmt/LoopStructurer.php
              |    also reads the CFG and the natural-loop analysis
              v
         while, do-while, for, foreach, and jump-table switch,
         including an if or a match inside those regions
              |
              v
         src/Cfg/ExceptionRegions.php
              |    try/catch rows, CATCH operands, catch_next edges
              v
         src/Stmt/ExceptionStructurer.php
              |    reuses the loop walk on each bounded region
              v
         try / catch, try / finally, and try / catch / finally
              |
              v
         src/CodeGen/PhpSourceGenerator.php
              |    one op-array body, opening tag, no closing tag
              v
         PHP fragment
```

The reader, the IR model, the CFG builder, dominator analysis, natural-loop analysis, the expression reconstructor, the statement reconstructor, the conditional structurer, the loop structurer, and `php bin/rephper graph` do not load the extension and do not use Zend C structures. Opcode control-flow rules for this stage live in `src/Cfg/Php84/Semantics.php`. Expression folding lives in `src/Expr/Reconstructor.php`. Jump-table match expressions live in `src/Expr/MatchReconstructor.php`. Statement grouping lives in `src/Stmt/Reconstructor.php`. Conditional structuring lives in `src/Stmt/ConditionalStructurer.php`. Natural-loop analysis lives in `src/Cfg/LoopAnalyzer.php`. While, do-while, for, foreach, and jump-table switch statements live in `src/Stmt/LoopStructurer.php`. Try, catch, and finally regions live in `src/Cfg/ExceptionRegions.php`. Those statements live in `src/Stmt/ExceptionStructurer.php`. `src/Application/OpArrayPipeline.php` runs that sequence for one op array. PHP rendering lives in `src/CodeGen/PhpSourceGenerator.php`. The generator does not load the extension and does not use Zend C structures.

`extension/rephper.c` is the Zend extension entry point. It registers `op_array_handler`. PHP 8.4 declares that callback as:

```c
typedef void (*op_array_handler_func_t)(zend_op_array *op_array);
```

`pass_two()` calls the handler at the start of the second compiler pass, before it rewrites operands into their runtime form. The dump therefore shows the compile-time representation:

- `IS_CV` values are already `EX_NUM_TO_VAR()` indexes. `zend_get_compiled_variable_name()` decodes them. `IS_TMP_VAR` and `IS_VAR` are still raw compiler numbers, so that API must not be used for them.
- `IS_CONST` is still an index into `op_array->literals`.
- Jump operands still hold absolute opline numbers. `pass_two()` later turns those into pointers or relative offsets.
- `ZEND_BRK`, `ZEND_CONT`, and `ZEND_GOTO` are pseudo-opcodes 253, 254, and 255. `pass_two()` rewrites the first two to `JMP` and resolves `GOTO` labels. `FAST_CALL` still holds a try/catch index.
- For `$this->prop`, the compiler sets `op1_type` to `IS_UNUSED` and copies an uninitialized znode. That number changes between processes. The dump prints `THIS`.
- Property-hook op arrays are named `$prop::get` or `$prop::set` before `pass_two()`. `op_array->prop_info` is assigned only after `pass_two()` returns, so the live property is read from `CG(context).active_property_info`.

`zend_get_opcode_name()` returns the C constant, including the `ZEND_` prefix. The dump strips that prefix.

Version-specific C code lives in `extension/src/Compatibility/php84/`. `extension/src/Common/json.c` writes JSON. It does not know about Zend types, so a later PHP version can reuse it. Operand classification shared by the text dump and the exporter lives in `extension/src/Compatibility/php84/opcodes.h`.

## Compatibility

Three separate concerns:

| Concern | What changes | Where it lives |
| --- | --- | --- |
| PHP language | `match`, enums, property hooks | `tests/Fixtures/PHP/X.Y/` |
| Zend Engine API | `zend_op`, operand encoding, opcode set | `extension/src/Compatibility/phpXY/` |
| Operating system and build | compiler, `phpize`, extension directory | `Makefile`, GitHub Actions `os` matrix |

The selected version and its API numbers live in the root `Makefile`:

```makefile
PHP_VERSION ?= 8.4
PHP_API ?= 20240924
ZEND_MODULE_API ?= 20240924
ZEND_EXTENSION_API ?= 420240924
```

`make info` and `make build` compare those values with `php`, `phpize`, and `php-config`. A mismatch stops the build. The compiler is also given the expected API numbers and rejects a different set of headers. Configuring the extension by hand, without the variables the Makefile exports, fails in `config.m4`.

The Zend extension API for PHP 8.4 is `420240924`. That is not the same number as the PHP API or the Zend module API. Both are checked.

Only PHP 8.4 is implemented. Adding 8.5 means a new `ifeq` in the Makefile, a new case in `extension/config.m4`, a new directory under `extension/src/Compatibility/`, and a new matrix entry. It does not mean scattering version checks through the 8.4 inspector.

## Operating systems

The first target is Ubuntu 24.04. The build uses `phpize`, which is the portable PHP extension build. Windows and macOS are not in the active matrix and have not been tried.

## Requirements

- PHP 8.4 CLI, `phpize8.4`, and `php-config8.4`
- PHP extensions used by PHPUnit: `dom`, `mbstring`, `tokenizer`, `xml`, and `xmlwriter`
- A C compiler, make, and autoconf
- Composer

Composer installs `symfony/console` for the `graph` command and `phpunit/phpunit` for development.

On Ubuntu:

```bash
sudo apt-get install php8.4-cli php8.4-dev php8.4-mbstring php8.4-xml build-essential autoconf pkg-config
```

## Build

From the repository root, install the PHP classes, then build and test the extension:

```bash
composer install
make info
make build
make test
```

The toolchain is configurable. The defaults are the versioned binaries, not plain `php`:

```bash
make build PHP_VERSION=8.4 PHP=php8.4 PHPIZE=phpize8.4 PHP_CONFIG=php-config8.4
```

`make build` runs `phpize`, configures with the selected `php-config`, and compiles `extension/modules/rephper.so`.

### Makefile targets

| Target | What it does |
| --- | --- |
| `make info` | Prints the selected version, the binary version, the PHP API, the Zend module API, the Zend extension API, `phpize`, `php-config`, and the extension directory. Exits with an error if they disagree. |
| `make build` | Checks the toolchain, then builds the extension. |
| `make test` | Builds the extension, then runs `composer test`. |
| `make test-unit` | Runs `composer test:unit`. It does not build or load the extension. |
| `make test-integration` | Builds the extension, then runs `composer test:integration`. |
| `make clean` | Removes `phpize` output. Source files and fixtures stay. |
| `make install` | Installs `rephper.so` into the extension directory reported by the selected `php-config`. The path is not hardcoded. Writing there usually needs root. `INSTALL_ROOT` prefixes that path, which is how the install rule can be checked without root. |

## Loading the extension

Rephper is a Zend extension, so it is loaded with `zend_extension`, not `extension`.

```bash
php8.4 -n -d zend_extension=$PWD/extension/modules/rephper.so -l path/to/file.php
```

`-n` skips `php.ini`, including OPcache. OPcache changes compiler flags, so a dump made with OPcache enabled does not match the checked-in files. The integration tests pass `-n` to every `php` process that loads the extension. PHPUnit itself runs with the extensions it needs, including `dom` and `mbstring`.

`-l` is PHP's lint mode. It compiles the file and does not execute it. The dump is written to stderr. Stdout is PHP's `No syntax errors detected` line.

Loading the extension makes every subsequent compilation print op arrays to stderr. Load it for inspection. Do not enable it globally in `php.ini` until the dump is optional.

## Intermediate representation

The IR is the boundary between the Zend Engine and the PHP tools that read it. The reader, the CFG builder, and the expression reconstructor take the JSON file as input. They do not see `zend_op` or `zval` pointers.

`ir_version` is the schema version. It is `1`. `php.version` is the PHP build that compiled the source. Those versions are independent: a new PHP release can keep emitting IR 1, and a schema change bumps `ir_version` without implying a new PHP version.

`compilation.stage` is `pre_pass_two`. Jump targets are absolute opline indexes from before `pass_two()` rewrites them. `compilation.jumps` is `absolute_opline`.

The document shape is:

```text
ir_version
php                  compiler version, version id, API numbers, zts, debug
compilation          stage and jump encoding
op_arrays[]          one object per compiled function, method, closure, hook, or main script
classes[]            user classes, interfaces, traits, and enums from the main file
```

Each op array records its kind, name, filename, lines, scope name, flags, arguments, compiled variable names, literals, static variables, try/catch rows, live ranges, break/continue rows, labels, attributes, and instructions. Names of compiled variables and parameters do not include `$`. A `const` operand is an index into that op array's `literals` array, not a copy of the value. `dynamic_functions` holds ids of nested op arrays that appear earlier in the same document.

An instruction has `opcode`, `opcode_number`, `line`, `op1`, `op2`, `result`, and `extended_value`. Operand `kind` is one of `unused`, `this`, `const`, `tmp`, `var`, `cv`, `jump`, `try_catch`, `level`, `label`, or `unknown`. A `jump` operand's `target` is an opline index. `tmp` and `var` indexes are still the compiler's raw numbers. `cv` indexes match `variables`. For `$this->prop`, op1 is `this` because the compiler leaves that znode number uninitialized. `FE_FETCH_*`, `SWITCH_*`, and `MATCH` also set `jump_target` from `extended_value`. A switch or match literal used as the jump table is marked `jump_table: true`, and the integers inside that array are opline targets.

Three exported fields keep the compiler's encoding, and the names are easy to misread:

- `BRK` and `CONT` export op1 as `{"kind":"level","value":N}`. `N` is an index into this op array's `breaks` array (`CG(context).current_brk_cont`), not the `break 2` depth. The depth is the unused op2 number. `break 2` walks `parent` once, then uses that row's `break` or `continue` opline. A null `breaks` array means this op array was not the compiler context, so the rows were not available.
- `GOTO` exports op1 as `{"kind":"label","id":N}`. `N` is an unwind count, not an index into `labels`. The label name is the op2 constant. `labels` maps that name to an opline. A null `labels` array means the names were not available.
- A try/catch row stores `catch`, `finally`, or `finally_end` as `0` when that handler was not emitted. The VM treats `0` as "no handler" because an opline is never `< 0`. The JSON uses `null` only for the `(uint32_t)-1` sentinel. A row with neither a real catch nor a real finally is invalid. `finally_end` is the `FAST_RET` opline. `FAST_CALL` op1 is a `try_catch` index, and its target is that row's `finally`.

Literals keep their PHP types. They are not all strings.

| PHP value | JSON |
| --- | --- |
| `null` | `{"type":"null"}` |
| `true` / `false` | `{"type":"bool","value":true}` |
| integer inside ±(2^53−1) | `{"type":"int","value":10}` |
| integer outside that range | `{"type":"int","value":"9223372036854775807"}` |
| finite float other than `-0.0` | `{"type":"float","value":0.5}` |
| `-0.0`, `NAN`, `INF` | `{"type":"float","value":"-0"}`, `"NaN"`, `"Infinity"`, or `"-Infinity"` |
| UTF-8 string | `{"type":"string","value":"..."}` with JSON escapes |
| non-UTF-8 string | the same, plus `"binary":true`. Every byte is a `\u00XX` code unit. Recover the original byte from the low 8 bits. |
| array | `{"type":"array","elements":[{"key":...,"value":...}]}` so key order and int-versus-string keys survive |

A constant expression that is still an AST is `{"type":"constant_ast","ast":...}`. Enum case values are often still `CONST_ENUM_INIT` nodes at this stage. The backing value is one of the AST children.

Generate a file with `php -l` so the source is compiled and not executed:

```bash
REPHPER_IR=out.json php8.4 -n -d zend_extension=$PWD/extension/modules/rephper.so -l path/to/file.php
```

`REPHPER_IR` is an environment variable. Rephper is a Zend extension and does not register a PHP module, so there is no `php.ini` setting for the path. The named path is created or replaced when it is a regular file. A directory, symlink, or other non-regular path is left untouched and an error is written to stderr. The text dump is still written to stderr.

This is the `ADD` inside `calculate()` from `tests/Fixtures/Common/Arithmetic.php`, with the surrounding document fields abbreviated:

```json
{
  "ir_version": 1,
  "php": {
    "version": "8.4.27",
    "version_id": 80427,
    "api": 20240924,
    "zend_module_api": 20240924,
    "zend_extension_api": 420240924,
    "zts": false,
    "debug": false
  },
  "compilation": {
    "stage": "pre_pass_two",
    "jumps": "absolute_opline"
  },
  "op_arrays": [
    {
      "id": 0,
      "kind": "function",
      "name": "calculate",
      "instructions": [
        {
          "index": 2,
          "opcode": "ADD",
          "opcode_number": 1,
          "line": 5,
          "op1": {"kind": "cv", "index": 0, "name": "a"},
          "op2": {"kind": "cv", "index": 1, "name": "b"},
          "result": {"kind": "tmp", "index": 0},
          "extended_value": 0
        }
      ]
    }
  ]
}
```

The checked-in files under `tests/Expected/PHP/8.4/` replace the patch version with `PHP_VERSION` and `version_id` with `0`, and they use repository-relative filenames. A real export keeps the compiler's exact version and the path PHP compiled.

## IR reader

The reader is `src/Ir/Reader.php`. It loads one JSON document from a file or a string. `json_decode` uses `JSON_THROW_ON_ERROR`. A missing field, a field of the wrong type, an instruction whose `index` is not its position, or an operand kind outside the eleven kinds the exporter writes is `Rephper\Ir\IrException`. `ir_version` other than `1` is `UnsupportedIrVersionException`. Invalid values are not replaced with defaults. Keys the reader does not model are kept on an `extra` array so a later field is not discarded.

`php bin/rephper graph` is the command that reads a file and prints its graphs. The command class only wires the reader, the builder, and the printer.

The model follows the JSON document:

```text
Document
├── PhpBuild
├── Compilation
└── OpArray
    ├── Instruction
    │   └── Operand
    ├── Literal
    ├── TryCatch
    ├── BreakContinue
    └── Label
```

Nested functions, methods, closures, and property hooks are separate op arrays in the same document. `dynamic_functions` holds their ids. Each op array gets its own graph. Class rows stay as arrays; the CFG does not use them.

## Control-flow graph

A basic block is a run of instructions with one entry and no branch inside it. The builder finds leaders, then cuts the op array into half-open ranges `[start, end)`. The original opline indexes stay on the instructions. Block ids are `B0`, `B1`, … in start-opline order. A range is easier to compare than a pair of inclusive ends: the next block starts where this one ends.

An opline is a leader when it is any of these:

1. Instruction 0.
2. The target of a jump, a switch or match case, a `break`, a `continue`, a `goto`, a `foreach` exit, a `finally` call or return, or any other explicit successor.
3. The instruction immediately after an instruction that is not a single fallthrough to the next opline. A conditional branch, an unconditional jump, a `return`, a `throw`, and a `switch` all do this. A plain `ADD` does not, so the next instruction stays in the same block.
4. A real `try`, `catch`, or `finally` opline. A stored `0` is not a handler, so it is not a leader.
5. A label opline.

Edges are taken from the last instruction of the block. The target must be a block start. A jump past the end of the op array, a jump to an opline that is not a leader, a duplicate label, or a try range that does not match the instructions is `Rephper\Cfg\CfgException`. The builder does not invent a destination.

| Kind | Meaning |
| --- | --- |
| `fallthrough` | The next opline. Also the type-mismatch path after `SWITCH_LONG` and `SWITCH_STRING`. |
| `jump` | `JMP`. |
| `true`, `false` | `JMPZ` / `JMPNZ` and the value-bearing forms `JMPZ_EX`, `JMPNZ_EX`, and `JMP_SET`. For `JMPZ`, true is the next opline and false is op2. |
| `null`, `not_null` | `COALESCE` and `JMP_NULL`. `JMP_NULL`'s `extended_value` is flags, not a target. |
| `switch`, `default` | One `switch` edge per jump-table entry, then `default` from `extended_value`. `MATCH` has no fallthrough. `SWITCH_*` keeps the fallthrough into the following comparison chain. |
| `foreach_exit` | `FE_RESET_*` op2, and `FE_FETCH_*` `jump_target`. The array path also falls through, including an empty array. |
| `break`, `continue` | The opline from the `breaks` row after walking `parent` for the depth. |
| `goto` | The opline of the label named by the `GOTO` constant. |
| `finally_call`, `finally_return` | `FAST_CALL` enters `finally`. `FAST_RET` returns to the opline after each `FAST_CALL` for that try/catch row. |
| `catch_next` | A `CATCH` that is not the last one jumps through op2 to the next `CATCH`. The last catch, marked by `ZEND_LAST_CATCH` in `extended_value`, only falls through. |
| `assert_skip`, `initialized`, `frameless` | Assertions disabled, a static variable already initialized, and a frameless call that hits. Each also falls through on the other path. |
| `catch`, `finally` | Exception edges. These are not successors. |

`RETURN`, `RETURN_BY_REF`, `GENERATOR_RETURN`, `THROW`, and `MATCH_ERROR` end a block and have no successor. PHP 8.4 has no `EXIT` opcode. `YIELD` and `YIELD_FROM` stay inside the block: suspension is not a separate edge. A comparison's `smart_branch` flag is kept on the operand and ignored here, because the following `JMPZ` or `JMPNZ` is still its own instruction.

Exception edges follow the VM's try/catch/finally dispatch for one thrown exception. The innermost `try_catch` row that contains the block start is used. An opline before `catch` goes to the catch block. An opline before `finally` goes to the finally block. An opline inside the finally body walks to an outer row. A `THROW` with no enclosing handler has no exception edge. The graph does not add an "uncaught" block. The edge is the handler boundary. It is not a successor, and dominators and natural loops do not follow it. It does not name the exception class a thrown value can match. Typed catch clauses are recovered later from the `CATCH` operands and the `catch_next` edges, not by turning this edge into a branch.

Unreachable compiler epilogues, usually `VERIFY_RETURN_TYPE` plus `RETURN` after an earlier return, stay in the graph. They have no predecessors. The builder does not delete them.

`classify()` in `tests/Fixtures/Common/Conditions.php` compiles to this graph:

```text
OpArray #0 classify (function)

Block B0 [0, 3)
    0: RECV
    1: IS_SMALLER
    2: JMPZ

    Successors:
        B1 (true)
        B2 (false)

Block B1 [3, 4)
    3: RETURN

    Successors:
        (none)
    Predecessors:
        B0

Block B2 [4, 5)
    4: RETURN

    Successors:
        (none)
    Predecessors:
        B0

Block B3 [5, 7)
    5: VERIFY_RETURN_TYPE
    6: RETURN

    Successors:
        (none)
```

`B3` is the unreachable epilogue. Checked-in expected JSON uses `"version": "PHP_VERSION"` and `"version_id": 0`. The builder accepts that pair when the API is still 20240924, so `php bin/rephper graph` can read those files directly. A real export keeps the compiler's exact version string.

Three failures are different:

| Failure | Exception | Example |
| --- | --- | --- |
| The document is not IR 1, or a field has the wrong type | `Rephper\Ir\IrException` | truncated JSON, `ir_version` 2 |
| The IR is valid and the opcode is not modeled | `Rephper\Cfg\UnsupportedSemanticsException` | `USER_OPCODE`, PHP 8.3, a stage other than `pre_pass_two` |
| The IR is valid and an optional table is missing | `Rephper\Cfg\IncompleteMetadataException` | `breaks` or `labels` is null on an op array that uses them |

An out-of-range jump is invalid IR for the CFG and uses `CfgException` directly.

## Expression reconstruction

`src/Expr/Reconstructor.php` reads one op array and the CFG already built for it. It does not change the IR or the graph, and it does not load the extension. Reconstruction stays inside a single basic block. A temporary defined on another path is not copied in. `MatchReconstructor` then rebuilds a jump-table `match` whose arms meet again after the branch. A function with no `MATCH` keeps the same reconstruction. The match representation is described below.

```php
$reconstruction = (new Rephper\Expr\Reconstructor())->reconstruct($opArray, $graph);
```

Each block has an ordered list of expressions. Value opcodes such as `ADD` and the comparisons are stored in their `tmp` or `var` result slot and folded into the later instruction in that same block that uses the slot. Both uses of one slot share one expression object. A direct named function call is folded the same way: the call expression is stored in the slot written by `DO_ICALL`, `DO_UCALL`, or `DO_FCALL_BY_NAME`, and a later use of that slot is the same object. An assignment is stored in its result slot the same way. `while ($line = fgets($handle))` therefore uses that assignment as the branch operand. An assignment whose result is unused stays in the list. A use of the result in another block stays unresolved. Returns stay in the list. The `opline` on each node is the instruction that created it, so the original evaluation order remains visible. For a function call that opline is the instruction that executes the call. The compiler epilogue stays too: after a user's `return`, a following block can still hold `VERIFY_RETURN_TYPE` and another `RETURN` of null. Instructions this pass does not fold stay in the list as opaque nodes.

| Node | What it records |
| --- | --- |
| `Literal` | The IR literal type and value. Numbers, strings, booleans, and null stay distinct. An array keeps `value` null and stores `elements` in source order. `binary` is set for a non-UTF-8 string. |
| `VariableRead` | A compiled variable name. Temporary slot numbers are not variable names. |
| `Binary` | `+`, `-`, `*`, `/`, `%`, `.`, `===`, `!==`, `==`, `!=`, `<`, `<=`, with the operands in the order the opcode uses. |
| `Unary` | `!` for `BOOL_NOT`, `~` for `BW_NOT`, and `bool` for `BOOL`. |
| `Assignment` | A write to a compiled variable. `operator` is null for `=`. `+` means `+=`, from `ASSIGN_OP`'s `extended_value`. |
| `ReturnValue` | The returned value. A `return;` is a return of the null literal the compiler emitted. |
| `FunctionCall` | A direct named call. The name is the string literal the compiler stored. Arguments stay in send order. |
| `Argument` | One argument: its expression, send opcode, passing mode (`value` or `reference`), unpack flag, optional name, and 1-based position when the send is positional. |
| `Opaque` | An instruction kept in place. It records the opcode, opcode number, extended value, jump target, unknown instruction fields, and all three operands. |
| `Unresolved` | A `tmp` or `var` used in a block that does not define it. |
| `MatchExpression` | A jump-table `match`. The subject, the arms, and the `MATCH_ERROR` opline when there is no default. |
| `MatchArm` | One arm: its condition literals, its result expression, and whether it is the default. |

`RECV` and `VERIFY_RETURN_TYPE` are opaque and do not produce a diagnostic. A reconstructed function call does not produce a diagnostic for its `INIT_*`, `SEND_*`, `CHECK_UNDEF_ARGS`, or `DO_*` instructions. Any other opcode that this pass does not fold, including `STRLEN`, `ECHO`, `FETCH_DIM_R`, `INIT_DYNAMIC_CALL`, `INIT_METHOD_CALL`, `NEW`, `CALLABLE_CONVERT`, `JMPZ_EX`, and `JMPNZ_EX`, is opaque and adds an `unsupported` diagnostic. The diagnostic names the opcode, opline, source line, block, and reason. A const index that is not in the literal table is invalid IR and throws `ExpressionException`.

An opaque operand keeps the IR kind and its fields. A `const`, compiled variable, `tmp`, or `var` also points at the expression it uses, and a temporary points at the same object stored for that slot. `unused`, `jump`, `this`, and the other non-value kinds stay as IR data. The result operand records the written slot and does not point back at the opaque node. `echo $text` keeps the compiled variable. `echo strlen($text)` keeps the `STRLEN` node as the operand of `ECHO`. `FETCH_DIM_R` keeps both compiled variables, the opcode number, and any extra instruction fields.

These compiled forms are easy to misread as source syntax:

- `-$a` and `+$a` are `MUL` by the literals `-1` and `1`. The tree is a multiplication.
- `$a >= $b` is `IS_SMALLER_OR_EQUAL` with the operands swapped, so the tree is `$b <= $a`.
- `$a && $b` and `$a || $b` are `JMPZ_EX` or `JMPNZ_EX` plus `BOOL` in later blocks. Those blocks are not joined into `&&` or `||`.

`strlen($text) + $n` keeps the operation, the argument, the addition, and the following operand. `STRLEN` is the left side of `+`, and its operand is the compiled variable `text`. `$n` is the right side. The `STRLEN` opline is earlier than the `ADD` opline.

```text
return strlen($text) + $n

ReturnValue
    Binary +
        Opaque STRLEN
            op1 cv text -> VariableRead text
        VariableRead n
```

`strlen($text)` on a compiled variable is the `STRLEN` opcode, not a call, so it stays opaque. `strtolower($text)` is a real call. `return strlen(strtolower($text)) + $n` keeps `STRLEN` as the left side of `+`. Its operand is the `FunctionCall` stored by `DO_ICALL`, and that call's argument is the compiled variable `text`. `echo strtolower($text . $n)` keeps the concatenation as the call argument and the same `FunctionCall` as the `ECHO` operand.

```text
return strlen(strtolower($text)) + $n

ReturnValue
    Binary +
        Opaque STRLEN
            op1 var -> FunctionCall strtolower
                Argument value: VariableRead text
        VariableRead n
```

A named call is reconstructed only when the whole sequence is one of these shapes:

| Init | Arguments | Execute |
| --- | --- | --- |
| `INIT_FCALL` whose op2 is a string literal | `SEND_VAL`, `SEND_VAR`, `SEND_REF`, `SEND_UNPACK`, and the same sends with a const name on op2 | `DO_ICALL` for an internal function, `DO_UCALL` for a known user function |
| `INIT_FCALL_BY_NAME` whose op2 is a string literal | `SEND_VAL_EX`, `SEND_VAR_EX`, `SEND_REF`, `SEND_UNPACK`, and named sends | `DO_FCALL_BY_NAME` |

The init instruction's `extended_value` is the number of positional arguments when it is greater than zero. Zero means there is no fixed positional prefix, so the call may have no arguments or only named and unpacked arguments. Named and unpacked arguments follow a positional prefix, and `CHECK_UNDEF_ARGS` is recorded on the call instead of becoming its own expression. Argument expressions, including nested calls, are compiled between the init and the send that consumes them. They keep their own expression objects and their original order. A call result that is used twice is one `FunctionCall` object.

`DO_FCALL` is not treated as the end of `INIT_FCALL`. Constructors, dynamic calls, and method calls use `DO_FCALL`, and those sequences can sit inside another call's argument. They open an opaque frame so their sends stay with them. The outer named call can still be reconstructed, with the opaque value as its argument. If a named sequence is missing an argument, has an unrecognized send, or is interrupted, every instruction in that sequence stays opaque and each one is diagnosed. A partial call is not emitted.

These forms stay opaque:

- `INIT_DYNAMIC_CALL` and `$name($price)`
- `INIT_METHOD_CALL` and `INIT_STATIC_METHOD_CALL`
- `NEW` followed by `SEND_*` and `DO_FCALL`
- `CALLABLE_CONVERT` after `INIT_FCALL`, which is first-class callable syntax
- a send opcode this pass does not model, such as `SEND_USER`, `SEND_ARRAY`, or `SEND_FUNC_ARG`

The function name is the literal the compiler stored. A call compiled against a known function can be lowercased (`byReference($n)` is stored as `byreference`). A named argument whose callee is already known can be rewritten by the compiler into a positional send, and the tree then has no name. `unknown_func(quantity: $quantity)` keeps the name because the callee is not known at compile time.

```text
$result = $a + $b * $c

Assignment result
    Binary +
        VariableRead a
        Binary *
            VariableRead b
            VariableRead c
```

PHP source generation is not part of this pass.

## Statement reconstruction

`src/Stmt/Reconstructor.php` takes an op array, the CFG for that op array, and the expression reconstruction already produced for it. It does not fold expressions again and it does not load the extension. Each basic block gets its own ordered statement list. This pass does not merge blocks. Loops, `switch`, and `try` stay in separate blocks here. A jump-table `match` is already one expression in the statement that uses it, so this pass does not turn the arm blocks into statements. Conditional structuring, natural-loop analysis, and `LoopStructurer` are separate passes. None of them changes the per-block lists.

```php
$statements = (new Rephper\Stmt\Reconstructor())->reconstruct(
    $opArray,
    $controlFlowGraph,
    $expressions,
);
```

| Statement | What it records |
| --- | --- |
| `ExpressionStatement` | An assignment, or a function call whose result is unused. The statement holds the same expression object the expression pass produced. |
| `ReturnStatement` | The returned expression. `return;` is a return of the null literal the compiler emitted for that instruction. |
| `ThrowStatement` | The thrown value. `throw new Exception('bad')` keeps `NEW` as that value. The constructor argument and `DO_FCALL` stay as earlier statements. |
| `OpaqueStatement` | A side-effecting instruction that is not one of those statements. It holds the same `Opaque` expression, including the opcode and its operands. `echo $text` is an `ECHO` statement. `strtolower($text);` is one expression statement whose expression is the `FunctionCall`. |

A temporary that was folded into a later expression does not become its own statement. `$result = $a + $b` is one assignment whose value is the addition. `$result = strtolower($text)` is one assignment whose value is the call. `return $price * $quantity` is one return whose value is the multiplication. `RECV`, `VERIFY_RETURN_TYPE`, and the compiler's trailing return (`extended_value` 4294967295) produce no statement. A bare `JMP` produces no statement; the CFG already records that edge. A `CATCH` whose class operand is resolved stays an opaque statement until try/catch structuring consumes it. A `CATCH` with no resolved operand is dropped here, the same way a bare `JMP` is. A branch that still carries a value, such as `JMPZ`, stays an opaque statement so the condition is not dropped.

An opaque statement adds an `unsupported` diagnostic with the opcode, opline, source line, block, and reason. A `THROW` whose exception value is present does not.

```text
$a = 10;
$b = 20;

ExpressionStatement   opline 0
    Assignment a = Literal int 10
ExpressionStatement   opline 1
    Assignment b = Literal int 20
```

This pass does not join blocks. A `JMPZ` or `JMPNZ` that still carries its condition stays an opaque statement here, so the condition is available to the next pass. PHP source generation is not part of this pass.

## Structured conditionals

`src/Stmt/ConditionalStructurer.php` takes the control-flow graph and the statement reconstruction for one op array. It does not fold expressions again, does not rebuild statements, and does not change the graph or the per-block lists. It returns a separate statement list for the whole op array. Statement objects inside a branch are the same objects the statement pass produced.

```php
$structured = (new Rephper\Stmt\ConditionalStructurer())->structure(
    $controlFlowGraph,
    $statements,
);
```

| Type | What it records |
| --- | --- |
| `IfStatement` | Ordered tested branches and an optional else. `elseStatements === null` means there is no else region. An empty array is an else region with no statements. The statement opline and line are the first tested branch. `elseOpline` and `elseLine` are the first instruction of the else region. |
| `ConditionalBranch` | The condition expression, the branch opline and line, and the statements in that arm. `elseif` is another branch of the same `IfStatement`. |

The supported forms are `if`, `if` / `else`, `if` / `elseif` / `else`, and conditionals nested inside a branch. The branch opcodes are `JMPZ` and `JMPNZ`.

The condition is the expression already stored on that branch instruction: `op1` of the `JMPZ` or `JMPNZ` statement. A comparison or a `!` that the compiler evaluated into a temporary in the same block is that expression. The pass does not build a new condition and does not wrap it in another `!`. `$a > $b` stays the comparison the compiler emitted, which is `$b < $a`. `if (!$a)` stays a unary `!`. A condition that is an unresolved temporary, because its definition is in another block, is refused.

The then arm is the successor whose edge kind is `true`. The other arm is the edge whose kind is `false`. Those kinds come from `src/Cfg/Php84/Semantics.php`. For `JMPZ`, true falls through and false is the jump. For `JMPNZ`, true is the jump and false falls through. The pass does not decide this from block numbers or from which block was created next.

The merge point comes from the graph:

- When both edges target the same block, that block is the merge and the body is empty. PHP emits this for an `if` whose body it deleted.
- Otherwise the immediate post-dominator of the branch block is the merge when it is a real block. A block post-dominates the branch when every path from the branch to the function exit goes through it. Blocks with no ordinary successor are treated as reaching a synthetic exit, so a `return` or `throw` still has a path to the end. The immediate post-dominator is the closest of those blocks.
- When that block is the false successor, there is no else. The false block's statements stay after the `IfStatement`.
- When it is a later block, the true region and the false region both stop there. Statements after that block stay outside the conditional.
- When the only common end is the synthetic exit, the false successor stays outside the conditional. PHP also emits an unreachable `JMP` immediately after a true branch that returns or throws when the source has an explicit else that does not fall through. If that jump lands past the false successor, the false successor is the else and the jump target is the merge. Without that jump, a later `return` stays outside the `if`.

Each branch is walked with the same rules and stops at the surrounding merge, so a conditional inside a branch stays nested. When an else region is exactly one `IfStatement`, with no statement before or after it, that statement's branches are appended to the outer one. That is an `elseif` chain, including an `elseif` that has no final else. An else that contains any other statement keeps the inner `IfStatement` nested. An empty else that the compiler deletes has no else region.

`JMPZ_EX`, `JMPNZ_EX`, `JMP_SET`, `COALESCE`, and `JMP_NULL` are not `if` headers. The same opcodes implement `&&`, `||`, and null coalescing. They stay in the original statement lists.

If any region in the op array is not an unambiguous forward conditional, the result is the original statements in block order, the original diagnostics, and one `unstructured` diagnostic. That diagnostic names the opcode, opline, source line, block, and the reason. No condition or branch relationship is invented. A loop, `switch`, `match`, `goto`, `break`, `continue`, `foreach`, `try` / `catch`, or a backward edge makes the whole op array unstructured, even if another part of it is a plain `if`.

A branch that became an `IfStatement` is not also left in the new list as an opaque statement. Its `unsupported` diagnostic is omitted from the new diagnostic list. The per-block reconstruction is unchanged and still contains both.

This public result is unchanged when the op array contains a loop, a jump-table `switch`, or a `MATCH`: the original statements come back with one `unstructured` diagnostic. While, do-while, for, foreach, and jump-table switch structuring is a separate pass, described below. It can place an `IfStatement` inside a loop body or a switch case, and it can leave a reconstructed match expression inside an `if` or a loop. `ConditionalStructurer::structure()` still refuses `SWITCH_LONG`, `SWITCH_STRING`, `MATCH`, `CATCH`, `FAST_CALL`, and `FAST_RET`. Try, catch, and finally are a separate pass. PHP source generation is a separate pass.

## Natural loops

`src/Cfg/LoopAnalyzer.php` takes the control-flow graph and the op array it was built from. It returns dominators, natural loops, and the reachable `BRK` and `CONT` instructions. It does not rebuild expressions or statements and does not change the graph. `LoopStructurer` reads this analysis when it builds `while`, `do-while`, `for`, `foreach`, and jump-table `switch` statements. The analyzer itself does not emit those statements. The `breaks[]` table is carried on the analysis so a switch break can be told from a loop break. Loop classification does not use it beyond the rules above.

```php
$loops = (new Rephper\Cfg\LoopAnalyzer())->analyze($controlFlowGraph, $opArray);
```

Dominators come from `src/Cfg/DominatorAnalysis.php`. The entry is the block that starts at opline 0. A block D dominates N when every ordinary path from the entry to N goes through D. A reachable block dominates itself. Unreachable blocks are listed and have no dominator set. Exception edges are not followed. The iteration does not treat block ids as execution order.

A back edge is an ordinary edge from block N to block H where H dominates N. The edge may point at a block that is earlier or later in the opline layout. PHP lays a `while` body out before its condition, so the body's fallthrough into the condition is the back edge. The condition's branch into the body is not.

The natural loop of every back edge into one header is that header plus every block that can reach a back-edge source without passing through the header. Several back edges into the same header are one loop. A parent is the smallest loop whose blocks contain the child. Each loop records its header, member blocks, back edges, entry edges, exit edges, children, the oplines of the header and the back edges, and `continueOpline`. `continueOpline` is the `breaks[]` continue target when one non-switch row matches the loop. A `for` update starts there. The back-edge opline is the last instruction of that update, not the first. An exit edge leaves the member set. A back edge is not an exit. Blocks that cannot reach a back-edge source stay outside the member set, including the block after the loop and a `break` block that has no path back to the header.

`BRK` and `CONT` keep op2 as the depth and op1 as the `breaks[]` index. The target is the block the CFG edge already names. The depth is not inferred from block order. The instruction is tied to the innermost loop that contains its block, or the in-loop block that branches to it. A `break` records the outermost related loop whose members do not contain the target. A `continue` records a loop only when that target is the loop's continue block. A `continue` that lands inside the loop on another block has no loop id. That record includes a `continue` whose `breaks[]` row is still a switch that ends on the loop's continue block. `LoopStructurer` uses the `breaks[]` row, not that loop id, to decide whether the continue belongs to the switch. `outer` is true when the recorded loop is outside the innermost related loop, which is how `break 2` and `continue 2` name an outer loop. A `break` whose target stays inside that innermost loop has no loop id. A switch `break` is in that group when its target stays inside the loop. An edge out of an unreachable block is not a loop entry. The compiler's unreachable else marker therefore does not make a normal loop look entered outside its header.

| Kind | What decides it |
| --- | --- |
| `while` | The header ends with `JMPZ` or `JMPNZ`, an edge leaves the loop, and the single non-switch `breaks[]` continue block is the header. A condition that branches to itself is also a `while` when every entry edge has kind `jump`. |
| `do_while` | A condition that branches to itself, when every entry is a fallthrough or a conditional edge. A multi-block `do-while` has a back edge from a later `JMPZ` or `JMPNZ` that leaves the loop. When the header itself has `while` shape, that later block is the continue block. |
| `for` | The header has the `while` shape, and the continue block is another block of the loop. An empty `for` has the same blocks as a `while` whose body is the increment. The continue block is what distinguishes them. |
| `foreach` | The header ends with `FE_FETCH_R` or `FE_FETCH_RW`, an entry comes from `FE_RESET_R` or `FE_RESET_RW`, and a back edge is a `JMP` to the header. |
| `unknown` | The loop contains `GOTO`, an entry targets a block other than the header, a self-loop has mixed entry kinds, the header is not one of those conditions, or the `breaks[]` rows do not choose one shape. The reason is stored on the loop. |

A backward `goto` can satisfy the back-edge definition and still stays `unknown`. A `goto` whose target does not dominate its source is not a back edge. `while ($a && $i < $b)` is a natural loop whose header is `JMPZ_EX`; it stays `unknown`. An unreachable cycle, including a loop left after `return`, is not a loop. PHP rejects `goto` into a loop, so that shape is not in the CFG. `ConditionalStructurer::structure()` still reports an op array that contains a loop as unstructured. The next sections are what turn a classified `while`, `do-while`, `for`, or `foreach`, and a jump-table `switch`, into a statement.

## While, do-while, for, and foreach statements

`src/Stmt/LoopStructurer.php` takes the control-flow graph, the loop analysis for that graph, and the statement reconstruction. It reuses the expression objects and the statement objects. It does not change the graph or the per-block lists. `unknown` loops stay unstructured.

```php
$analysis = (new Rephper\Cfg\LoopAnalyzer())->analyze($controlFlowGraph, $opArray);
$structured = (new Rephper\Stmt\LoopStructurer())->structure(
    $controlFlowGraph,
    $analysis,
    $statements,
);
```

| Type | What it records |
| --- | --- |
| `WhileStatement` | The condition expression, the ordered body, the branch opline, the source line, and the natural-loop id. |
| `DoWhileStatement` | The same fields. The body statements run before the condition is tested. |
| `ForStatement` | The initializer expressions, the condition, the update expressions, the ordered body, the branch opline, the source line, and the natural-loop id. The condition is null when the compiler synthesized `true` for an omitted condition. |
| `ForeachStatement` | The iterable expression, the optional key target, the value target, whether the value is by reference, the ordered body, the fetch opline, the source line, and the natural-loop id. |
| `CompiledVariableTarget` | The compiled-variable name and index, and the opline and source line of the instruction that writes it. A value is written by `FE_FETCH_*`. A key is written by the following `ASSIGN`. This is not an expression. |
| `BreakStatement` | The source depth, the instruction opline, the source line, and the loop id from `LoopControl`. |
| `ContinueStatement` | The same fields. The loop id is the loop whose continue block the CFG edge lands on. |

A `while` condition is the expression already stored on the header's `JMPZ` or `JMPNZ`. The body starts at the successor that stays inside the natural loop. The exit is the successor that leaves it. An empty body is that header branching to itself. Statements in the header before the branch keep the loop unstructured. An assignment whose result is the branch operand is the condition, so `while ($line = fgets($handle))` keeps that assignment in the condition. It is not moved before the loop. When the edge into the body has kind `false`, the condition is that expression wrapped in `!`. Kind `true` uses the expression as stored. `while (!$n)` is already a unary `!` on a true edge.

A `do-while` condition is the latch. The latch is the one back edge whose kind is `true` or `false` and whose source ends with `JMPZ` or `JMPNZ`. When the latch is the header, the body is the statements in that block before the branch, and an empty body is an empty list. When the body spans other blocks, the walk starts at the header and the latch contributes only the statements before its branch. The back-edge kind chooses the same polarity as a `while` body edge. `continue` in a `do-while` targets that latch. A `return` or `throw` in the body stays in the body.

An `if` inside a supported loop is an `IfStatement` in the loop body. A supported loop inside an `if` is a `WhileStatement`, `DoWhileStatement`, `ForStatement`, or `ForeachStatement` in that branch. The walk treats the loop header and the block after the loop as boundaries, so statements outside the loop stay outside. Post-dominators inside the loop treat an edge to either boundary as an edge to the function exit. An else that is exactly one `IfStatement` is flattened into another branch, by the same rule the conditional pass uses. An explicit else whose true arm is `continue`, `break`, `return`, or `throw` is recovered from the compiler's unreachable `JMP` marker.

`BreakStatement` and `ContinueStatement` come from `LoopControl` and from the CFG edge that instruction already has. The depth is op2, so `break 2` and `continue 2` keep that level when the target loop is resolved. A control whose `breaks[]` row is a loop and whose loop id is null stays an opaque statement, with an `unresolved` diagnostic. A `break` or `continue` whose row is a switch is not a loop statement. A structured jump-table switch records it as `SwitchBreakStatement` or `SwitchContinueStatement`, described below. A comparison-chain switch leaves it opaque. A `break 2` or `continue 2` whose row is the enclosing loop is a loop statement for that loop. The pass does not choose a target from block order. The straight-line pass drops a bare `BRK` or `CONT`, so these statements are created here.

Nested `while`, `do-while`, `for`, and `foreach` loops follow the parent the analyzer recorded. The child is one statement of the parent body. Statements between two children stay between them. The child's statements appear in the child, once.

PHP lays a `for` out as the initializers, a jump to the condition, the body, the updates, and then the condition. The true edge of the condition returns to the body. The back edge is the fallthrough from the last update into the condition. `continueOpline` is the first update instruction. Body statements and updates often share a block; the split is that opline. A jump to the update, including `continue`, makes the update its own block because that opline is a leader.

The prologue is the one jump that enters the header. Statements in that block on the jump's source line are the initializers, in their original order. A statement on an earlier line stays before the loop. `$total = 0` on the line before `for ($i = 0; ...)` is not an initializer. Initializers that span lines keep only the jump's line in the `ForStatement`; the earlier lines remain statements that run once before the loop. A statement written on the same line as the `for` cannot be distinguished from a comma initializer, so it is included. The same line rule applies to `while`: a statement on the `while` line is presented as a `for` initializer with an empty update list.

An empty update compiles as a `while`, and the analyzer kind stays `while`. When the prologue jump has an initializer on its line, the statement is a `ForStatement` with an empty update list. `for (; true;)` with no update has the same shape as `while (true)` and stays a `WhileStatement` whose condition is a literal true. `for (;;)` is that shape when the synthesized true uses the previous instruction's line and that line is not the prologue jump's line. Its condition is null. `for ($i = 0; ; $i++)` is a `for`, and the synthesized true shares the `for` line, so the condition stays a literal true.

The condition is the header `JMPZ` or `JMPNZ` expression, with the same polarity as `while`. The pass does not invent it from block order. An assignment or a call that the expression pass folded into that operand stays the condition. A header that ends with `COALESCE`, such as `for ($i = 0; $value = $items[$i] ?? null; $i++)`, is `unknown`. The original statements stay in place, including the assignment, and the diagnostic is the analyzer's reason.

Updates are the expressions at or after `continueOpline` on the straight path from that instruction to the header. They are removed from the body and are not copied onto a `continue`. `continue` is a `ContinueStatement` for the loop whose continue block the CFG edge lands on. `continue 2` names the outer loop. `break 2` names the outer loop the break leaves. A `return` or `throw` stays in the body. A `for` inside a `while` or `do-while`, and a `while` or `do-while` inside a `for`, is the child statement of the parent body. The parent's update is not taken from the child.

A `foreach` is entered at its `FE_RESET_R` or `FE_RESET_RW`. That instruction is not a member of the natural loop. The header is the following `FE_FETCH_R` or `FE_FETCH_RW`, and the back edge is the `JMP` to that fetch. The iterable is the expression already stored on the reset operand. It is not taken from the iterator temporary. A variable and a call stay that expression, once. The value target is `op2` when it is one compiled variable. The value is by reference only when the reset and the fetch are both the `RW` opcodes. That is not represented as an assignment, and the structured statements do not clear the variable after the loop. A key is present when the fetch result is a temporary and the next statement assigns that temporary to one compiled variable. The key assignment is ordinary assignment even when the value is by reference. `FE_RESET_*`, `FE_FETCH_*`, and `FE_FREE` are not statements. `FE_FREE` on the normal exit, and the `FE_FREE` the compiler emits before `break`, `continue`, or `return`, are omitted. A `for` update or a `do-while` test that shares the exit's block stays in that statement.

The body is the fetch fallthrough. An `if` in the body, a nested `foreach`, and a `while`, `do-while`, or `for` in either direction use the same walk. `break` and `continue` keep the depth and the loop id from `LoopControl`, including `break 2` and `continue 2`. A `return` or `throw` stays in the body. The statements around it stay as well.

A value target that is not a compiled variable stays unstructured. That includes `foreach ($pairs as [$key, $value])` and `foreach ($pairs as $key => [$value, $extra])`. The original statements stay, with a diagnostic that names the fetch, and a containing `for` stays a `for` when the failure is only that foreach. A mismatch between the reset and fetch reference forms is also left unstructured. `unknown` stays as its original statements, in member-block order, plus one `unstructured` diagnostic. The diagnostic uses the analyzer's reason when there is one. An ambiguous `while` or `do-while` uses the same fallback for its own blocks. A reachable block the walk missed is appended with a diagnostic. Statements that belong only to an unreachable block are appended as well, so the original statements are still present.

A jump-table `match` is a `MatchExpression`, not a statement. This walk skips that region and continues at the result merge, so an `if` or a loop that contains the match stays structured. A comparison-chain match stays in its original instructions. `try` / `catch` / `finally` is a separate pass, described below. A loop header of `JMPZ_EX`, and a loop that contains `GOTO`, stay `unknown`.

## Switch statements

`LoopStructurer` rebuilds `SWITCH_LONG` and `SWITCH_STRING`. `ConditionalStructurer::structure()` still refuses both, and `MATCH`. PHP compiles a small `switch`, or one whose labels are not all integers or all non-numeric strings, as `JMPNZ` comparisons. That chain stays nested `if` statements. This pass does not turn an arbitrary comparison chain into a switch.

| Type | What it records |
| --- | --- |
| `SwitchStatement` | The subject expression already stored on the `SWITCH_*` operand, the cases in source order, the type-fallback comparisons, the `SWITCH` opline, the source line, and a switch id. Ids are `S0`, `S1`, … in opline order. |
| `SwitchCase` | One or more `Literal` labels, the ordered body, whether the case is the default, whether a path falls through to the next case, and the body entry opline and line. |
| `SwitchFallbackArm` | One loose comparison on the type-mismatch path. `value` is that comparison's literal, the same object as the case label. `caseIndex` selects the case body it jumps to. The arm does not own the body. |
| `SwitchBreakStatement` | A `BRK` whose `breaks[]` row is a structured switch. The source depth is op2. `switchId` is the switch that row names. |
| `SwitchContinueStatement` | A `CONT` whose `breaks[]` row is a structured switch. It is not rewritten as a break and it is not a loop continue. |

The subject is the expression on the switch operand, once. A call or another expression folded into that operand stays the subject, including its side effects and evaluation order. The subject is not inferred from a temporary name or from block order. The `SWITCH` opcode is not also left as a statement.

Case labels and destinations come from the CFG `switch` edges. The edge's case value is the label, and its target is the body block. The `default` edge is the `extended_value` target. Labels keep their type: an integer stays an int and a string stays a string. Several labels with the same target are one case, in jump-table order, and the body is not copied. PHP emits no opcodes for an empty case, so those labels share the next case's body. Cases are ordered by the body entry opline, which is source order, not by block id and not by sorting the numeric keys.

A default whose target is not the switch break target is a case. It may also carry labels when those labels jump to the same body, and it may be the first case when its body is the earliest. An empty default in the last position uses the same opline as the break target, so it is not a separate case and no label is invented for it. A switch with no default uses that same break-target opline for `extended_value`.

The type-fallback path is the `fallthrough` edge after `SWITCH_LONG` and `SWITCH_STRING`. It is the compiler's `==` or `CASE` chain for a subject whose type misses the jump table. A same-type miss uses the `default` edge, which is the end of the switch when there is no default body. The chain is accepted only when it is one `JMPNZ` per jump-table edge, in that order, comparing the same subject to the same literal, followed by a `JMP` to the default edge. Each comparison becomes a `SwitchFallbackArm`. `switch (true)` compares the case constants themselves, and a duplicate source label is omitted from the jump table while the chain still has both bodies. Those shapes do not match. The switch region stays the original statements, with an `unstructured` diagnostic. A containing loop or conditional stays structured. No partial `SwitchStatement` is emitted, and no case label is invented.

Fallthrough is explicit. A case falls through when a block visited while emitting its body has a successor that is the next case's entry. Adjacent block ids are not used. The statements stay in the earlier case and are not copied. An explicit break is a `SwitchBreakStatement`, and that case does not fall through. A `return` or `throw` ends the case the same way.

`BRK` and `CONT` walk `breaks[]` the way the CFG does: op1 is the row index and op2 is the depth. A row with the switch flag is a switch control. When that switch was structured, and the CFG target opline is the row's break target or continue target, the statement is `SwitchBreakStatement` or `SwitchContinueStatement` for that switch id. PHP gives a switch the same continue target and break target, so the continue leaves the switch, but the opcode stays `CONT`. When that target is also a loop's continue block, `LoopAnalyzer` still records the loop id. The `breaks[]` row decides, so the statement is not a `ContinueStatement`. `break 2` or `continue 2` whose row is a loop is a `BreakStatement` or `ContinueStatement` for that loop. `break 2` whose row is an outer switch is a `SwitchBreakStatement` for the outer id. A switch that was not structured, including a comparison chain, keeps a switch-local `BRK` or `CONT` as an opaque statement with an `unresolved` diagnostic. That includes a continue whose CFG target happens to be a loop header. It does not become a loop break. A control that cannot be tied to a structured switch or a resolved loop stays opaque with that diagnostic.

The same walk places a switch inside `if`, `while`, `do-while`, `for`, and `foreach`, and an `if`, a loop, or another switch inside a case. The switch stops at its break-target block, so a `for` update, a `while` header, and a `do-while` latch stay on the loop. A failure restores that attempt and emits the original statements of the switch region only. If the break target cannot be found, the failure propagates and the caller keeps its own fallback. The graph and the per-block reconstruction are not modified.

A compiler `FREE` of a temporary or variable subject, with the `ZEND_FREE_ON_RETURN` or `ZEND_FREE_SWITCH` bit, is omitted between the switch and its break target. It is not a user statement. A compiled-variable subject has no such free. `MATCH` is not a switch. It is reconstructed as an expression, below.

## Match expressions

`src/Expr/MatchReconstructor.php` runs after the per-block expression pass. It rebuilds a PHP 8.4 `MATCH` jump table into one `MatchExpression`. The match is an expression. It is not a statement, and it does not reuse `SwitchStatement` or `SwitchCase`. `ConditionalStructurer::structure()` still refuses `MATCH`. `LoopStructurer` does not build a match statement. It skips the blocks of a recognized match and continues at the result merge, so an `if` or a loop that contains the match stays structured.

PHP emits `MATCH` when every non-default condition is a constant integer or string and there are at least two of them. Mixed integer and string keys are allowed, and a numeric string stays a string. One condition, a variable, a boolean, or any other non-constant compiles to `IS_IDENTICAL` or `CASE_STRICT` and jumps. Those chains are left as they are. This pass does not turn an `if` chain into a match.

| Type | What it records |
| --- | --- |
| `MatchExpression` | The subject expression already stored on the `MATCH` operand, the arms in source order, the `MATCH` opline and line, `fallbackOpline` (the `extended_value` target), and `unhandledOpline` when that target is `MATCH_ERROR`. |
| `MatchArm` | The arm entry opline, the result expression's line, the condition literals in jump-table order, that result expression, and whether the arm is the default. A default arm has no condition literals. |

The subject is evaluated once. A variable read, a literal, an arithmetic or comparison expression, a function call, or another expression already folded into the operand stays that object. It is not copied into the arms and it is not inferred from a temporary name. Its side effects stay on that object.

Conditions come from the CFG `switch` edges, which are the jump-table keys in table order. An integer stays an int and a string stays a string. These are identity labels, the same comparison PHP `match` uses, not the loose equality of `switch`. The `default` edge is `extended_value`. No label is invented. Several keys that jump to one body are one arm and one result object. Arms are ordered by that body's entry, which is source order, so a default written first stays first.

Each arm writes one shared temporary with `QM_ASSIGN` and jumps to the same block. The arm result is the expression already stored on that `QM_ASSIGN`. A literal, a variable, a comparison, or a function call compiled in the arm stays that expression, including the call's side effects. Only that arm's expression is the arm result. A nested jump-table match is reconstructed first, and that `MatchExpression` object is the outer arm's result. The reads of the shared temporary, including the `VERIFY_RETURN_TYPE` the compiler inserts before a typed `return`, all receive the same `MatchExpression`.

That expression is the value of an assignment, a `return`, an operand of `+` or `.`, or the argument of a direct call. When `INIT_FCALL` or `INIT_FCALL_BY_NAME` is interrupted by this `MATCH`, and the merge block sends the result as the call's only positional argument and then executes the call, the result is one `FunctionCall` whose argument is the match. A call with more arguments, or any other shape, is not stitched.

A match with a default arm has `unhandledOpline` null and one arm with `isDefault` set. A match without a default points `extended_value` at `MATCH_ERROR`. `unhandledOpline` is that instruction. The unmatched path throws `UnhandledMatchError`. It is not a default arm, it is not a `return`, and it is not turned into a statement. No default is invented. A match inside a catch stays this expression. This pass does not build the try.

The compiler `FREE` of a temporary or variable subject, with the switch-free bit, is omitted at the merge. It is not a user statement. A compiled variable or a constant subject has no such free.

When the shape is not the one above, the original `MATCH`, the arm instructions, and `MATCH_ERROR` stay in place, with one `unstructured` diagnostic that names the `MATCH` opline and the reason. An arm that does not end in `QM_ASSIGN` and `JMP`, such as a ternary, and a result temporary that is also written by a body the jump table does not name, are refused. No partial `MatchExpression` is emitted. Branch values are not discarded. The CFG is not changed, and other conditionals, loops, and switches are not changed by a match this pass does not accept.

## Try, catch, and finally

`src/Cfg/ExceptionRegions.php` locates each `try_catch` row. It does not follow exception edges as ordinary successors, and it does not infer the region from block ids or from instruction order alone. The protected start is the row's `try` opline. Without a finally, the normal continuation is the target of the `JMP` the compiler emits immediately before the first `CATCH`, and that target is `spanEnd`. With a finally, `spanEnd` is the opline after `FAST_RET`, and that same JMP targets the normal `FAST_CALL` instead. The try body is every statement from the protected start up to the catch dispatch or that normal call. It includes a `return`, a `throw`, a function call, a division, a conditional, a loop, a switch, a match, and a nested try. The end is not the first return or jump inside the body.

The exception edge on a block is the VM's handler boundary. It stays off the successor list. Typed clauses come from the `CATCH` sequence. Each `CATCH` op1 is the class-name constant, in the spelling the compiler stored. The following lowercase literal is not the operand and is not used. The names stay in that order. `result` is the caught compiled variable, without `$`, or unused when the clause has no variable. No class name or variable name is invented. `extended_value` bit 0 is `ZEND_LAST_CATCH`. That bit marks the last class of the last clause. A non-last `CATCH` also has op2, which the CFG records as a `catch_next` edge to the next `CATCH`.

A union catch is several `CATCH` instructions that share one body. For every class except the last class of the clause, the compiler emits `CATCH`, then a `JMP` to the shared body, and sets op2 to the next `CATCH`. The last class of the clause falls through into that body. Those names are one `CatchClause`. Separate clauses do not share that body. The last class of a clause that is not the last clause has op2 pointing at the next clause's first `CATCH`, and that clause body ends with a `JMP` to the continuation. The last clause has no exit jump; its body falls through to the continuation. A `JMP` between two `CATCH` instructions that targets the continuation, rather than a shared body, closes an empty clause and starts the next one. A catch-dispatch `CATCH` or that jump is not a statement of any catch body.

One empty shape is ambiguous. `catch (A|B) {}` and `catch (A) {} catch (B) {}` written on one line, with the same variable or with no variable, compile to the same chain: every non-last `CATCH` is followed by a `JMP` to the continuation, op2 is the next `CATCH`, and the last `CATCH` sits immediately before the continuation. Different source lines cannot be one union, and different variables cannot be one union, so those stay separate clauses. A non-empty body is unique because the union jump targets the body. When the empty same-line chain cannot be told apart, the region stays unstructured. The diagnostic is `Empty catch clauses cannot be distinguished from a union catch.` The original statements stay, including each `CATCH`.

`src/Stmt/ExceptionStructurer.php` emits the statements. It does not run `ConditionalStructurer` or `LoopStructurer` over the whole op array first. It asks `LoopStructurer` to walk from the entry. When that walk reaches a block that starts a region, the region is returned here. Try, catch, and finally bodies are then structured by the same walk, stopped at the region's boundaries. An `if`, a loop, a jump-table switch, and a jump-table match inside a body stay those existing objects. A try inside an `if`, `while`, `for`, or `foreach` is one statement of that region. Nested tries that start at the same opline are entered outermost first. The inner try is one statement of the outer body. An exception thrown in an inner catch is not a branch to the outer catch. The inner catch's exception edge still names the outer handler, and it stays off the successor list.

Catch bodies, and a finally body that no ordinary path reaches, are invisible to the main loop analysis. `LoopAnalyzer::includeHandlers()` adds natural loops reachable from those entries by ordinary edges. It does not follow exception edges, does not enter blocks already reachable from the main entry, and does not replace the original dominators. Switch breaks inside a catch use those controls.

| Type | What it records |
| --- | --- |
| `TryStatement` | The protected statements in order, the catch clauses in order, the optional finally, the first protected instruction's opline and line, the first `CATCH` opline or null when there is no catch, and the continuation opline. The compiler emits no opcode for the `try` keyword, so the line is the first protected instruction. |
| `CatchClause` | The exception class names in order, the optional caught-variable name, the body statements in order, the first `CATCH` opline and line, and the `CATCH` oplines that share the clause. A union's names belong to this one clause. This is not a statement. |
| `FinallyClause` | The finally body in order, the body start, the finally keyword's line, the `FAST_RET` opline, and the normal `FAST_CALL` opline. The body is stored once. This is not a statement. |

On success, the `CATCH` instructions of that region are consumed. `FAST_CALL`, `FAST_RET`, `DISCARD_EXCEPTION`, and the jump that skips the finally body are omitted as well. Their `unsupported` diagnostics go with them. A bare jump between catch clauses was never a statement. Every other user statement appears once, as the same object the earlier passes produced.

A finally handler belongs to that same statement. The compiler lays the normal path out as `FAST_CALL`, then `JMP` to the statement continuation, then the finally body, then `FAST_RET`. The row's `finally` opline is the body start and `finally_end` is the `FAST_RET`. The normal `FAST_CALL` is two oplines before the body. Its line is the finally keyword, because the compiler does not emit an opcode for that keyword. `FinallyClause` holds that one body. Catch clauses stay on the statement when the row has both. A `try` / `finally` has an empty catch list and a null catch opline.

Every other `FAST_CALL` whose operand selects this row is an abrupt completion of the protected region: `return`, `throw`, `break`, `continue`, or the `FAST_CALL` of an enclosing finally. The completion stays in the try or catch body, in source order. The `FAST_CALL` in front of it is not a statement. `FAST_RET` does not resume one call per edge. Its `finally_return` successors are the opline after each `FAST_CALL` of the same row, which is every address the runtime might have stored. The CFG keeps that over-approximation. Structuring does not follow those edges and does not copy the body for each one.

A `return` or `throw` written inside the finally is a statement of the finally body. In PHP that completion overrides a pending return or exception. Both the pending statement and the overriding statement stay, each once. `DISCARD_EXCEPTION` frees the pending value and is not a user statement. Neither is the `JMP` that skips the body on the normal path. A `return` of a compiled variable is copied to a temporary before the call, and that temporary does not cross the block, so the `ReturnStatement` object is kept and its value stays unresolved. A literal return keeps the literal. `break` and `continue` keep their depth and the loop id from loop analysis, including `break 2` and `continue 2`.

Nested handlers stay on their own rows. Regions that start at the same opline are entered outermost first, so an inner finally is not attached to the outer statement. A try/catch inside a finally, a finally inside a loop, and a conditional or loop inside a finally use the same walk. An exception thrown in an inner finally is not turned into a branch. Its exception edge still names the outer handler and stays off the successor list.

The row stays unstructured when the normal `FAST_CALL` is missing, the following `JMP` does not target the opline after `FAST_RET`, `FAST_RET` does not use that call's temporary, a `FAST_CALL` for the row lies outside the span, or an abrupt `FAST_CALL` is followed by something other than a return, throw, break, continue, or finally call. The same fallback is used when a body contains a branch this walk cannot structure. A `while` whose body never reaches its header, such as a body that always breaks, is not a natural loop, and a finally that contains that branch is left unstructured rather than inventing the loop. The original statements stay in order, with one diagnostic, and no partial `TryStatement` is emitted. PHP source generation is not part of this pass.

If a supported region cannot be assembled, that region is restored and emitted as its original statements plus one `unstructured` diagnostic. No partial `TryStatement` is emitted. A sibling region is not flattened. `LoopStructurer::structure()` without this pass still returns the original statements when the op array contains an exception edge or a `FAST_CALL`.

```php
$structured = (new Rephper\Stmt\ExceptionStructurer())->structure(
    $opArray,
    $controlFlowGraph,
    $loops,
    $statements,
);
```

## PHP source generation

`src/CodeGen/PhpSourceGenerator.php` renders the structured statements of one op array. It does not read Zend structures, does not inspect the opcode array, does not rebuild expressions or statements, and does not change the control-flow graph. `src/Application/OpArrayPipeline.php` runs the existing passes for one op array from a document the command has already read: build its CFG, reconstruct expressions, reconstruct straight-line statements, structure conditionals, loops, switches, and exception regions, then the command hands the `StructuredStatements` to the generator.

```php
$source = (new Rephper\CodeGen\PhpSourceGenerator())->generate($structuredStatements);
```

The string is a fragment:

```php
<?php
$result = $a + $b;

return $result;
```

It starts with `<?php` and a newline. It has no closing `?>`. Indentation is four spaces. Lines end with `\n`. Top-level statements are separated by a blank line. Statements inside a block are not. The same tree always produces the same text.

That fragment is the body of one op array. It is not a reconstruction of the file that was compiled. Comments and the original whitespace are not in the IR. Function declarations, method declarations, class declarations, namespace declarations, imports, property declarations, class constants, and file-level documentation comments are not emitted. A function op array becomes the statements inside the function, including its `return`, with the parameters left as compiled-variable reads. The script body is a separate op array. Passing the fragment to `php -l` checks the syntax of that body. It does not mean the original file was recovered.

### Expressions

The renderer prints a node only when the tree already has a PHP spelling.

| Node | Rendered form |
| --- | --- |
| `Literal` | `null`, `true`, `false`, a decimal integer, a float, a quoted string, or an array |
| `VariableRead` | `$name` |
| `Assignment` | `$name = expr`, or `$name += expr` when `operator` is set |
| `Binary` | The stored operator, with parentheses only where PHP 8.4 would group differently |
| `Unary` | `!`, `~`, or `(bool)` |
| `FunctionCall` | `name(arguments)` for a direct named call |
| `Argument` | A positional expression, `name: expr`, or `...expr` |
| `MatchExpression` | `match` with the arms in stored order |
| `MatchArm` | The condition literals, or `default` when `isDefault` is set |

`Opaque`, `Unresolved`, and `ReturnValue` are not expressions with a source form. Rendering one throws `Rephper\CodeGen\SourceGenerationException`. The exception names the opcode when the node has one, and the opline and source line. Nothing is returned, so a caller that writes the result only after `generate()` returns does not keep a partial file.

`ECHO` is the one opaque statement with a verified spelling. `echo $text;` is printed when the operand is itself renderable. `echo strlen($text)` stays unsupported because `STRLEN` is still an `Opaque` expression. No other opcode is guessed into source.

Parentheses follow PHP 8.4 precedence and associativity, not the number of child nodes. `*`, `/`, and `%` bind tighter than `+`, `-`, and `.`, and those six associate to the left, so `$a + $b * $c` needs no extra parentheses and `$a - ($b - $c)` does. `+`, `-`, and `.` share one level: `($a . $b) + $c` is `$a . $b + $c`, and `$a + ($b . $c)` keeps the parentheses. `<`, `<=`, `==`, `!=`, `===`, and `!==` do not associate, so `($a < $b) < $c` stays parenthesized. Assignment and the compound assignments associate to the right. A prefix operator is not wrapped around another prefix operator: `~!$a` is already `~(!$a)`, and `!~$a` is already `!(~$a)`. `!foo()` and `(bool)foo()` need no parentheses around the call. A `match` expression is a primary, so it is parenthesized only when a lower-precedence operator around it would not parse as that tree.

`-$a` and `+$a` stay multiplications by `-1` and `1`. The tree does not contain a unary plus or minus. `$a > $b` stays the compiled `$b < $a`. `&&` and `||` are not operators in the tree.

Literals keep the PHP type stored on the node. `null`, `true`, and `false` stay those words. An integer is decimal. `PHP_INT_MIN` is printed as `PHP_INT_MIN` because the token `-9223372036854775808` is not an integer in PHP. A float is a float token, including `1.0` when the IR value is the integer `1` with type `float`. `-0.0` is `-0.0`. `NAN`, `INF`, and `-INF` are the spellings of the IR strings `"NaN"`, `"Infinity"`, and `"-Infinity"`. A string uses single quotes when every byte is printable UTF-8. Quotes and backslashes are escaped there. Control bytes use a double-quoted string with `\\`, `\"`, `\$`, `\n`, `\r`, `\t`, and `\xHH`. A binary string is the IR form whose bytes were written as `\u00XX`; each code point's low eight bits are emitted as `\xHH`, so the source stays ASCII and evaluates to the original bytes. An array is `[]` in element order. An integer key is omitted only when it is the next implicit index. A string key stays quoted, including `'0'`. A recursive array, a constant AST, `undef`, and `unsupported` have no source form and throw.

A match keeps arm order, condition types, several conditions on one arm, and a default wherever it was stored. A match with no default arm does not gain one. `unhandledOpline` is the `MATCH_ERROR` path. It is not printed as `default`.

A reference argument is the variable the compiler sent. PHP 8 rejects `&` at the call site, so `sort($items)` is printed without it. The reference is a property of the callee, and this pass does not print the callee's signature. Named and unpacked arguments stay in send order.

### Statements

| Statement | Rendered form |
| --- | --- |
| `ExpressionStatement` | The expression followed by `;` |
| `ReturnStatement` | `return expr;` |
| `ThrowStatement` | `throw expr;` |
| `IfStatement` | `if` / `elseif` / `else`, with braces |
| `WhileStatement` | `while (condition) { body }` |
| `DoWhileStatement` | `do { body } while (condition);` |
| `ForStatement` | Initializers, condition, and updates in stored order. A null condition is omitted |
| `ForeachStatement` | `foreach (iterable as value)`, with `$key =>` and `&` when the tree has them |
| `BreakStatement`, `ContinueStatement` | `break` or `continue`, plus the level when it is greater than 1 |
| `SwitchStatement` | `switch`, cases in stored order |
| `SwitchBreakStatement` | `break`, not an invented case break |
| `SwitchContinueStatement` | `continue`. It is not rewritten as `break` |
| `TryStatement` | `try`, then each catch, then `finally` when present |
| `OpaqueStatement` | `echo expr;` when the opcode is `ECHO` and the operand can be rendered |

An `if` with `elseStatements === null` has no else. An empty array is an empty else. Switch cases keep shared labels and fallthrough. A break is printed only when the body already has one. The type-fallback comparisons are how the compiler implements the same `switch`; they are not copied after it. Catch types stay in order and are joined with `|`. A null catch variable is omitted. `break 2` and `continue 2` keep that level.

`return null;` is the null literal the compiler stored for `return;`. The two spellings are not distinguished. A stored function name is printed as stored, including a name the compiler lowercased.

An unsupported statement, an unresolved temporary, or any other node without a spelling throws `SourceGenerationException`. Generation is all or nothing.

```bash
php8.4 bin/rephper source out.json --op-array=3
php8.4 bin/rephper source out.json --op-array=calculate
php8.4 bin/rephper source out.json --op-array=main --output=body.php
```

An integer `--op-array` selects that id. Any other value selects the name, and `main` selects the script body. Without `--output` the fragment is written to stdout. The command returns 1 when the file, the op array, or generation fails, and the message names what stopped it. The command does not load the Zend extension and does not execute the original PHP file. It does not format expressions itself.

## Commands

`bin/rephper` is a Symfony Console application. It loads Composer’s autoload and does not load the Zend extension. With no command it prints the command list and exits 0.

```bash
php8.4 bin/rephper
php8.4 bin/rephper graph out.json
php8.4 bin/rephper graph out.json --op-array=calculate
php8.4 bin/rephper graph out.json --op-array=main
php8.4 bin/rephper help graph
php8.4 bin/rephper source out.json --op-array=calculate
php8.4 bin/rephper source out.json --op-array=0 --output=body.php
```

`graph` reads one IR JSON file and prints one block list per op array. `--op-array` limits the output. `main` selects the script body, whose JSON name is null. Validation errors go to stderr and the process exits 1. That includes a missing file, invalid JSON, an IR version other than 1, a jump the builder cannot accept, an unsupported opcode, and an unknown op-array name. A missing `file` argument also exits 1.

`source` reads one IR JSON file and writes the PHP body of one op array. The choice and the failure behavior are described above. There is no `inspect` or `export` command. Inspection and export still go through `php -l` and `REPHPER_IR`. There is no command that prints expression trees. Reconstruction is the `Rephper\Expr\Reconstructor` library call, and it needs the op array together with the graph the builder produced for that same op array.

## Tests

```bash
composer install
make test
make test-unit
make test-integration
```

`composer test`, `composer test:unit`, and `composer test:integration` run the same PHPUnit suites. `make test` and `make test-integration` build the extension first and pass it to PHPUnit through `REPHPER_PHP` and `REPHPER_EXTENSION`. The unit suite does not load the extension. Run PHPUnit with the normal PHP extensions enabled. The spawned `php -l` processes still use `-n`, so OPcache stays out of the opcode dump.

`tests/Unit/` covers the reader, control-flow edges, exception edges, expression trees, named function calls, straight-line statements, structured conditionals, dominators, natural loops, `while`, `do-while`, `for`, `foreach`, jump-table `switch` statements, jump-table `match` expressions, `try` / `catch` statements, PHP rendering of those trees, invalid IR, and the `graph` and `source` commands through Symfony’s `CommandTester`. One unit test also runs `bin/rephper` so the executable bootstrap is checked. Version selection still treats `8.10` as newer than `8.9`, and PHP 8.4 remains the only supported compiler.

`tests/Integration/SourceGeneratorTest.php` reads checked-in PHP 8.4 IR, runs the reconstruction pipeline, and lints each generated body with `php8.4 -n -l`. That lint does not load the Rephper extension and does not execute the original fixture. The same input is generated twice and the text must match. A failure throws before a source file is written.

`tests/Integration/ExporterTest.php` compiles every fixture with the extension when `extension/modules/rephper.so` exists. Without that file the integration tests are skipped. For each fixture it checks:

1. The extension loads with no startup output and `php -m` lists `Rephper`.
2. `php -l` succeeds and the dump contains an op array and opcode lines.
3. Feature snippets are present, such as `JMPZ`, `MATCH`, `CATCH`, and `FAST_RET`.
4. The normalized text dump matches `tests/Expected/PHP/<version>/*.txt`.
5. The IR is valid JSON with the expected operand and literal types, and feature-specific values are present.
6. A second compile writes the same JSON.
7. `REPHPER_IR` pointed at a directory does not remove that directory.
8. Lint does not execute the file and does not compile a file it would `require`.

`Fixtures/Common/` is syntax every supported PHP version can compile. `Fixtures/PHP/X.Y/` is syntax introduced in that version. PHP 8.4 runs `Common/`, `PHP/8.0/`, `PHP/8.1/`, `PHP/8.2/`, and `PHP/8.4/`. The engine implementation is still only 8.4, so the expected opcodes and IR are stored under `Expected/PHP/8.4/`.

Expected dumps are per PHP version. The same source is not assumed to produce the same opcodes on another version.

Regenerate the 8.4 text dumps and IR files after an intentional compiler-output change:

```bash
make build
REPHPER_PHP=php8.4 \
REPHPER_EXTENSION=$PWD/extension/modules/rephper.so \
REPHPER_UPDATE_EXPECTED=1 \
php8.4 vendor/bin/phpunit --testsuite integration --filter testFixture
```

Paths inside the dump are rewritten to repository-relative paths before comparison, including the lowercased filename stored in an anonymous class runtime key.

## What compilation sees

Checked with `php8.4 -l` and this extension on PHP 8.4.27:

| Declared in the linted file | Received by `op_array_handler` |
| --- | --- |
| Main script | Yes |
| User-defined function | Yes |
| Method | Yes |
| Closure | Yes |
| Anonymous class and its methods | Yes |
| Property hook (`$prop::get`, `$prop::set`) | Yes |
| A function declared in a file pulled in with `require` | No |

Linting one file does not compile files it would include at runtime, and it does not execute the file. A probe that calls `exit(7)` still finishes lint with status 0. Do not boot an application or run service providers to inspect a file.

## GitHub Actions

`.github/workflows/build.yml` runs on pushes and pull requests. The operating system and the PHP version are separate matrix axes. The active matrix is Ubuntu 24.04 and PHP 8.4. The job installs that PHP version, its development tools, and Composer, installs the locked dependencies with `composer install --no-interaction --no-progress --prefer-dist`, then runs `make info`, `make test-unit`, `make test-integration`, and a separate check that the extension loads with no startup output. Unit tests run before the extension is built. The integration target builds it and runs the exporter suite.

## Current limitations

- PHP 8.4 only.
- Ubuntu is the only operating system that has been targeted. It has been built and tested locally on Linux with PHP 8.4.27.
- PHP source generation prints one op-array body. It does not restore comments, the original whitespace, function declarations, method declarations, class declarations, namespace declarations, imports, property declarations, class constants, or file-level documentation comments. `if`, `if` / `else`, `if` / `elseif` / `else`, and nested conditionals are structured from `JMPZ` and `JMPNZ`. `ConditionalStructurer::structure()` still returns the original statements for an op array that contains a loop or a jump-table switch. `while`, `do-while`, `for`, `foreach`, and jump-table `switch` statements come from `LoopStructurer`, including an `if` inside them and one of those regions inside an `if`. A `for` initializer is the trailing run of prologue statements on the prologue jump's line. Earlier lines stay before the loop, including the earlier lines of a multi-line initializer. A statement on the same line as `for` or `while` is an initializer. An empty update is classified as `while` and presented as `for` when that initializer is present. `for (;;)` has a null condition when the synthesized true uses the previous instruction's line. `for (; true;)` with no update stays `while (true)`. `for ($i = 0; ; $i++)` keeps a literal true. A `foreach` value or key is structured only when it is one compiled variable. Destructuring stays unstructured, and that failure does not by itself flatten a containing `for`. By-reference iteration is the `RW` reset and fetch, not an assignment. `unknown` loops stay in their original statements with an `unstructured` diagnostic. A short-circuit loop condition (`JMPZ_EX` or `COALESCE`) and a backward `goto` stay `unknown` and unstructured, and an assignment in that condition is not moved before the loop. An unreachable cycle is not a loop. `SWITCH_LONG` and `SWITCH_STRING` become a `SwitchStatement` when the type-mismatch chain restates the jump table. A small switch that PHP compiles as comparisons stays nested `if` statements. `switch (true)` and a duplicate case label do not match that chain, so that switch region stays in its original statements and does not flatten an enclosing loop. An empty trailing default is not distinct from no default. A `continue` inside a switch stays a `SwitchContinueStatement` and is not rewritten as a break. A switch-local `break` of a switch that was not structured stays an opaque statement, not a loop break. `break 2` and `continue 2` keep the source depth. A jump-table `match` is a `MatchExpression`. A comparison-chain `match`, including one condition or a non-constant condition, stays in its original instructions. An arm that branches internally, and a duplicated condition whose second body is not in the jump table, stay unrestructured, with an `unstructured` diagnostic. `MATCH_ERROR` is the unmatched path of a match that has no default. It is recorded on the expression and is not a statement. `try` / `catch`, `try` / `finally`, and `try` / `catch` / `finally` are a `TryStatement` with one optional `FinallyClause`. Empty catch clauses written on one line cannot be told apart from one empty union catch, so that region stays unstructured. A compiled-variable return copied across `FAST_CALL` stays unresolved. A `while` that never returns to its header is not a natural loop. An empty else that the compiler deletes is an `if` without else. A `return` after an `if` that has no explicit else stays outside the conditional. `$a > $b` remains the compiled `$b < $a` comparison. A repeating edge of kind `false` wraps the stored condition in `!`; the PHP 8.4 fixtures repeat on a true edge, and `while (!$n)` already stores that `!`. An assignment consumed in the same block is that use, not a second root.
- Direct named function calls are reconstructed from `INIT_FCALL` / `INIT_FCALL_BY_NAME`, their sends, and the matching `DO_ICALL`, `DO_UCALL`, or `DO_FCALL_BY_NAME`. Dynamic calls, method calls, static calls, constructors, and first-class callables stay opaque. `strlen($text)` on a compiled variable remains the `STRLEN` opcode. A named argument that the compiler rewrote into a positional send is positional in the tree. The stored function name can be lowercased.
- CFG semantics are implemented for PHP 8.4, `pre_pass_two`, and absolute opline jumps only. `src/Cfg/Php84/Semantics.php` is the only opcode switch. Another PHP version needs its own semantics class.
- `HANDLE_EXCEPTION`, `USER_OPCODE`, and `CALL_TRAMPOLINE` are rejected. The builder does not guess their successors.
- `BIND_INIT_STATIC_OR_JMP` and `JMP_FRAMELESS` follow the PHP 8.4 handlers. The current fixtures do not emit them; the CFG tests cover them by editing a real instruction.
- Exception edges are one per block, from `try_catch` ranges. The IR does not name the exception class a `THROW` can reach, so the edge is the VM's handler boundary, not a typed catch match. Typed clauses come from the `CATCH` operands and `catch_next` edges. Those edges are not added to the successor list.
- `try` / `finally` and `try` / `catch` / `finally` are `TryStatement` values with one `FinallyClause`. `FAST_CALL` and `FAST_RET` stay the CFG's `finally_call` and `finally_return` edges. `FAST_RET` lists every `FAST_CALL` continuation for that row; the statement tree does not duplicate the body. A compiled-variable `return` copied through a temporary stays unresolved on the `ReturnStatement`. A `while` that never returns to its header is not a natural loop, so a finally that contains only that branch stays unstructured. `GOTO` through a finally is unstructured. A generated body is not the original source file.
- `BRK` op1 is a `breaks` index and `GOTO` op1 is an unwind count. The CFG uses the `breaks` and `labels` tables. The JSON field names are unchanged.
- OPcache must be off for a stable dump and a stable IR file.
- Included and required files are not compiled with the file that names them.
- The extension has no directory walker. One process writes one IR file for the compilations in that request. The integration tests pass one file at a time.
- Jump targets are absolute opline numbers from before `pass_two()`, not the runtime offsets.
- `BRK`, `CONT`, and `GOTO` are still the compile-time pseudo-opcodes. `GOTO` targets are in `labels`, not in the `GOTO` operand.
- `arg_info.default_value` is uninitialized at this hook. A default is `default_literal`, an index into `literals` taken from `RECV_INIT`.
- Class rows are captured when the main script reaches `pass_two()`. Properties and constants declared on another class are not copied in. A class may not be marked `linked` yet. Enum cases can still be `CONST_ENUM_INIT` AST nodes.
- Expected IR files were produced with a non-ZTS, non-debug PHP 8.4. `php.zts` and `php.debug` record the compiler that produced the file.
- The exporter keeps its document in process-global memory. That matches the CLI lint process used here. ZTS was not tested.

## Roadmap

- A complete PHP file. The generator prints one op-array body and does not yet emit declarations, namespaces, or imports. `try` / `catch`, `try` / `finally`, and `try` / `catch` / `finally` are already a `TryStatement`. Empty catch clauses on one line stay unstructured when they cannot be told apart from one empty union. Natural loops, `while`, `do-while`, `for`, `foreach`, and jump-table `switch` are already structured from the control-flow graph. A jump-table `match` is already one expression. A comparison-chain switch stays nested conditionals. A comparison-chain `match` stays in its original instructions. A `foreach` value that is not one compiled variable stays unstructured. `if` / `elseif` / `else` is already structured from it, including inside those regions.
- Expression reconstruction across basic blocks, including `&&` and `||`. Method calls, dynamic calls, constructors, and first-class callables are still separate instructions.
- More PHP versions, each with its own compatibility directory and expected dumps.
- More operating systems, as extra `os` matrix entries once the build has been verified there.
- Inspecting a directory of source files without executing the application.
