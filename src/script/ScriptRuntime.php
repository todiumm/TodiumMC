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

namespace pocketmine\script;

use pocketmine\behaviorpacks\BehaviorPack;
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Liquid;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\data\bedrock\block\BlockStateData;
use pocketmine\entity\Entity;
use pocketmine\entity\Location;
use pocketmine\event\entity\EntityDamageEvent;
use pocketmine\event\entity\EntityRegainHealthEvent;
use pocketmine\event\Cancellable;
use pocketmine\event\Event;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\math\Vector3;
use pocketmine\nbt\tag\ByteTag;
use pocketmine\nbt\tag\CompoundTag;
use pocketmine\nbt\tag\DoubleTag;
use pocketmine\nbt\tag\FloatTag;
use pocketmine\nbt\tag\IntTag;
use pocketmine\nbt\tag\ListTag;
use pocketmine\nbt\tag\LongTag;
use pocketmine\nbt\tag\StringTag;
use pocketmine\nbt\tag\Tag;
use pocketmine\network\mcpe\protocol\PlaySoundPacket;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\script\engine\Interpreter;
use pocketmine\script\engine\ScriptEngine;
use pocketmine\utils\Utils;
use pocketmine\VersionInfo;
use pocketmine\world\format\io\GlobalBlockStateHandlers;
use pocketmine\world\World;
use Symfony\Component\Filesystem\Path;
use function array_values;
use function count;
use function intdiv;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function json_decode;
use function json_encode;
use function microtime;
use function preg_replace;
use function str_starts_with;
use function strtolower;
use function substr;

/**
 * Todium's native Script API runtime.
 *
 * Bedrock's Script API is a JavaScript API. Todium runs those scripts on a JavaScript engine written in PHP (see
 * {@see \pocketmine\script\engine\ScriptEngine}), so no external JavaScript runtime has to be installed. The engine
 * loads each pack's modules, exposes the `@minecraft/server` surface, and calls back into the server through the
 * dotted host functions registered here (world.setBlock, player.sendMessage, ...).
 *
 * Scripts are written against the familiar `@minecraft/server` module surface, so an existing behaviour pack script
 * runs on Todium without modification. The engine is only started when at least one loaded behaviour pack declares a
 * script module, so servers with no scripts pay nothing for this feature.
 */
final class ScriptRuntime{

	/** How long a script may take to answer a cancellable ("before") event, in seconds. */
	public const BEFORE_EVENT_TIMEOUT = 1.0;

	private bool $started = false;
	private bool $shuttingDown = false;

	private ?ScriptEngine $engine = null;

	/**
	 * Host functions exposed to scripts, keyed by their dotted name.
	 *
	 * @var array<string, \Closure(list<mixed>) : mixed>
	 */
	private array $hostFunctions = [];

	/**
	 * Event handles for cancellable events currently being dispatched to scripts.
	 *
	 * @var array<string, Event>
	 */
	private array $eventHandles = [];

	private ?string $lastError = null;

	private int $nextRequestId = 1;

	/**
	 * Values stored by scripts through the dynamic property API, keyed by scope and property name.
	 *
	 * @var array<string, mixed>
	 */
	private array $dynamicProperties = [];

	public function __construct(
		private readonly Server $server
	){}

	/**
	 * The native engine needs no external runtime, so the Script API is always available.
	 */
	public static function isSupported() : bool{
		return true;
	}

	/**
	 * Boots the runtime for the given script-carrying behaviour packs.
	 *
	 * @param BehaviorPack[] $scriptPacks
	 */
	public function start(array $scriptPacks) : void{
		if($this->started){
			return;
		}
		if(count($scriptPacks) === 0){
			return;
		}

		$sources = $this->collectSources($scriptPacks);

		$this->registerHostFunctions();

		$entryPoints = [];
		foreach($scriptPacks as $pack){
			foreach($pack->getScriptEntries() as $entry){
				$entryPoints[] = Path::join($pack->getPackId(), $entry);
			}
		}

		$this->engine = new ScriptEngine(
			fn(string $name, array $arguments) : mixed => $this->callHostFunction($name, $arguments),
			$this->server->getName(),
			VersionInfo::BASE_VERSION
		);
		$this->engine->start($sources, $entryPoints);
		$this->started = true;

		if($this->engine->getLastError() !== ""){
			$this->lastError = $this->engine->getLastError();
			return;
		}

		$this->reportDetectedVersions($scriptPacks, $sources);
		$this->server->getLogger()->info("Script API runtime started for " . count($scriptPacks) . " behaviour pack(s)");
	}

