# Rephper

Rephper inspects PHP source after the Zend Engine has compiled it. A PHP 8.4 Zend extension prints compiled op arrays and writes them as versioned JSON. A separate PHP program reads that JSON, validates it, and builds a control-flow graph for each op array. Reconstructing PHP source from the graph is a later milestone. The decompiler is not part of this tree.

## Objectives

1. Accept PHP source.
2. Compile it with the Zend Engine.
3. Read the compiled opcodes and their metadata from Zend structures.
4. Serialize that representation as IR JSON.
5. Read the JSON without loading the Zend extension, and build a control-flow graph for each op array.
6. Reconstruct readable PHP from the graph. Not implemented yet.

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
              v
         src/Expr/Reconstructor.php
              |
              v
         expression trees for one basic block
              |
              v
         future statement reconstructor
```

The reader, the IR model, the CFG builder, and `php bin/rephper graph` do not load the extension and do not use Zend C structures. Opcode control-flow rules for this stage live in `src/Cfg/Php84/Semantics.php`.

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

`-n` skips `php.ini`. In particular it skips OPcache. OPcache changes compiler flags, so the opcode dump is not comparable with OPcache enabled. The tests always pass `-n`.

`-l` is PHP's lint mode. It compiles the file and does not execute it. The dump is written to stderr. Stdout is PHP's `No syntax errors detected` line.

Loading the extension makes every subsequent compilation print op arrays to stderr. Load it for inspection. Do not enable it globally in `php.ini` until the dump is optional.

## Intermediate representation

The IR is the boundary between the Zend Engine and a future decompiler. The decompiler is meant to read the JSON file. It does not load the extension and it does not see `zend_op` or `zval` pointers.

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
| `catch_next` | A `CATCH` that is not the last one also jumps to the next catch type. The last catch only falls through. |
| `assert_skip`, `initialized`, `frameless` | Assertions disabled, a static variable already initialized, and a frameless call that hits. Each also falls through on the other path. |
| `catch`, `finally` | Exception edges. These are not successors. |

`RETURN`, `RETURN_BY_REF`, `GENERATOR_RETURN`, `THROW`, and `MATCH_ERROR` end a block and have no successor. PHP 8.4 has no `EXIT` opcode. `YIELD` and `YIELD_FROM` stay inside the block: suspension is not a separate edge. A comparison's `smart_branch` flag is kept on the operand and ignored here, because the following `JMPZ` or `JMPNZ` is still its own instruction.

Exception edges follow the VM's try/catch/finally dispatch for one thrown exception. The innermost `try_catch` row that contains the block start is used. An opline before `catch` goes to the catch block. An opline before `finally` goes to the finally block. An opline inside the finally body walks to an outer row. A `THROW` with no enclosing handler has no exception edge. The graph does not add an "uncaught" block.

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

`src/Expr/Reconstructor.php` reads one op array and the CFG already built for it. It does not change the IR or the graph, and it does not load the extension. Reconstruction stays inside a single basic block. A temporary defined on another path is not copied in.

```php
$reconstruction = (new Rephper\Expr\Reconstructor())->reconstruct($opArray, $graph);
```

Each block has an ordered list of expressions. A temporary result is folded into the later instruction that uses it, and both uses of the same slot share one expression object. The `opline` on each node is the instruction that created it, so the original evaluation order remains visible. Instructions that are not expressions stay in that list as opaque nodes.

| Node | What it records |
| --- | --- |
| `Literal` | The IR literal type and value. Numbers, strings, booleans, and null stay distinct. |
| `VariableRead` | A compiled variable name. Temporary slot numbers are not variable names. |
| `Binary` | `+`, `-`, `*`, `/`, `%`, `.`, `===`, `!==`, `==`, `!=`, `<`, `<=`, with the operands in the order the opcode uses. |
| `Unary` | `!` for `BOOL_NOT`, `~` for `BW_NOT`, and `bool` for `BOOL`. |
| `Assignment` | A write to a compiled variable. `operator` is null for `=`. `+` means `+=`, from `ASSIGN_OP`'s `extended_value`. |
| `ReturnValue` | The returned value. A `return;` is a return of the null literal the compiler emitted. |
| `Opaque` | An instruction kept in place because it is not one of those expressions. |
| `Unresolved` | A `tmp` or `var` used in a block that does not define it. |

`RECV` and `VERIFY_RETURN_TYPE` are opaque and do not produce a diagnostic. Any other opcode that this pass does not fold, including `STRLEN`, `JMPZ_EX`, and `JMPNZ_EX`, is opaque and adds an `unsupported` diagnostic. The diagnostic names the opcode, opline, source line, block, and reason. A const index that is not in the literal table is invalid IR and throws `ExpressionException`.

Two compiled forms are easy to misread as source syntax:

- `-$a` and `+$a` are `MUL` by the literals `-1` and `1`. The tree is a multiplication.
- `$a >= $b` is `IS_SMALLER_OR_EQUAL` with the operands swapped, so the tree is `$b <= $a`.
- `$a && $b` and `$a || $b` are `JMPZ_EX` or `JMPNZ_EX` plus `BOOL` in later blocks. Those blocks are not joined into `&&` or `||`.

`strlen($text) + $n` keeps `STRLEN` as the left operand of `+`. The call is not dropped and not reordered ahead of `$n`.

```text
$result = $a + $b * $c

