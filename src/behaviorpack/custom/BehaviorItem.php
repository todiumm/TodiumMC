<?php

declare(strict_types=1);

namespace behaviorpack\custom;

use behaviorpack\custom\item\CombatItem;
use pocketmine\custom\item\ItemComponents;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;

/**
 * A plain behavior pack item, reused for every identifier.
 *
 * @phpstan-import-type ItemDefinition from BehaviorItemTrait
 */
final class BehaviorItem extends Item implements ItemComponents, CombatItem{
	use BehaviorItemTrait;

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
		parent::__construct($identifier, $definition["name"]);
		foreach($components as $component){
			$this->addComponent($component);
		}
	}
}
