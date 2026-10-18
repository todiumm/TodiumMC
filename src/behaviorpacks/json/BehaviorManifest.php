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

/**
* Model for JsonMapper to represent a Bedrock behaviour pack's manifest.json.
*
* Unlike a resource pack manifest, a behaviour pack may contain several modules (data, script, resources), and the
* script modules are what the native Script API executes.
*/
final class BehaviorManifest{

	/** @required */
	public int $format_version;

	/** @required */
	public BehaviorManifestHeader $header;

	/**
	 * @var BehaviorManifestModule[]
	 * @required
	 */
	public array $modules;

	public ?BehaviorManifestMetadata $metadata = null;

	/** @var BehaviorManifestDependency[] */
	public ?array $dependencies = null;

	/** @var string[] */
	public ?array $capabilities = null;
}
