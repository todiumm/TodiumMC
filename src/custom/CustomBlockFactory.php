<?php

declare(strict_types=1);

namespace pocketmine\custom;

use Closure;
use InvalidArgumentException;
use pocketmine\block\Block;
use pocketmine\block\BlockIdentifier;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\RuntimeBlockStateRegistry;
use pocketmine\custom\block\BlockComponents;
use pocketmine\custom\block\permutations\Permutable;
use pocketmine\custom\block\permutations\Permutation;
use pocketmine\custom\block\permutations\Permutations;
use pocketmine\custom\item\CreativeInventoryInfo;
use pocketmine\custom\util\NBT;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\data\bedrock\block\convert\BlockStateReader;
use pocketmine\data\bedrock\block\convert\BlockStateWriter;
use pocketmine\nbt\LittleEndianNbtSerializer;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\TreeRoot;
use pocketmine\network\mcpe\cache\StaticPacketCache;
use pocketmine\network\mcpe\convert\BlockStateDictionary;
use pocketmine\network\mcpe\convert\BlockStateDictionaryEntry;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\network\mcpe\protocol\types\BlockPaletteEntry;
use pocketmine\network\mcpe\protocol\types\CacheableNbt;
use pocketmine\scheduler\AsyncPool;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use ReflectionClass;
use function array_keys;
use function array_map;
use function array_values;
use function count;
use function array_reverse;
use function hash;
use function hexdec;
use function ksort;
use function unpack;
use const SORT_STRING;

/**
 * Native registry for custom blocks of protocol 1.26.50.
 *
 * Since 1.26.50 a block state's network runtime ID is a 32-bit FNV-1a hash of its little-endian NBT (name plus
 * key-sorted states), so custom states never shift the IDs of vanilla ones; they are simply added to the dictionary.
 */
final class CustomBlockFactory{
	/** Molang version the vanilla block definitions of 1.26.50 are sent with. */
	public const CLIENT_MOLANG_VERSION = 13;

	/** @var array<string, Block> */
	private static array $blocks = [];
	/** @var list<BlockPaletteEntry> */
	private static array $definitions = [];
	/**
	 * Everything a worker thread needs to rebuild the same registrations.
	 * @var array<string, array{int, Closure, Closure|null, Closure|null}>
	 */
	private static array $workerData = [];
	private static bool $workerHookInstalled = false;

	private function __construct(){}

	/**
	 * @phpstan-param Closure(BlockIdentifier) : Block $blockFunc
	 * @phpstan-param (Closure(Block) : BlockStateWriter)|null $serializer
	 * @phpstan-param (Closure(BlockStateReader) : Block)|null $deserializer
	 */
	public static function register(string $identifier, Closure $blockFunc, ?CreativeInventoryInfo $creativeInfo = null, ?Closure $serializer = null, ?Closure $deserializer = null) : Block{
		if(isset(self::$blocks[$identifier])){
			throw new InvalidArgumentException("Custom block $identifier is already registered");
		}
		$typeId = BlockTypeIds::newId();
		$block = self::registerWithTypeId($typeId, $identifier, $blockFunc, $serializer, $deserializer, $creativeInfo);
		self::$workerData[$identifier] = [$typeId, $blockFunc, $serializer, $deserializer];
		return $block;
	}

	public static function get(string $identifier) : Block{
		return clone (self::$blocks[$identifier] ?? throw new InvalidArgumentException("Custom block $identifier is not registered"));
	}

	public static function isRegistered(string $identifier) : bool{
		return isset(self::$blocks[$identifier]);
	}

	/**
	 * @return string[]
	 */
	public static function getRegisteredIdentifiers() : array{
		return array_keys(self::$blocks);
	}

	/**
	 * Block definitions to append to StartGamePacket.
	 * @return list<BlockPaletteEntry>
	 */
	public static function getDefinitions() : array{
		return self::$definitions;
	}

	/**
	 * Makes every async worker (chunk encoding included) know the custom blocks, now and for workers started later.
	 * Must be called once, after all custom blocks are registered.
	 */
	public static function syncWorkers(AsyncPool $pool) : void{
		if(self::$workerHookInstalled || self::$workerData === []){
			return;
		}
		self::$workerHookInstalled = true;
		$data = self::$workerData;
		$pool->addWorkerStartHook(static function(int $worker) use ($pool, $data) : void{
			$pool->submitTaskToWorker(new RegisterCustomBlocksTask($data), $worker);
		});
	}

