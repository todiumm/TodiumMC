<?php

declare(strict_types=1);

namespace pocketmine\custom;

use Closure;
use InvalidArgumentException;
use pocketmine\custom\item\CreativeInventoryInfo;
use pocketmine\custom\item\ItemComponents;
use pocketmine\custom\util\NBT;
use pocketmine\data\bedrock\item\BlockItemIdMap;
use pocketmine\data\bedrock\item\SavedItemData;
use pocketmine\inventory\CreativeCategory;
use pocketmine\inventory\CreativeGroup;
use pocketmine\inventory\CreativeInventory;
use pocketmine\item\Item;
use pocketmine\item\ItemIdentifier;
use pocketmine\item\ItemTypeIds;
use pocketmine\item\StringToItemParser;
use pocketmine\lang\Translatable;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\network\mcpe\protocol\types\ItemTypeEntry;
use pocketmine\block\Block;
use pocketmine\world\format\io\GlobalItemDataHandlers;
use ReflectionClass;
use RuntimeException;

/**
 * Native registry for custom (data driven) items of protocol 1.26.50.
 *
 * Registering an item allocates its type ID, wires it into the item (de)serializers and the string parser, adds it
 * to the creative inventory and appends it to the ItemRegistryPacket the clients receive.
 */
final class CustomItemFactory{
	/** @var array<string, ItemTypeEntry> */
	private static array $entries = [];
	/** @var array<string, CreativeGroup> */
	private static array $groups = [];

	private function __construct(){}

	/**
	 * @phpstan-param Closure(ItemIdentifier) : Item $itemFunc
	 */
	public static function register(string $identifier, Closure $itemFunc, ?CreativeInventoryInfo $creativeInfo = null) : Item{
		if(isset(self::$entries[$identifier])){
			throw new InvalidArgumentException("Custom item $identifier is already registered");
		}
		$item = $itemFunc(new ItemIdentifier(ItemTypeIds::newId()));
		$itemId = $item->getTypeId();

		GlobalItemDataHandlers::getDeserializer()->map($identifier, fn() => clone $item);
		GlobalItemDataHandlers::getSerializer()->map($item, fn() => new SavedItemData($identifier));
		StringToItemParser::getInstance()->register($identifier, fn() => clone $item);

		$componentBased = $item instanceof ItemComponents;
		$entry = new ItemTypeEntry($identifier, $itemId, $componentBased, $componentBased ? 1 : 0, new CacheableNbt(self::createItemNbt($item, $identifier, $itemId, $creativeInfo)));
		self::$entries[$identifier] = $entry;
		self::addToDictionary($entry);

		if($creativeInfo !== null){
			self::addToCreativeInventory($item, $creativeInfo);
		}
		return $item;
	}

	public static function get(string $identifier, int $amount = 1) : Item{
		$item = StringToItemParser::getInstance()->parse($identifier) ?? throw new InvalidArgumentException("Custom item $identifier is not registered");
		return $item->setCount($amount);
	}

	/**
	 * Registers the item form of a custom block. Its item ID matches the block type ID.
	 */
	public static function registerBlockItem(string $identifier, Block $block) : void{
		$itemId = $block->getIdInfo()->getBlockTypeId();
		StringToItemParser::getInstance()->registerBlock($identifier, fn() => clone $block);
		self::addToDictionary(new ItemTypeEntry($identifier, $itemId, false, 0, new CacheableNbt(CompoundTag::create())));

		$map = BlockItemIdMap::getInstance();
		$property = (new ReflectionClass($map))->getProperty("itemToBlockId");
		$property->setValue($map, $property->getValue($map) + [$identifier => $identifier]);
		$property = (new ReflectionClass($map))->getProperty("blockToItemId");
		$property->setValue($map, $property->getValue($map) + [$identifier => $identifier]);
	}

	public static function isRegistered(string $identifier) : bool{
		return isset(self::$entries[$identifier]);
	}

