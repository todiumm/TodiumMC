<?php

declare(strict_types=1);

namespace behaviorpack\entity;

use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\SpawnEgg;
use pocketmine\math\Vector3;
use pocketmine\world\World;

/**
 * Spawn egg of a custom behavior pack entity.
 */
final class CustomSpawnEgg extends SpawnEgg{

	/**
	 * @phpstan-param class-string<BehaviorEntity> $entityClass
	 */
	public function __construct(
		ItemIdentifier $identifier,
		string $name,
		?string $entityClass = null
	){
		if($entityClass === null){
			$prototype = \pocketmine\custom\NativeCustomItemRegistry::consume(static::class);
			if(!$prototype instanceof self){
				throw new \UnexpectedValueException("Invalid native custom spawn egg prototype");
			}
			$entityClass = $prototype->entityClass;
		}
		$this->entityClass = $entityClass;
		parent::__construct($identifier, $name);
	}

	protected function createEntity(World $world, Vector3 $pos, float $yaw, float $pitch) : Entity{
		$class = $this->entityClass;
		return new $class(Location::fromObject($pos, $world, $yaw, $pitch));
	}
}
