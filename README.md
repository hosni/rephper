# Rephper

Rephper inspects PHP source after the Zend Engine has compiled it. The current milestone is a PHP 8.4 Zend extension that prints compiled op arrays and writes them as versioned JSON. A later milestone will reconstruct readable PHP from that JSON. The decompiler is not part of this tree.

## Objectives

1. Accept PHP source.
2. Compile it with the Zend Engine.
3. Read the compiled opcodes and their metadata from Zend structures.
4. Serialize that representation as IR JSON.
5. Reconstruct readable PHP from the serialized form. Not implemented yet.

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
         future PHP decompiler
```

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
- A C compiler, make, and autoconf

On Ubuntu:

```bash
sudo apt-get install php8.4-cli php8.4-dev build-essential autoconf pkg-config
```

## Build

From the repository root:

```bash
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
| `make test` | Builds, then runs `tests/run.php` with the selected PHP binary. |
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

An instruction has `opcode`, `opcode_number`, `line`, `op1`, `op2`, `result`, and `extended_value`. Operand `kind` is one of `unused`, `this`, `const`, `tmp`, `var`, `cv`, `jump`, `try_catch`, `level`, `label`, or `unknown`. A `jump` operand's `target` is an opline index. `tmp` and `var` indexes are still the compiler's raw numbers. `cv` indexes match `variables`. For `$this->prop`, op1 is `this` because the compiler leaves that znode number uninitialized. `BRK` and `CONT` keep a `level`. `GOTO` keeps an unresolved `label` id; the `labels` array maps names to oplines. `FE_FETCH_*`, `SWITCH_*`, and `MATCH` also set `jump_target` from `extended_value`. A switch or match literal used as the jump table is marked `jump_table: true`, and the integers inside that array are opline targets.

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

## Tests

```bash
make test
```

`tests/run.php`:

1. Checks semantic version selection. `8.10` is newer than `8.9`.
2. Refuses a PHP version that is not in the supported list. That list is currently `8.4`.
3. Loads the extension and requires a clean startup, with `Rephper` listed by `php -m`.
4. Runs every fixture compatible with the active PHP version through `php -l`.
5. Requires a successful compile, a dump header, and opcode lines.
6. Compares the dump with `tests/Expected/PHP/<version>/*.txt`.
7. Writes IR JSON for the same compile, checks that it is valid JSON, checks operand and literal types, and checks feature-specific values.
8. Compiles the fixture a second time and requires the same JSON.
9. Compares the normalized JSON with `tests/Expected/PHP/<version>/*.json`.
10. Checks that `REPHPER_IR` pointed at a directory does not remove that directory.

`Fixtures/Common/` is syntax every supported PHP version can compile. `Fixtures/PHP/X.Y/` is syntax introduced in that version. The runner selects every such directory whose version is less than or equal to the active PHP. PHP 8.4 therefore runs `Common/`, `PHP/8.0/`, `PHP/8.1/`, `PHP/8.2/`, and `PHP/8.4/`. The engine implementation is still only 8.4, so the expected opcodes and IR are stored under `Expected/PHP/8.4/`.

Expected dumps are per PHP version. The same source is not assumed to produce the same opcodes on another version.

Regenerate the 8.4 text dumps and IR files after an intentional compiler-output change:

```bash
make build
REPHPER_PHP=php8.4 \
REPHPER_EXTENSION=$PWD/extension/modules/rephper.so \
REPHPER_UPDATE_EXPECTED=1 \
php8.4 -n tests/run.php
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

`.github/workflows/build.yml` runs on pushes and pull requests. The operating system and the PHP version are separate matrix axes. The active matrix is Ubuntu 24.04 and PHP 8.4. The job installs that PHP version and its development tools, then runs `make info`, `make build`, `make test`, and a separate check that the extension loads with no startup output.

## Current limitations

- PHP 8.4 only.
- Ubuntu is the only operating system that has been targeted. It has been built and tested locally on Linux with PHP 8.4.27.
- There is no decompiler, control-flow graph, or source generator. The IR keeps jumps, break/continue rows, try/catch rows, and labels so a later pass can reconstruct that syntax.
- OPcache must be off for a stable dump and a stable IR file.
- Included and required files are not compiled with the file that names them.
- The extension has no directory walker. One process writes one IR file for the compilations in that request. The test runner passes one file at a time.
- Jump targets are absolute opline numbers from before `pass_two()`, not the runtime offsets.
- `BRK`, `CONT`, and `GOTO` are still the compile-time pseudo-opcodes. `GOTO` targets are in `labels`, not in the `GOTO` operand.
- `arg_info.default_value` is uninitialized at this hook. A default is `default_literal`, an index into `literals` taken from `RECV_INIT`.
- Class rows are captured when the main script reaches `pass_two()`. Properties and constants declared on another class are not copied in. A class may not be marked `linked` yet. Enum cases can still be `CONST_ENUM_INIT` AST nodes.
- Expected IR files were produced with a non-ZTS, non-debug PHP 8.4. `php.zts` and `php.debug` record the compiler that produced the file.
- The exporter keeps its document in process-global memory. That matches the CLI lint process used here. ZTS was not tested.

## Roadmap

- A PHP decompiler that reads IR JSON and does not link against the Zend extension.
- More PHP versions, each with its own compatibility directory and expected dumps.
- More operating systems, as extra `os` matrix entries once the build has been verified there.
- Inspecting a directory of source files without executing the application.
