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
 * @author Altay Team
 * @link https://github.com/altayofficial
 */

declare(strict_types=1);

namespace pocketmine\data\bedrock\block\upgrade;

use pocketmine\data\bedrock\block\BlockStateNames;
use pocketmine\data\bedrock\block\BlockStateStringValues;
use pocketmine\data\bedrock\block\BlockTypeNames;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\utils\Utils;
use ReflectionClass;
use function is_string;
use function str_ends_with;

final class HorizontalConnectionUpgradeSchema{

	public const SCHEMA_ID = 10000;

	private function __construct(){
	}

	public static function create() : BlockStateUpgradeSchema{
		$schema = new BlockStateUpgradeSchema(1, 26, 50, 0, self::SCHEMA_ID);
		foreach(Utils::stringifyKeys((new ReflectionClass(BlockTypeNames::class))->getConstants()) as $name => $id){
			if(!is_string($id)){
				continue;
			}
			if(str_ends_with($name, "_FENCE_GATE")){
				continue;
			}
			if(
				str_ends_with($name, "_FENCE")
				|| str_ends_with($name, "_PANE")
				|| str_ends_with($name, "_BARS")
				|| $id === BlockTypeNames::TRIP_WIRE
			){
				$schema->addedProperties[$id][BlockStateNames::MC_CONNECTION_NORTH] = new ByteTag(0);
				$schema->addedProperties[$id][BlockStateNames::MC_CONNECTION_SOUTH] = new ByteTag(0);
				$schema->addedProperties[$id][BlockStateNames::MC_CONNECTION_WEST] = new ByteTag(0);
				$schema->addedProperties[$id][BlockStateNames::MC_CONNECTION_EAST] = new ByteTag(0);
			}
			if(str_ends_with($name, "_STAIRS")){
				$schema->addedProperties[$id][BlockStateNames::MC_CORNER] = new StringTag(BlockStateStringValues::MC_CORNER_NONE);
			}
		}
		return $schema;
	}
}
