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

use function ctype_digit;
use function explode;
use function implode;
use function is_array;
use function is_int;
use function is_string;
use function str_contains;
use function str_starts_with;
use function trim;

/**
 * A `@minecraft/server` API version, as requested by a behaviour pack.
 *
 * Bedrock lets a pack ask for a module version in three shapes: a version string ("1.11.0"), a version array
 * ([1, 11, 0]) or the literal "beta" for the newest pre-release. All three normalise to the same comparable value
 * here, so the rest of the runtime only ever deals with integers.
 */
final class ApiVersion{

	/** The newest stable API surface this runtime implements. */
	public const MAX_SUPPORTED = "1.15.0";

	private function __construct(
		public readonly int $major,
		public readonly int $minor,
		public readonly int $patch,
		public readonly bool $beta
	){}

	/**
	 * @param string|array<int|string, mixed>|int|null $version a manifest `version` value: dotted string, array form, or the
	 *                                               numeric major alone
	 */
	public static function parse(string|array|int|null $version) : self{
		if($version === null){
			//a pack that declares no version is treated as targeting the newest surface, which is what it sees anyway
			return self::newestSupported();
		}
		if(is_int($version)){
			return new self($version, 0, 0, false);
		}
		if(is_array($version)){
			$parts = [];
			foreach($version as $part){
				if(is_int($part)){
					$parts[] = $part;
				}elseif(is_string($part) && ctype_digit(trim($part))){
					$parts[] = (int) trim($part);
				}else{
					//a string component such as "beta" ("1.0.0-beta") only carries the channel
					break;
				}
			}
			return $parts === [] ? self::newestSupported() : self::fromParts($parts, self::isBetaArray($version));
		}
		return self::fromString($version);
	}

	public static function fromString(string $version) : self{
		$version = trim($version);
		if($version === ""){
			return self::newestSupported();
		}
		if($version === "beta"){
			//"beta" means "the newest beta this runtime knows about"
			return self::parse(self::MAX_SUPPORTED . "-beta");
		}

		$beta = false;
		if(str_contains($version, "-")){
			[$version, $channel] = explode("-", $version, 2);
			$beta = str_starts_with($channel, "beta");
		}

		$parts = [];
		foreach(explode(".", $version, 3) as $part){
			$part = trim($part);
			$parts[] = ctype_digit($part) ? (int) $part : 0;
		}
		return self::fromParts($parts, $beta);
	}

	/**
	 * @param int[] $parts
	 */
	private static function fromParts(array $parts, bool $beta) : self{
		return new self($parts[0] ?? 0, $parts[1] ?? 0, $parts[2] ?? 0, $beta);
	}

	/**
	 * @param array<int|string, mixed> $version
	 */
	private static function isBetaArray(array $version) : bool{
		foreach($version as $part){
			if(is_string($part) && str_contains($part, "beta")){
				return true;
			}
		}
		return false;
	}

	/**
	 * Returns whether this version is at least the given one. A beta build satisfies the stable version it
	 * precedes, matching how Bedrock treats a pack that asks for "1.11.0" while running a newer beta.
	 */
	public function isAtLeast(string $version) : bool{
		$other = self::fromString($version);
		return $this->compareTo($other) >= 0;
	}

	public function compareTo(self $other) : int{
		foreach(["major", "minor", "patch"] as $field){
			if($this->{$field} !== $other->{$field}){
				return $this->{$field} <=> $other->{$field};
			}
		}
		return 0;
	}

	public function isBeta() : bool{
		return $this->beta;
	}

	/**
	 * The highest API version this runtime can serve. A pack asking for anything newer is clamped here, so it
	 * keeps working against the newest surface instead of failing to load.
	 */
	public static function newestSupported() : self{
		return self::fromString(self::MAX_SUPPORTED);
	}

	public function isNewerThanSupported() : bool{
		return $this->isNewerThan(self::newestSupported());
	}

	/**
	 * Returns whether this version is strictly newer than the given one.
	 */
	public function isNewerThan(self $other) : bool{
		return $this->compareTo($other) > 0;
	}

	public function toString() : string{
		return implode(".", [$this->major, $this->minor, $this->patch]) . ($this->beta ? "-beta" : "");
	}
}