	/**
	 * Detects the API version of each pack and logs it, so a pack that targets a version newer than Todium implements is
	 * visible in the console rather than silently clamped.
	 *
	 * @param BehaviorPack[]        $scriptPacks
	 * @param array<string, string> $sources
	 */
	private function reportDetectedVersions(array $scriptPacks, array $sources) : void{
		foreach($scriptPacks as $pack){
			$packId = $pack->getPackId();
			$version = ApiVersionDetector::detect($pack->getManifestData(), self::sourcesOfPack($packId, $sources));
			if($version === null){
				continue;
			}
			if($version->isNewerThanSupported()){
				$this->server->getLogger()->warning("Behaviour pack " . $packId . " targets @minecraft/server " . $version->toString() . ", newer than the " . ApiVersion::MAX_SUPPORTED . " surface Todium implements; scripts run against the newest surface Todium has");
			}else{
				$this->server->getLogger()->info("Behaviour pack " . $packId . " targets @minecraft/server " . $version->toString());
			}
		}
	}

	/**
	 * Narrows the full source map to the files belonging to one pack. Source keys are "<pack id>/<path>", so the pack id
	 * is the leading path segment.
	 *
	 * @param array<string, string> $sources
	 *
	 * @return list<string>
	 */
	private static function sourcesOfPack(string $packId, array $sources) : array{
		$prefix = $packId . "/";
		$own = [];
		foreach(Utils::stringifyKeys($sources) as $path => $contents){
			if(str_starts_with($path, $prefix)){
				$own[] = $contents;
			}
		}
		return $own;
	}

	/**
	 * Reads every file of every script-carrying pack into an in-memory module registry.
	 *
	 * @param BehaviorPack[] $scriptPacks
	 *
	 * @return array<string, string> path => source
	 */
	private function collectSources(array $scriptPacks) : array{
		$sources = [];
		foreach($scriptPacks as $pack){
			$prefix = $pack->getPackId();
			foreach($pack->listFiles() as $relativePath){
				$contents = $pack->readFile($relativePath);
				if($contents === null){
					continue;
				}
				$sources[Path::join($prefix, $relativePath)] = $contents;
			}
		}
		return $sources;
	}

	/**
	 * Dispatches a dotted host call coming from a script. Errors are logged and swallowed so that a misbehaving
	 * script cannot take the server down.
	 *
	 * @param list<mixed> $arguments
	 */
	private function callHostFunction(string $name, array $arguments) : mixed{
		if($name === "system.logError"){
			$this->server->getLogger()->error("[Script] " . Interpreter::toString($arguments[0] ?? ""));
			return null;
		}

		if($name === "system.log"){
			$this->logFromScript(Interpreter::toString($arguments[0] ?? "info"), Interpreter::toString($arguments[1] ?? ""));
			return null;
		}

		$closure = $this->hostFunctions[$name] ?? null;
		if($closure === null){
			$this->server->getLogger()->warning("[Script] Unknown Script API function \"" . $name . "\"");
			return null;
		}

		try{
			return $closure($arguments);
		}catch(\Throwable $error){
			$this->server->getLogger()->error("[Script] " . $name . " failed: " . $error->getMessage());
			return null;
		}
	}

