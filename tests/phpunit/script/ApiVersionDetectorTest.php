<?php

/*
 * Todium - native behaviour pack support for Altay.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace pocketmine\script;

use PHPUnit\Framework\TestCase;

/**
 * Covers the automatic detection of a pack's `@minecraft/server` version, from its manifest and its scripts.
 */
final class ApiVersionDetectorTest extends TestCase{

	public function testManifestDependencyIsDetected() : void{
		$manifest = [
			"dependencies" => [
				["module_name" => "@minecraft/server", "version" => "1.11.0"]
			]
		];

		self::assertSame("1.11.0", ApiVersionDetector::detect($manifest, [])?->toString());
	}

	public function testManifestArrayVersionIsDetected() : void{
		$manifest = [
			"dependencies" => [
				["module_name" => "@minecraft/server", "version" => [1, 12, 0]]
			]
		];

		self::assertSame("1.12.0", ApiVersionDetector::detect($manifest, [])?->toString());
	}

	public function testUnrelatedDependencyIsIgnored() : void{
		$manifest = [
			"dependencies" => [
				["module_name" => "@minecraft/server-ui", "version" => "1.2.0"]
			]
		];

		self::assertNull(ApiVersionDetector::detect($manifest, []));
	}

	public function testNoDependencyAndBareImportYieldsNothing() : void{
		self::assertNull(ApiVersionDetector::detect([], ['import { world } from "@minecraft/server";']));
	}

	public function testVersionedImportIsDetected() : void{
		$sources = ['import { world } from "@minecraft/server@1.11.0";'];

		self::assertSame("1.11.0", ApiVersionDetector::detect([], $sources)?->toString());
	}

	public function testManifestWinsOverImport() : void{
		$manifest = [
			"dependencies" => [
				["module_name" => "@minecraft/server", "version" => "1.4.0"]
			]
		];
		$sources = ['import { world } from "@minecraft/server@1.11.0";'];

		self::assertSame("1.4.0", ApiVersionDetector::detect($manifest, $sources)?->toString());
	}

	public function testHighestImportVersionWins() : void{
		$sources = [
			'import { world } from "@minecraft/server@1.4.0";',
			'import { system } from "@minecraft/server@1.11.0";'
		];

		self::assertSame("1.11.0", ApiVersionDetector::detect([], $sources)?->toString());
	}

	public function testDetectedVersionIsNeverNewerThanItSays() : void{
		$manifest = [
			"dependencies" => [
				["module_name" => "@minecraft/server", "version" => "2.0.0"]
			]
		];
		$version = ApiVersionDetector::detect($manifest, []);

		self::assertNotNull($version);
		self::assertTrue($version->isNewerThanSupported(), "2.0.0 is past what Todium implements");
	}
}