	/**
	 * Registers a block under a fixed type ID. Used directly by worker threads, which must reproduce the main
	 * thread's type IDs exactly because chunks cross threads as internal state IDs.
	 *
	 * @internal
	 */
	public static function registerWithTypeId(int $typeId, string $identifier, Closure $blockFunc, ?Closure $serializer, ?Closure $deserializer, ?CreativeInventoryInfo $creativeInfo) : Block{
		$block = $blockFunc(new BlockIdentifier($typeId));
		RuntimeBlockStateRegistry::getInstance()->register($block);
		self::$blocks[$identifier] = $block;
		CustomItemFactory::registerBlockItem($identifier, $block);

		$components = CompoundTag::create();
		$properties = CompoundTag::create();
		if($block instanceof BlockComponents){
			foreach($block->getComponents() as $component){
				$components->setTag($component->getName(), $component->getValue());
			}
		}

		/** @var list<CompoundTag> $stateTags */
		$stateTags = [];
		if($block instanceof Permutable){
			$names = $values = $blockProperties = [];
			foreach($block->getBlockProperties() as $property){
				$names[] = $property->getName();
				$values[] = $property->getValues();
				$blockProperties[] = $property->toNBT();
			}
			$permutations = array_map(static fn(Permutation $p) => $p->toNBT(), $block->getPermutations());

			//the client needs this component to predict placement
			$components->setTag("minecraft:on_player_placing", CompoundTag::create());
			$properties
				->setTag("permutations", new ListTag($permutations))
				->setTag("properties", new ListTag(array_reverse($blockProperties)));

			foreach(Permutations::getCartesianProduct($values) as $combination){
				$states = CompoundTag::create();
				foreach($combination as $i => $value){
					$states->setTag($names[$i], NBT::getTagType($value));
				}
				$stateTags[] = $states;
			}

			$serializer ??= static function(Permutable $block) use ($identifier) : BlockStateWriter{
				$writer = BlockStateWriter::create($identifier);
				$block->serializeState($writer);
				return $writer;
			};
			$deserializer ??= static function(BlockStateReader $in) use ($identifier) : Block{
				$b = CustomBlockFactory::get($identifier);
				if(!$b instanceof Permutable){
					throw new \LogicException("Custom block $identifier lost its Permutable interface");
				}
				$b->deserializeState($in);
				return $b;
			};
		}else{
			$stateTags[] = CompoundTag::create();
			$serializer ??= static fn() => BlockStateData::current($identifier, []);
			$deserializer ??= static fn(BlockStateReader $in) => clone $block;
		}

		GlobalBlockStateHandlers::getSerializer()->map($block, $serializer);
		GlobalBlockStateHandlers::getDeserializer()->map($identifier, $deserializer);

		self::injectStates($identifier, $stateTags);

		$creativeInfo ??= CreativeInventoryInfo::DEFAULT();
		$properties
			->setTag("components", $components->setTag("minecraft:creative_category", CompoundTag::create()
				->setString("category", $creativeInfo->getCategory())
				->setString("group", $creativeInfo->getGroup())))
			->setTag("menu_category", CompoundTag::create()
				->setString("category", $creativeInfo->getCategory())
				->setString("group", $creativeInfo->getGroup()))
			->setInt("molangVersion", self::CLIENT_MOLANG_VERSION);
		self::$definitions[] = $definition = new BlockPaletteEntry($identifier, new CacheableNbt($properties));
		self::appendToStaticPacketCache($definition);

		CustomItemFactory::addToCreativeInventory($block->asItem(), $creativeInfo);
		return $block;
	}

	/**
	 * Network runtime ID of a block state: signed 32-bit FNV-1a of its little-endian NBT, states sorted by key.
	 */
	public static function computeNetworkId(string $name, CompoundTag $states) : int{
		$sorted = $states->getValue();
		ksort($sorted, SORT_STRING);
		$sortedTag = CompoundTag::create();
		foreach($sorted as $key => $tag){
			$sortedTag->setTag($key, $tag);
		}
		$root = CompoundTag::create()->setString("name", $name)->setTag("states", $sortedTag);
		$hash = (int) hexdec(hash("fnv1a32", (new LittleEndianNbtSerializer())->write(new TreeRoot($root))));
		return $hash >= 0x80000000 ? $hash - 0x100000000 : $hash;
	}

	/**
	 * @param list<CompoundTag> $stateTags one per permutation, the position is the meta value
	 */
	private static function injectStates(string $identifier, array $stateTags) : void{
		$dictionary = TypeConverter::getInstance()->getBlockTranslator()->getBlockStateDictionary();
		$reflection = new ReflectionClass(BlockStateDictionary::class);

		$statesProperty = $reflection->getProperty("states");
		/** @var array<int, BlockStateDictionaryEntry> $states */
		$states = $statesProperty->getValue($dictionary);
		$lookupProperty = $reflection->getProperty("stateDataToStateIdLookup");
		$lookup = $lookupProperty->getValue($dictionary);

		$perState = [];
		foreach($stateTags as $meta => $stateTag){
			$networkId = self::computeNetworkId($identifier, $stateTag);
			$entry = new BlockStateDictionaryEntry($identifier, $stateTag->getValue(), $meta);
			$states[$networkId] = $entry;
			$perState[$entry->getRawStateProperties()] = $networkId;
		}
		$lookup[$identifier] = count($perState) === 1 ? array_values($perState)[0] : $perState;

		$statesProperty->setValue($dictionary, $states);
		$lookupProperty->setValue($dictionary, $lookup);
		$reflection->getProperty("idMetaToStateIdLookupCache")->setValue($dictionary, null);
	}


	private static function appendToStaticPacketCache(BlockPaletteEntry $definition) : void{
		$cache = StaticPacketCache::getInstance();
		$property = (new ReflectionClass($cache))->getProperty("blockDefinitions");
		$list = $property->getValue($cache);
		$list[] = $definition;
		$property->setValue($cache, $list);
	}
}