	/**
	 * Builds the table of host functions that JavaScript may call.
	 */
	private function registerHostFunctions() : void{
		$this->hostFunctions = [
			//world
			"world.getPlayers" => fn(array $a) => $this->describePlayers($this->resolveWorld($a[0])->getPlayers()),
			"world.getEntities" => fn(array $a) => $this->describeEntities($this->resolveWorld($a[0])->getEntities()),
			"world.getTimeOfDay" => fn(array $a) => $this->resolveWorld($a[0])->getTimeOfDay(),
			"world.setTimeOfDay" => function(array $a) : null{
				$this->resolveWorld($a[0])->setTime((int) $a[1]);
				return null;
			},
			"world.getDay" => fn(array $a) => intdiv($this->resolveWorld($a[0])->getTime(), 24000),
			"world.getSeed" => fn(array $a) => $this->resolveWorld($a[0])->getSeed(),
			"world.getDifficulty" => fn(array $a) => $this->resolveWorld($a[0])->getDifficulty(),
			"world.getPlayerCount" => fn(array $a) => count($this->resolveWorld($a[0])->getPlayers()),
			"world.getBlock" => fn(array $a) => $this->describeBlock($this->resolveWorld($a[0]), $a[1]),
			"world.setBlock" => function(array $a) : bool{
				$this->setBlock($this->resolveWorld($a[0]), $a[1], $a[2]);
				return true;
			},
			"world.playSound" => function(array $a) : null{
				$this->playSound($this->resolveWorld($a[0]), $a[1], (string) $a[2], $a[3] ?? []);
				return null;
			},

			//entity
			"entity.isValid" => fn(array $a) => $this->resolveEntity((string) $a[0], (int) $a[1]) !== null,
			"entity.getHealth" => fn(array $a) => $this->requireEntity((string) $a[0], (int) $a[1])->getHealth(),
			"entity.getMaxHealth" => fn(array $a) => $this->requireEntity((string) $a[0], (int) $a[1])->getMaxHealth(),
			"entity.getLocation" => fn(array $a) => $this->describeLocation($this->requireEntity((string) $a[0], (int) $a[1])->getLocation()),
			"entity.getNameTag" => fn(array $a) => $this->requireEntity((string) $a[0], (int) $a[1])->getNameTag(),
			"entity.getTypeId" => fn(array $a) => $this->requireEntity((string) $a[0], (int) $a[1])::getNetworkTypeId(),
			"entity.isPlayer" => fn(array $a) => $this->requireEntity((string) $a[0], (int) $a[1]) instanceof Player,
			"entity.setNameTag" => function(array $a) : null{
				$this->requireEntity((string) $a[0], (int) $a[1])->setNameTag((string) $a[2]);
				return null;
			},
			"entity.teleport" => function(array $a) : bool{
				if(!is_array($a[2])){
					throw new ScriptApiException("A location with x, y and z is required");
				}
				$this->requireEntity((string) $a[0], (int) $a[1])->teleport($this->toVector3($a[2]));
				return true;
			},
			"entity.kill" => function(array $a) : null{
				$this->requireEntity((string) $a[0], (int) $a[1])->kill();
				return null;
			},
			"entity.heal" => function(array $a) : float{
				$entity = $this->requireEntity((string) $a[0], (int) $a[1]);
				$event = new EntityRegainHealthEvent($entity, (float) $a[2], EntityRegainHealthEvent::CAUSE_CUSTOM);
				$entity->heal($event);
				return $event->getAmount();
			},
			"entity.applyDamage" => function(array $a) : bool{
				$entity = $this->requireEntity((string) $a[0], (int) $a[1]);
				$entity->attack(new EntityDamageEvent($entity, EntityDamageEvent::CAUSE_CUSTOM, (float) $a[2]));
				return true;
			},

			//player
			"player.getName" => fn(array $a) => $this->requirePlayer((string) $a[0])->getName(),
			"player.getGameMode" => fn(array $a) => strtolower($this->requirePlayer((string) $a[0])->getGamemode()->name),
			"player.getXpLevel" => fn(array $a) => $this->requirePlayer((string) $a[0])->getXpManager()->getXpLevel(),
			"player.getWorld" => fn(array $a) => $this->requirePlayer((string) $a[0])->getWorld()->getFolderName(),
			"player.getRotation" => fn(array $a) => [
				"x" => $this->requirePlayer((string) $a[0])->getLocation()->getPitch(),
				"y" => $this->requirePlayer((string) $a[0])->getLocation()->getYaw()
			],
			"player.hasPermission" => fn(array $a) => $this->requirePlayer((string) $a[0])->hasPermission((string) $a[1]),
			"player.isOp" => fn(array $a) => $this->server->isOp($this->requirePlayer((string) $a[0])->getName()),
			"player.sendMessage" => function(array $a) : null{
				$this->requirePlayer((string) $a[0])->sendMessage((string) $a[1]);
				return null;
			},

			//server
			"server.getName" => fn(array $a) => $this->server->getName(),
			"server.getMotd" => fn(array $a) => $this->server->getMotd(),
			"server.getMaxPlayers" => fn(array $a) => $this->server->getMaxPlayers(),
			"server.getTick" => fn(array $a) => $this->server->getTick(),
			"server.getPlayers" => fn(array $a) => $this->describePlayers(array_values($this->server->getOnlinePlayers())),
			"server.getWorlds" => fn(array $a) => $this->describeWorlds(),
			"server.broadcastMessage" => function(array $a) : null{
				$this->server->broadcastMessage((string) $a[0]);
				return null;
			},
			"server.runCommand" => function(array $a) : bool{
				$sender = new ConsoleCommandSender($this->server, $this->server->getLanguage());
				return $this->server->dispatchCommand($sender, (string) $a[0]);
			},

			//event mutation; only valid for cancellable "before" events
			"event.cancel" => function(array $a) : bool{
				$event = $this->requireHandle((string) $a[0]);
				if($event instanceof Cancellable && method_exists($event, "cancel")){
					$event->cancel();
					return true;
				}
				return false;
			},
			"event.setMessage" => function(array $a) : bool{
				$event = $this->requireHandle((string) $a[0]);
				if($event instanceof PlayerChatEvent){
					$event->setMessage((string) $a[1]);
					return true;
				}
				return false;
			},

			//dynamic properties
			"world.getDynamicProperty" => fn(array $a) => $this->getDynamicProperty("world:" . Interpreter::toString($a[0] ?? "")),
			"world.setDynamicProperty" => function(array $a) : bool{
				$this->setDynamicProperty("world:" . Interpreter::toString($a[0] ?? ""), $a[1] ?? null);
				return true;
			},
			"player.getDynamicProperty" => fn(array $a) => $this->getDynamicProperty("player:" . Interpreter::toString($a[0] ?? "") . ":" . Interpreter::toString($a[1] ?? "")),
			"player.setDynamicProperty" => function(array $a) : bool{
				$this->setDynamicProperty("player:" . Interpreter::toString($a[0] ?? "") . ":" . Interpreter::toString($a[1] ?? ""), $a[2] ?? null);
				return true;
			},

			//system
			"system.getCurrentTick" => fn(array $a) => $this->server->getTick(),
			"system.getTimeMs" => fn(array $a) => (int) (microtime(true) * 1000)
		];
	}

