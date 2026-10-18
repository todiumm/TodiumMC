<?php

/*
 * Todium - native behaviour pack support for Altay.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace pocketmine\script;

use PHPUnit\Framework\TestCase;
use pocketmine\script\engine\ScriptEngine;
use function count;
use function is_scalar;

/**
 * Covers how the engine resolves the `@minecraft/server` module a pack imports, end to end.
 *
 * The pack id is the leading path segment of a source path, so these tests use a synthetic "pack" directory.
 */
final class ScriptEngineVersioningTest extends TestCase{

	private const PACK = "11111111-1111-4111-8111-111111111111";

	/** @var list<string> */
	private array $logLines = [];

	private ?ScriptEngine $engine = null;

	protected function setUp() : void{
		$this->logLines = [];
	}

	public function testBareImportResolves() : void{
		$this->runPack('import { world } from "@minecraft/server";' . "\n" . 'console.log("loaded:" + world.serverName);');

		self::assertSame("", $this->engineError());
		self::assertNotEmpty($this->logLines, "the pack should have run");
	}

	public function testVersionedImportSuffixResolves() : void{
		//a pack may write the version into the specifier; it must still resolve to the same module
		$this->runPack('import { world } from "@minecraft/server@1.11.0";' . "\n" . 'console.log("loaded:" + world.serverName);');

		self::assertSame("", $this->engineError());
		self::assertNotEmpty($this->logLines, "the pack should have run");
	}

	public function testLegacyServerSpecifierResolves() : void{
		$this->runPack('import { world } from "server";' . "\n" . 'console.log("loaded:" + world.serverName);');

		self::assertSame("", $this->engineError());
		self::assertNotEmpty($this->logLines, "the pack should have run");
	}

	public function testFullSurfaceIsAvailableWhateverThePackTargets() : void{
		//the version a pack targets never removes symbols, so a pack still finds newer members
		$this->runPack('import { world } from "@minecraft/server";' . "\n" . 'console.log(typeof world.getDay);');

		self::assertSame("function", $this->lastLog());
	}

	private function runPack(string $source) : void{
		$this->engine = new ScriptEngine(
			function(string $name, array $arguments) : mixed{
				if($name === "system.log"){
					$message = $arguments[1] ?? "";
					$this->logLines[] = is_scalar($message) ? (string) $message : "";
				}
				return null;
			},
			"TodiumTest",
			"1.21.0"
		);

		$this->engine->start(
			[self::PACK . "/scripts/main.js" => $source],
			[self::PACK . "/scripts/main.js"]
		);
	}

	private function engineError() : string{
		return $this->engine?->getLastError() ?? "";
	}

	private function lastLog() : string{
		return count($this->logLines) === 0 ? "" : $this->logLines[count($this->logLines) - 1];
	}
}
