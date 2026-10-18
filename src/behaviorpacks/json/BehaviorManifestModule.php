<?php

/*
*
*      _    _ _
*     / \  | | |_ __ _ _   _
*    / _ \ | | __/ _` | | | |
*   / ___ \| | || (_| | |_| |
*  /_/   \_\_|\__\__,_|\__, |
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

namespace pocketmine\behaviorpacks\json;

use pocketmine\behaviorpacks\BehaviorPack;

final class BehaviorManifestModule{

	public string $description = "";

	/** @required */
	public string $type;

	/** @required */
	public string $uuid;

	/**
	 * @var int[]
	 * @phpstan-var array{int, int, int}
	 * @required
	 */
	public array $version;

	/**
	 * Present on script modules; the language the entry point is written in. Only "javascript" is supported.
	 */
	public ?string $language = null;

	/**
	 * Present on script modules; path of the entry point inside the pack.
	 */
	public ?string $entry = null;

	public function isScript() : bool{
		return $this->type === BehaviorPack::MODULE_TYPE_SCRIPT;
	}

	/**
	 * Returns the script entry point of this module, or null if it does not declare one.
	 */
	public function getScriptEntry() : ?string{
		return $this->isScript() && $this->entry !== null && $this->entry !== "" ? $this->entry : null;
	}
}