	/**
	 * Routes a line of script output to the server console at the matching level.
	 */
	private function logFromScript(string $level, string $message) : void{
		$logger = $this->server->getLogger();
		match($level){
			"warning", "warn" => $logger->warning("[Script] " . $message),
			"error" => $logger->error("[Script] " . $message),
			"debug" => $logger->debug("[Script] " . $message),
			default => $logger->info("[Script] " . $message)
		};
	}
	public function isRunning() : bool{
		return $this->started && !$this->shuttingDown;
	}

	public function getLastError() : ?string{
		return $this->lastError;
	}

	/**
	 * Advances the script scheduler. Called once per server tick.
	 */
	public function tick() : void{
		if(!$this->isRunning()){
			return;
		}
		$this->engine?->tick();
	}

	/**
	 * Registers an event so that scripts may mutate it, returning a handle to pass along with the event.
	 */
	public function registerEventHandle(Event $event) : string{
		$handle = "event-" . $this->nextRequestId++;
		$this->eventHandles[$handle] = $event;
		return $handle;
	}

	public function releaseEventHandle(string $handle) : void{
		unset($this->eventHandles[$handle]);
	}

	/**
	 * Delivers an event to the scripts.
	 *
	 * @param mixed[] $payload
	 */
	public function dispatchEvent(string $name, array $payload, bool $cancelable, float $timeout = 0.0) : void{
		if(!$this->isRunning()){
			return;
		}
		$this->engine?->dispatchEvent($name, $payload, $cancelable);
	}

	public function shutdown() : void{
		$this->shuttingDown = true;
		$this->engine = null;
		$this->started = false;
	}





