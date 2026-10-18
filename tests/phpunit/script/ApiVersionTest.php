<?php

/*
 * Todium - native behaviour pack support for Altay.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace pocketmine\script;

use PHPUnit\Framework\TestCase;

/**
 * Covers how a pack's requested `@minecraft/server` version is parsed and normalised.
 */
final class ApiVersionTest extends TestCase{

	public function testParsesDottedVersion() : void{
		$version = ApiVersion::parse("1.11.0");

		self::assertSame(1, $version->major);
		self::assertSame(11, $version->minor);
		self::assertSame(0, $version->patch);
		self::assertFalse($version->isBeta());
	}

	public function testParsesArrayForm() : void{
		$version = ApiVersion::parse([1, 12, 3]);

		self::assertSame("1.12.3", $version->toString());
	}

	public function testParsesBetaChannel() : void{
		self::assertTrue(ApiVersion::parse("1.13.0-beta")->isBeta());
		self::assertTrue(ApiVersion::parse([1, 13, 0, "beta"])->isBeta());
	}

	public function testBetaKeywordMeansNewestSupported() : void{
		$version = ApiVersion::parse("beta");

		self::assertTrue($version->isBeta());
		self::assertSame(ApiVersion::MAX_SUPPORTED . "-beta", $version->toString());
	}

	public function testUndeclaredVersionKeepsNewestSurface() : void{
		self::assertSame(ApiVersion::MAX_SUPPORTED, ApiVersion::parse(null)->toString());
		self::assertSame(ApiVersion::MAX_SUPPORTED, ApiVersion::parse("")->toString());
		self::assertSame(ApiVersion::MAX_SUPPORTED, ApiVersion::parse([])->toString());
	}

	public function testComparesVersionsNumericallyNotLexically() : void{
		self::assertTrue(ApiVersion::parse("1.9.0")->isAtLeast("1.10.0") === false, "1.9.0 predates 1.10.0");
		self::assertTrue(ApiVersion::parse("1.10.0")->isAtLeast("1.9.0"));
		self::assertTrue(ApiVersion::parse("1.12.0")->isAtLeast("1.12.0"));
		self::assertTrue(ApiVersion::parse("2.0.0")->isAtLeast("1.15.0"));
	}

	public function testDetectsVersionsNewerThanSupported() : void{
		self::assertFalse(ApiVersion::parse(ApiVersion::MAX_SUPPORTED)->isNewerThanSupported());
		self::assertTrue(ApiVersion::parse("2.0.0")->isNewerThanSupported());
		self::assertTrue(ApiVersion::parse("1.16.0")->isNewerThanSupported());
	}

	public function testPartialVersionsDefaultMissingComponentsToZero() : void{
		self::assertSame("1.11.0", ApiVersion::parse("1.11")->toString());
		self::assertSame("1.0.0", ApiVersion::parse("1")->toString());
	}

	public function testMalformedComponentsDoNotThrow() : void{
		self::assertSame("1.0.0", ApiVersion::parse("1.x.y")->toString());
	}
}