Assignment result
    Binary +
        VariableRead a
        Binary *
            VariableRead b
            VariableRead c
```

Statement reconstruction, function-call reconstruction, and PHP source generation are not part of this pass.

## Commands

`bin/rephper` is a Symfony Console application. It loads Composer’s autoload and does not load the Zend extension. With no command it prints the command list and exits 0.

```bash
php8.4 bin/rephper
php8.4 bin/rephper graph out.json
php8.4 bin/rephper graph out.json --op-array=calculate
php8.4 bin/rephper graph out.json --op-array=main
php8.4 bin/rephper help graph
```

`graph` reads one IR JSON file and prints one block list per op array. `--op-array` limits the output. `main` selects the script body, whose JSON name is null. Validation errors go to stderr and the process exits 1. That includes a missing file, invalid JSON, an IR version other than 1, a jump the builder cannot accept, an unsupported opcode, and an unknown op-array name. A missing `file` argument also exits 1.

There is no `inspect` or `export` command. Inspection and export still go through `php -l` and `REPHPER_IR`.

## Tests

```bash
composer install
make test
make test-unit
make test-integration
```

`composer test`, `composer test:unit`, and `composer test:integration` run the same PHPUnit suites. `make test` and `make test-integration` build the extension first and pass it to PHPUnit through `REPHPER_PHP` and `REPHPER_EXTENSION`. The unit suite does not load the extension. Run PHPUnit with the normal PHP extensions enabled. The spawned `php -l` processes still use `-n`, so OPcache stays out of the opcode dump.

`tests/Unit/` covers the reader, control-flow edges, exception edges, expression trees, invalid IR, and the `graph` command through Symfony’s `CommandTester`. One unit test also runs `bin/rephper` so the executable bootstrap is checked. Version selection still treats `8.10` as newer than `8.9`, and PHP 8.4 remains the only supported compiler.

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

`.github/workflows/build.yml` runs on pushes and pull requests. The operating system and the PHP version are separate matrix axes. The active matrix is Ubuntu 24.04 and PHP 8.4. The job installs that PHP version, its development tools, and Composer, installs the locked dependencies with `composer install`, then runs `make info`, `make test-unit`, `make test-integration`, and a separate check that the extension loads with no startup output. Unit tests run before the extension is built. The integration target builds it and runs the exporter suite.

## Current limitations

- PHP 8.4 only.
- Ubuntu is the only operating system that has been targeted. It has been built and tested locally on Linux with PHP 8.4.27.
- There is no statement reconstructor and no PHP source generator. Expression reconstruction covers values, arithmetic, comparisons, concatenation, boolean not, bitwise not, assignment, compound assignment for `+ - * / % .`, and return values inside one basic block.
- CFG semantics are implemented for PHP 8.4, `pre_pass_two`, and absolute opline jumps only. `src/Cfg/Php84/Semantics.php` is the only opcode switch. Another PHP version needs its own semantics class.
- `HANDLE_EXCEPTION`, `USER_OPCODE`, and `CALL_TRAMPOLINE` are rejected. The builder does not guess their successors.
- `BIND_INIT_STATIC_OR_JMP` and `JMP_FRAMELESS` follow the PHP 8.4 handlers. The current fixtures do not emit them; the CFG tests cover them by editing a real instruction.
- Exception edges are one per block, from `try_catch` ranges. The IR does not name the exception class a `THROW` can reach, so the edge is the VM's handler boundary, not a typed catch match.
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

- A PHP decompiler that reads the IR and the control-flow graph and does not link against the Zend extension.
- More PHP versions, each with its own compatibility directory and expected dumps.
- More operating systems, as extra `os` matrix entries once the build has been verified there.
- Inspecting a directory of source files without executing the application.