	// -----------------------------------------------------------------------------------------------------------------
	// object resolution + description
	// -----------------------------------------------------------------------------------------------------------------

	private function getDynamicProperty(string $key) : mixed{
		return $this->dynamicProperties[$key] ?? null;
	}

	private function setDynamicProperty(string $key, mixed $value) : void{
		if($value === null){
			unset($this->dynamicProperties[$key]);
			return;
		}
		$this->dynamicProperties[$key] = $value;
	}
	private function resolveWorld(mixed $folderName) : World{
		if(!is_string($folderName)){
			throw new ScriptApiException("Invalid world name");
		}
		$world = $this->server->getWorldManager()->getWorldByName($folderName);
		if($world === null){
			throw new ScriptApiException("World \"$folderName\" is not loaded");
		}
		return $world;
	}

	private function resolveEntity(string $worldName, int $entityId) : ?Entity{
		return $this->server->getWorldManager()->getWorldByName($worldName)?->getEntity($entityId);
	}

	private function requireEntity(string $worldName, int $entityId) : Entity{
		$entity = $this->resolveEntity($worldName, $entityId);
		if($entity === null){
			throw new ScriptApiException("Entity $entityId is no longer valid in world \"$worldName\"");
		}
		return $entity;
	}

	private function requirePlayer(string $name) : Player{
		$player = $this->server->getPlayerExact($name);
		if($player === null){
			throw new ScriptApiException("Player \"$name\" is not online");
		}
		return $player;
	}