	/**
	 * @return string[]
	 */
	public static function getRegisteredIdentifiers() : array{
		return array_keys(self::$entries);
	}

	/**
	 * @return string[]
	 * @deprecated use getRegisteredIdentifiers()
	 */
	public static function getIdentifiers() : array{
		return self::getRegisteredIdentifiers();
	}

	/**
	 * Adds the entry to the dictionary used for ItemRegistryPacket and for network ID translation.
	 */
	private static function addToDictionary(ItemTypeEntry $entry) : void{
		$dictionary = TypeConverter::getInstance()->getItemTypeDictionary();
		$reflection = new ReflectionClass($dictionary);

		$intToString = $reflection->getProperty("intToStringIdMap");
		$intToString->setValue($dictionary, $intToString->getValue($dictionary) + [$entry->getNumericId() => $entry->getStringId()]);

		$stringToInt = $reflection->getProperty("stringToIntMap");
		$stringToInt->setValue($dictionary, $stringToInt->getValue($dictionary) + [$entry->getStringId() => $entry->getNumericId()]);

		$itemTypes = $reflection->getProperty("itemTypes");
		$types = $itemTypes->getValue($dictionary);
		$types[] = $entry;
		$itemTypes->setValue($dictionary, $types);
	}

	private static function createItemNbt(Item $item, string $identifier, int $itemId, ?CreativeInventoryInfo $creativeInfo) : CompoundTag{
		if(!$item instanceof ItemComponents){
			return CompoundTag::create();
		}
		$components = CompoundTag::create();
		$properties = CompoundTag::create();
		foreach($item->getComponents() as $component){
			$tag = NBT::getTagType($component->getValue()) ?? throw new RuntimeException("Failed to get tag type for component " . $component->getName());
			if($component->isProperty()){
				$properties->setTag($component->getName(), $tag);
			}else{
				$components->setTag($component->getName(), $tag);
			}
		}
		if($creativeInfo !== null){
			$properties->setTag("creative_category", NBT::getTagType($creativeInfo->getNumericCategory()));
			$properties->setTag("creative_group", NBT::getTagType($creativeInfo->getGroup()));
		}
		$components->setTag("item_properties", $properties);
		return CompoundTag::create()
			->setTag("components", $components)
			->setInt("id", $itemId)
			->setString("name", $identifier);
	}

	/**
	 * @internal shared with CustomBlockFactory
	 */
	public static function addToCreativeInventory(Item $item, CreativeInventoryInfo $info) : void{
		if($info->getCategory() === CreativeInventoryInfo::CATEGORY_ALL || $info->getCategory() === CreativeInventoryInfo::CATEGORY_COMMANDS){
			return;
		}
		if(self::$groups === []){
			foreach(CreativeInventory::getInstance()->getAllEntries() as $entry){
				$group = $entry->getGroup();
				if($group !== null){
					self::$groups[$group->getName()->getText()] = $group;
				}
			}
		}
		$groupName = $info->getGroup();
		$group = self::$groups[$groupName] ?? ($groupName !== "" && $groupName !== CreativeInventoryInfo::NONE ? new CreativeGroup(new Translatable($groupName), $item) : null);
		if($group !== null){
			self::$groups[$group->getName()->getText()] = $group;
		}
		$category = match($info->getCategory()){
			CreativeInventoryInfo::CATEGORY_CONSTRUCTION => CreativeCategory::CONSTRUCTION,
			CreativeInventoryInfo::CATEGORY_ITEMS => CreativeCategory::ITEMS,
			CreativeInventoryInfo::CATEGORY_NATURE => CreativeCategory::NATURE,
			CreativeInventoryInfo::CATEGORY_EQUIPMENT => CreativeCategory::EQUIPMENT,
			default => throw new InvalidArgumentException("Unknown creative category " . $info->getCategory())
		};
		CreativeInventory::getInstance()->add($item, $category, $group);
	}
}
