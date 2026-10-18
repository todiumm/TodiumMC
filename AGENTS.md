# AGENTS.md

Notes for agents working on Todium, a fork of Altay (PocketMine-MP fork).

## Environment

* PHP is **not** installed by default. Install it before running anything:
  `sudo apt-get update -qq && sudo apt-get install -y php-cli php-mbstring php-xml`
  (user `openhands` has passwordless sudo).
* `zip` is **not** installed. Build archives with Python's `zipfile` instead.

## Testing

* Run the Script API tests with:
  `./vendor/bin/phpunit --bootstrap vendor/autoload.php tests/phpunit/script`
* Static analysis for the script code: `./vendor/bin/phpstan analyse --no-progress src/script tests/phpunit/script`
  → must stay at "[OK] No errors".
* The **full** PHPUnit suite and full-project PHPStan both report pre-existing failures caused by
  missing native dependencies (`vendor/pmmp` not installed, no `ZipArchive`/`leveldb`/`pthreads`
  extensions). These are unrelated to Script API work; scope test runs to `tests/phpunit/script`
  and `src/script`.

## Code style

* Indentation is **tabs** (see `.editorconfig`), with `indent_size = 4`. The `file_editor` tool
  writes spaces; convert new files to tabs before committing.
* PocketMine style: `function name() : type{`, braces on the same line, blank line before each
  function, `use function` imports for every global function called.

## Script API

* The runtime is pure PHP (`src/script/engine/`), no Node.js or external JS runtime.
* A behaviour pack's `@minecraft/server` version is detected automatically from the pack itself
  (`src/script/ApiVersionDetector.php`): the manifest dependency first, then a versioned import
  such as `"@minecraft/server@1.11.0"`. Nothing is registered by hand.
* The whole API surface is exposed to every pack whatever version it targets; the version is
  reported in the console and never used to withhold symbols.
