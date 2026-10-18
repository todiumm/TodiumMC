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

final class BehaviorManifestMetadata{
	/** @var string[]|null */
	public ?array $authors = null;

	public ?string $license = null;

	public ?string $url = null;

	public ?string $generated_with = null;

	/** Declared as "addon" by packs that ship a behaviour/data module; drives the client-side addon flag. */
	public ?string $product_type = null;
}
