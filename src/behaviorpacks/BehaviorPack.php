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

namespace pocketmine\behaviorpacks;

use pocketmine\resourcepacks\ResourcePack;

/**
 * A Bedrock behaviour pack.
 *
 * Behaviour packs are delivered to clients exactly like resource packs, but they additionally carry a data module
 * (entities, blocks, recipes, ...) and optionally a script module. The script module is not executed by the client on a
 * server; it is executed by Todium's native Script API runtime instead.
 */
interface BehaviorPack extends ResourcePack{

	/** Module type used for the pack's data module (entities, loot tables, functions, ...). */
	public const MODULE_TYPE_DATA = "data";
	/** Module type used for a Script API module. */
	public const MODULE_TYPE_SCRIPT = "script";
	/** Module type used when a pack only ships resources. */
	public const MODULE_TYPE_RESOURCES = "resources";

	/**
	 * Returns the pack's manifest.json as a decoded array, exactly as authored by the pack developer.
	 *
	 * @return mixed[]
	 */
	public function getManifestData() : array;

	/**
	 * Returns the module entries declared in the pack's manifest.
	 *
	 * @return \pocketmine\behaviorpacks\json\BehaviorManifestModule[]
	 */
	public function getModules() : array;

	/**
	 * Returns the relative paths (using forward slashes) of every script module entry point declared in the manifest.
	 *
	 * @return string[]
	 */
	public function getScriptEntries() : array;

	/**
	 * Returns whether this pack declares at least one script module.
	 */
	public function hasScripts() : bool;

	/**
	 * Returns whether this pack should be advertised to the client as an addon.
	 *
	 * A pack is an addon when it ships a data or script module, or when its metadata declares
	 * "product_type": "addon". Plain resource-only packs are not addons.
	 */
	public function isAddonPack() : bool;

	/**
	 * Reads a single file from inside the pack.
	 *
	 * @return string|null null if the file does not exist inside the pack
	 */
	public function readFile(string $relativePath) : ?string;

	/**
	 * Returns whether the given relative path exists inside the pack.
	 */
	public function fileExists(string $relativePath) : bool;

	/**
	 * Returns the relative paths (using forward slashes) of every file inside the pack.
	 *
	 * @return string[]
	 */
	public function listFiles() : array;

	/**
	 * Returns the pack's dependencies as declared in the manifest, as a list of [uuid, version] pairs.
	 *
	 * @return array<int, array{string, string}>
	 */
	public function getDependencies() : array;

	/**
	 * Returns the module types present in this pack.
	 *
	 * @return string[]
	 */
	public function getModuleTypes() : array;
}