	private function requireHandle(string $handle) : Event{
		$event = $this->eventHandles[$handle] ?? null;
		if($event === null){
			throw new ScriptApiException("This event can no longer be modified");
		}
		return $event;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function describePlayer(Player $player) : array{
		$location = $player->getLocation();
		return [
			"id" => $player->getId(),
			"name" => $player->getName(),
			"world" => $player->getWorld()->getFolderName(),
			"location" => $this->describeLocation($location),
			"rotation" => ["x" => $location->getPitch(), "y" => $location->getYaw()],
			"health" => $player->getHealth(),
			"maxHealth" => $player->getMaxHealth(),
			"gameMode" => strtolower($player->getGamemode()->name),
			"isOp" => $this->server->isOp($player->getName())
		];
	}

	/**
	 * @param Player[] $players
	 * @return array<int, array<string, mixed>>
	 */
	private function describePlayers(array $players) : array{
		$result = [];
		foreach($players as $player){
			if($player->isConnected()){
				$result[] = $this->describePlayer($player);
			}
		}
		return $result;
	}

	/**
	 * @param Entity[] $entities
	 * @return array<int, array<string, mixed>>
	 */
	private function describeEntities(array $entities) : array{
		$result = [];
		foreach($entities as $entity){
			if($entity->isClosed()){
				continue;
			}
			$result[] = [
				"id" => $entity->getId(),
				"typeId" => $entity::getNetworkTypeId(),
				"world" => $entity->getWorld()->getFolderName(),
				"location" => $this->describeLocation($entity->getLocation()),
				"health" => $entity->getHealth(),
				"maxHealth" => $entity->getMaxHealth(),
				"nameTag" => $entity->getNameTag(),
				"isPlayer" => $entity instanceof Player
			];
		}
		return $result;
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	private function describeWorlds() : array{
		$result = [];
		foreach($this->server->getWorldManager()->getWorlds() as $world){
			$result[] = [
				"name" => $world->getFolderName(),
				"playerCount" => count($world->getPlayers()),
				"timeOfDay" => $world->getTimeOfDay(),
				"day" => intdiv($world->getTime(), 24000),
				"seed" => $world->getSeed()
			];
		}
		return $result;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function describeLocation(Location $location) : array{
		return [
			"x" => $location->getX(),
			"y" => $location->getY(),
			"z" => $location->getZ(),
			"yaw" => $location->getYaw(),
			"pitch" => $location->getPitch()
		];
	}

	/**
	 * @param mixed[] $coordinates
	 * @return array<string, mixed>
	 */
	private function describeBlock(World $world, mixed $coordinates) : array{
		if(!is_array($coordinates)){
			throw new ScriptApiException("A position with x, y and z is required");
		}
		$position = $this->toVector3($coordinates);
		if(!$world->isInWorld($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())){
			throw new ScriptApiException("Position is outside of the world");
		}
		$block = $world->getBlock($position);
		return [
			"typeId" => $block->getName(),
			"location" => [
				"x" => $block->getPosition()->getX(),
				"y" => $block->getPosition()->getY(),
				"z" => $block->getPosition()->getZ()
			],
			"isAir" => $block->getTypeId() === BlockTypeIds::AIR,
			"isLiquid" => $block instanceof Liquid
		];
	}

	/**
	 * @param mixed[] $coordinates
	 * @param mixed[] $blockState
	 */
	private function setBlock(World $world, mixed $coordinates, mixed $blockState) : void{
		if(!is_array($coordinates) || !is_array($blockState)){
			throw new ScriptApiException("setBlock requires a position and a block");
		}
		$typeId = $blockState["typeId"] ?? null;
		if(!is_string($typeId) || $typeId === ""){
			throw new ScriptApiException("setBlock requires a block type ID");
		}
		$position = $this->toVector3($coordinates);
		if(!$world->isInWorld($position->getFloorX(), $position->getFloorY(), $position->getFloorZ())){
			throw new ScriptApiException("Position is outside of the world");
		}

		$states = $blockState["states"] ?? [];
		$blockStateData = BlockStateData::current($typeId, self::toBlockStates(is_array($states) ? $states : []));
		$world->setBlock($position, GlobalBlockStateHandlers::getDeserializer()->deserializeBlock($blockStateData));
	}

	/**
	 * @param mixed[] $coordinates
	 */
	private function toVector3(array $coordinates) : Vector3{
		if(!isset($coordinates["x"], $coordinates["y"], $coordinates["z"])){
			throw new ScriptApiException("A position with x, y and z is required");
		}
		return new Vector3(
			self::toFloat($coordinates["x"]),
			self::toFloat($coordinates["y"]),
			self::toFloat($coordinates["z"])
		);
	}

	/**
	 * @param mixed[] $location
	 * @param mixed[] $options
	 */
	private function playSound(World $world, mixed $location, string $soundId, mixed $options) : void{
		if(!is_array($location)){
			throw new ScriptApiException("A location with x, y and z is required");
		}
		$position = $this->toVector3($location);
		$volume = 1.0;
		$pitch = 1.0;
		if(is_array($options)){
			$volume = self::toFloat($options["volume"] ?? 1.0);
			$pitch = self::toFloat($options["pitch"] ?? 1.0);
		}

		$world->broadcastPacketToViewers($position, PlaySoundPacket::create(
			$soundId,
			$position->getX(),
			$position->getY(),
			$position->getZ(),
			$volume,
			$pitch,
			1,
			false,
			null,
			null
		));
	}
	private static function toFloat(mixed $value) : float{
		return is_int($value) || is_float($value) || is_bool($value) ? (float) $value : 0.0;
	}

	/**
	 * Converts a JSON object of Bedrock block states into the NBT tags the block deserializer expects.
	 *
	 * @param mixed[] $states
	 * @return array<string, Tag>
	 */
	private static function toBlockStates(array $states) : array{
		$result = [];
		foreach(Utils::promoteKeys($states) as $name => $value){
			$tag = self::toTag($value);
			if($tag !== null){
				$result[(string) $name] = $tag;
			}
		}
		return $result;
	}

	private static function toTag(mixed $value) : ?Tag{
		if(is_bool($value)){
			return new ByteTag($value ? 1 : 0);
		}
		if(is_int($value)){
			return new IntTag($value);
		}
		if(is_float($value)){
			return new FloatTag($value);
		}
		if(is_string($value)){
			return new StringTag($value);
		}
		if(is_array($value)){
			$tags = [];
			$allNumeric = true;
			foreach(Utils::promoteKeys($value) as $key => $child){
				$tag = self::toTag($child);
				if($tag === null){
					continue;
				}
				$tags[] = $tag;
				$allNumeric = $allNumeric && ctype_digit((string) $key);
			}
			return $allNumeric ? new ListTag($tags) : new CompoundTag($tags);
		}
		return null;
	}
}
