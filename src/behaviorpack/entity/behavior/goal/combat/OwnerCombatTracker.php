<?php

declare(strict_types=1);

namespace behaviorpack\entity\behavior\goal\combat;

use pocketmine\event\entity\EntityDamageByEntityEvent;
use pocketmine\event\entity\EntityDespawnEvent;
use pocketmine\event\EventPriority;
use pocketmine\Server;

/**
 * Remembers, for every entity (players included), the last entity it hit
 * and the last entity that hit it, with the server tick of the hit.
 */
final class OwnerCombatTracker{

	/** @var array<int, array{int, int}> */
	private static array $lastAttacked = [];

	/** @var array<int, array{int, int}> */
	private static array $lastAttacker = [];

	private static bool $registered = false;

	public static function register(Server $server) : void{
		if(self::$registered){
			return;
		}
		self::$registered = true;
		$manager = $server->getPluginManager();
		$manager->registerNativeEvent(EntityDamageByEntityEvent::class, function(EntityDamageByEntityEvent $event) : void{
			$damager = $event->getDamager();
			if($damager === null){
				return;
			}
			$victim = $event->getEntity();
			$tick = $victim->getWorld()->getServer()->getTick();
			self::$lastAttacked[$damager->getId()] = [$victim->getId(), $tick];
			self::$lastAttacker[$victim->getId()] = [$damager->getId(), $tick];
		}, EventPriority::MONITOR, $server, false);
		$manager->registerNativeEvent(EntityDespawnEvent::class, function(EntityDespawnEvent $event) : void{
			$id = $event->getEntity()->getId();
			unset(self::$lastAttacked[$id], self::$lastAttacker[$id]);
		}, EventPriority::MONITOR);
	}

	/**
	 * @return array{int, int}|null entity id and tick of the last entity hit
	 */
	public static function getLastAttacked(int $entityId) : ?array{
		return self::$lastAttacked[$entityId] ?? null;
	}

	/**
	 * @return array{int, int}|null entity id and tick of the last attacker
	 */
	public static function getLastAttacker(int $entityId) : ?array{
		return self::$lastAttacker[$entityId] ?? null;
	}
}
