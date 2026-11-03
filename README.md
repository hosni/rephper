# Rephper

Rephper inspects PHP source after the Zend Engine has compiled it. The current milestone is a PHP 8.4 Zend extension that prints compiled op arrays. Later milestones will serialize that representation and reconstruct readable PHP.

## Objectives

1. Accept PHP source.
2. Compile it with the Zend Engine.
3. Read the compiled opcodes and their metadata from Zend structures.
4. Serialize that representation. Not implemented yet.
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
extension/src/Compatibility/php84/inspect.c
    |
    v
human-readable dump on stderr
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

Version-specific C code lives in `extension/src/Compatibility/php84/`. `extension/src/Common/` is reserved for code that is genuinely shared by more than one PHP version. It is not present yet. There is nothing shared to put there, and an empty directory would not be a useful abstraction.

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
6. Compares the dump with `tests/Expected/PHP/<version>/`.

`Fixtures/Common/` is syntax every supported PHP version can compile. `Fixtures/PHP/X.Y/` is syntax introduced in that version. A fixture runs on X.Y and every later supported version. PHP 8.4 currently runs `Common/` and `PHP/8.4/` only, because earlier versions are not implemented and their fixture directories are not created empty.

Expected dumps are per PHP version. The same source is not assumed to produce the same opcodes on another version.

Regenerate the 8.4 dumps after an intentional compiler-output change:

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
- The dump is text on stderr. There is no JSON serializer and no decompiler.
- OPcache must be off for a stable dump.
- Included and required files are not compiled with the file that names them.
- The extension has no directory walker. The test runner passes one file at a time.
- Jump targets are absolute opline numbers from before `pass_two()`, not the runtime offsets.
- `BRK`, `CONT`, and `GOTO` are still the compile-time pseudo-opcodes.

## Roadmap

- A stable serialized form of the op array.
- A PHP decompiler that reads that form.
- More PHP versions, each with its own compatibility directory and expected dumps.
- More operating systems, as extra `os` matrix entries once the build has been verified there.
- Inspecting a directory of source files without executing the application.
