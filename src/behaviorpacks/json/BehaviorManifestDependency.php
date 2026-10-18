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

final class BehaviorManifestDependency{

	/**
	 * Present when the dependency is another pack, identified by its UUID. Absent for module dependencies.
	 */
	public ?string $uuid = null;

	/**
	 * Present when the dependency is a built-in module rather than a pack, e.g. "@minecraft/server".
	 */
	public ?string $module_name = null;

	/**
	 * Bedrock accepts both a version array ([1, 0, 0]) and a version string ("1.0.0"); string forms are normalised to
	 * arrays before mapping so that JsonMapper always sees the array form.
	 *
	 * @var int[]
	 */
	public array $version = [];
}
