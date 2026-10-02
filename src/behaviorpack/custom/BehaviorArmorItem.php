<?php

declare(strict_types=1);

namespace behaviorpack\custom;

use behaviorpack\custom\item\CombatItem;
use behaviorpack\custom\item\RepairableTrait;
use pocketmine\custom\item\ItemComponents;
use pocketmine\item\Armor;
use pocketmine\item\ArmorTypeInfo;
use pocketmine\item\ItemIdentifier;

/**
 * A behavior pack item with minecraft:wearable in an armor slot.
 *
 * @phpstan-import-type ItemDefinition from BehaviorItemTrait
 */
final class BehaviorArmorItem extends Armor implements ItemComponents, CombatItem{
	use BehaviorItemTrait;
	use RepairableTrait;

	/**
	 * @phpstan-param ItemDefinition $definition
	 */
	public function __construct(ItemIdentifier $identifier, array|string $definition){
		$components = [];
		if(is_string($definition)){
			$prototype = \pocketmine\custom\NativeCustomItemRegistry::consume(static::class);
			if(!$prototype instanceof self){
				throw new \UnexpectedValueException("Invalid native custom item prototype for " . static::class);
			}
			$definition = $prototype->definition;
			$components = $prototype->getComponents();
		}
		$this->definition = $definition;
		parent::__construct($identifier, $definition["name"], new ArmorTypeInfo(
			$definition["protection"],
			$definition["durability"],
			$definition["armorSlot"] ?? 0
		));
		foreach($components as $component){
			$this->addComponent($component);
		}
		if($definition["durability"] <= 0){
			$this->setUnbreakable();
		}
	}
}
