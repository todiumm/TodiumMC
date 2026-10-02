<?php

declare(strict_types=1);

namespace pocketmine\custom;

use Closure;
use pocketmine\entity\Entity;
use pocketmine\entity\EntityDataHelper;
use pocketmine\entity\EntityFactory;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\utils\SingletonTrait;
use pocketmine\world\World;
use ReflectionClass;

/**
 * Registers custom entities and announces them to clients through AvailableActorIdentifiersPacket.
 */
final class CustomEntityFactory{
	use SingletonTrait;

	/**
	 * @phpstan-param class-string<Entity> $className
	 * @phpstan-param (Closure(World, CompoundTag) : Entity)|null $creationFunc
	 */
	public function registerEntity(string $className, string $identifier, ?Closure $creationFunc = null, string $behaviourId = "") : void{
		EntityFactory::getInstance()->register(
			$className,
			$creationFunc ?? static fn(World $world, CompoundTag $nbt) : Entity => new $className(EntityDataHelper::parseLocation($nbt, $world), $nbt),
			[$identifier]
		);

		$cache = StaticPacketCache::getInstance();
		$property = (new ReflectionClass($cache))->getProperty("availableActorIdentifiers");
		$packet = $property->getValue($cache);
		$root = $packet->identifiers->getRoot();
		$list = $root->getListTag("idlist") ?? new ListTag();
		$list->push(CompoundTag::create()->setString("id", $identifier)->setString("bid", $behaviourId));
		$root->setTag("idlist", $list);
		$packet->identifiers = new CacheableNbt($root);
	}
}
