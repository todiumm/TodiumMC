<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\__\__,_|\__, |
 *                       |___/
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * Original work by the PocketMine Team.
 * https://www.pocketmine.net/
 *
 * @author Todium Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace pocketmine\script;

use function is_array;
use function is_int;
use function is_string;
use function preg_match_all;

/**
 * Works out which `@minecraft/server` API version a behaviour pack was written against, without the pack having to
 * declare it anywhere the server would have to be taught about in advance.
 *
 * Two signals are read, most authoritative first:
 *
 * 1. the `@minecraft/server` dependency in the pack's manifest, which is how Bedrock itself pins the version; and
 * 2. a versioned import inside the pack's own scripts, as in `import { world } from "@minecraft/server@1.11.0"`.
 *
 * When neither is present the pack simply has no declared version and the caller treats it as "newest". The result is
 * only reported, never used to withhold API symbols, so a miss can cost a log line but cannot break a pack.
 */
final class ApiVersionDetector{

	/** The module a pack imports to reach the Script API. */
	public const MODULE = "@minecraft/server";

	/**
	 * Matches the module specifier of an import, optionally carrying a version suffix. Captures the version, if any,
	 * so `"@minecraft/server@1.11.0"` yields `1.11.0` while a bare `"@minecraft/server"` yields nothing.
	 */
	private const SPECIFIER_PATTERN = '/@minecraft\/server(?:@(\d+(?:\.\d+){0,2}(?:-beta)?))?/';

	/**
	 * Detects the API version of a single pack from its manifest and the text of its scripts.
	 *
	 * @param mixed[]       $manifest the pack's decoded manifest.json
	 * @param list<string>  $sources  the text of every script the pack ships
	 */
	public static function detect(array $manifest, array $sources) : ?ApiVersion{
		return self::fromManifest($manifest) ?? self::fromSources($sources);
	}

	/**
	 * Reads the `@minecraft/server` entry from a manifest's dependencies. Returns null when the pack declares no
	 * such dependency, or declares one without a usable version.
	 *
	 * @param mixed[] $manifest
	 */
	public static function fromManifest(array $manifest) : ?ApiVersion{
		$dependencies = $manifest["dependencies"] ?? null;
		if(!is_array($dependencies)){
			return null;
		}
		foreach($dependencies as $dependency){
			if(!is_array($dependency) || ($dependency["module_name"] ?? null) !== self::MODULE){
				continue;
			}
			$version = $dependency["version"] ?? null;
			if(is_string($version) || is_array($version) || is_int($version)){
				return ApiVersion::parse($version);
			}
			return null;
		}
		return null;
	}

	/**
	 * Scans script text for a versioned `@minecraft/server` import, e.g. `"@minecraft/server@1.11.0"`. A bare import
	 * carries no version and is ignored. When several scripts disagree, the highest version wins, so a pack that
	 * uses a newer import in one file is not held back by an older one elsewhere.
	 *
	 * @param list<string> $sources
	 */
	public static function fromSources(array $sources) : ?ApiVersion{
		$found = null;
		foreach($sources as $source){
			if(preg_match_all(self::SPECIFIER_PATTERN, $source, $matches) === false){
				continue;
			}
			foreach($matches[1] as $captured){
				if($captured === ""){
					continue;
				}
				$version = ApiVersion::parse($captured);
				if($found === null || $version->isNewerThan($found)){
					$found = $version;
				}
			}
		}
		return $found;
	}
}
