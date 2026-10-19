<?php

/*
 *
 *      _    _ _
 *     / \  | | |_ __ _ _   _
 *    / _ \ | | __/ _` | | | |
 *   / ___ \| | || (_| | |_| |
 *  /_/   \_\__\__,_|\__, |
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

namespace pocketmine\script\engine;

use Closure;

/**
 * The `@minecraft/server` surface, implemented in PHP.
 *
 * This mirrors the JavaScript host library that the Node sidecar used to load: scripts written against
 * `world`, `system`, `Player`, `Dimension` and friends keep working unchanged. Host calls are dispatched through
 * the same dotted names the PHP server has always used, so a script cannot tell which backend is running it.
 */
final class ServerApi{

	/** @var array<string, list<JsFunction>> */
	private array $afterEvents = [];
	/** @var array<string, list<JsFunction>> */
	private array $beforeEvents = [];

	/** @var list<array{ticks: int, callback: JsFunction, next: int}> */
	private array $intervals = [];
	/** @var list<array{ticks: int, callback: JsFunction, at: int}> */
	private array $timeouts = [];

	private int $currentTick = 0;

	private ?JsObject $world = null;

	/**
	 * @param Closure(string, list<mixed>) : mixed $hostCall
	 */
	public function __construct(
		private readonly Closure $hostCall,
		private readonly string $serverName = "Todium",
		private readonly string $minecraftVersion = ""
	){}

	/**
	 * Builds the module namespace scripts import as "@minecraft/server".
	 *
	 * The whole surface is exposed whatever version a pack declares: the version says which API a pack was written
	 * against, not which symbols the server withholds. A pack that asks for an older version therefore still finds
	 * newer symbols instead of tripping over a hole in the namespace.
	 */
	public function createModule() : JsObject{
		$module = new JsObject();
		$module->set("world", $this->getWorld());
		$module->set("system", $this->createSystem());
		$module->set("serverName", $this->serverName);
		$module->set("minecraftVersion", $this->minecraftVersion);
		$module->set("BlockPermutation", $this->createBlockPermutation());
		$module->set("BlockType", new JsObject());
		$module->set("EntityDamageCause", $this->createDamageCause());
		$module->set("GameMode", $this->createGameMode());
		$module->set("EquipmentSlot", $this->createEquipmentSlot());
		$module->set("ItemStack", new JsFunction(name: "ItemStack"));
		$module->set("Vector3", new JsFunction(name: "Vector3"));
		return $module;
	}

	public function getWorld() : JsObject{
		if($this->world !== null){
			return $this->world;
		}

		$world = new JsObject();
		$this->world = $world;

		$afterEvents = new JsObject();
		$beforeEvents = new JsObject();

		foreach(["worldLoad", "worldInitialize", "playerSpawn", "playerLeave", "playerJoin", "playerDimensionChange", "playerGameModeChange", "playerBreakBlock", "playerPlaceBlock", "playerInteractWithBlock", "playerInteractWithEntity", "playerEmote", "playerInputModeChange", "playerInputPermissionCategoryChange", "entityDie", "entityRemove", "entitySpawn", "entityHurt", "entityHealthChanged", "entityHitEntity", "entityHitBlock", "entityLoad", "projectileHitBlock", "projectileHitEntity", "blockBreak", "blockPlace", "buttonPush", "leverAction", "pressurePlatePush", "pressurePlatePop", "targetBlockHit", "tripWireTrip", "explosion", "effectAdd", "weatherChange", "chatSend", "dataDrivenEntityTrigger", "entityEffectAdd", "itemUse", "itemUseOn", "itemStartUse", "itemStopUse", "itemReleaseUse", "itemCompleteUse", "itemStartUseOn", "itemStopUseOn", "pistonActivate", "scriptTick"] as $name){
			$afterEvents->set($name, $this->createSignal($name, false));
		}
		foreach(["chatSend", "entityHurt", "entityRemove", "playerInteractWithBlock", "playerInteractWithEntity", "playerBreakBlock", "playerPlaceBlock", "playerLeave", "playerGameModeChange", "itemUse", "itemUseOn", "explosion", "effectAdd", "weatherChange", "dataDrivenEntityTrigger", "pistonActivate"] as $name){
			$beforeEvents->set($name, $this->createSignal($name, true));
		}

		$world->set("afterEvents", $afterEvents);
		$world->set("beforeEvents", $beforeEvents);

		$world->set("getDimension", Builtins::fn(function(array $arguments) : JsObject{
			return $this->createDimension(self::dimensionId($arguments[0] ?? null));
		}, "getDimension"));

		$world->set("getAllPlayers", Builtins::fn(function(array $arguments) : JsArray{
			$players = $this->callHost("world.getPlayers", [self::dimensionId($arguments[0] ?? null)]);
			return $this->describePlayers($players);
		}, "getAllPlayers"));

		$world->set("sendMessage", Builtins::fn(function(array $arguments) : null{
			$this->callHost("server.broadcastMessage", [self::messageToString($arguments[0] ?? "")]);
			return null;
		}, "sendMessage"));

		$world->set("getTimeOfDay", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("world.getTimeOfDay", ["minecraft:overworld"]), "getTimeOfDay"));
		$world->set("getDay", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("world.getDay", ["minecraft:overworld"]), "getDay"));
		$world->set("getAbsoluteTime", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("world.getTimeOfDay", ["minecraft:overworld"]), "getAbsoluteTime"));
		$world->set("setTimeOfDay", Builtins::fn(function(array $arguments) : null{
			$this->callHost("world.setTimeOfDay", ["minecraft:overworld", $arguments[0] ?? 0]);
			return null;
		}, "setTimeOfDay"));
		$world->set("setAbsoluteTime", Builtins::fn(function(array $arguments) : null{
			$this->callHost("world.setTimeOfDay", ["minecraft:overworld", $arguments[0] ?? 0]);
			return null;
		}, "setAbsoluteTime"));
		$world->set("getDayOfWeek", Builtins::fn(fn(array $arguments) : int => (int) Interpreter::toNumber($this->callHost("world.getDay", ["minecraft:overworld"])) % 7, "getDayOfWeek"));

		$world->set("getPlayers", Builtins::fn(function(array $arguments) : JsArray{
			return $this->describePlayers($this->callHost("world.getPlayers", [self::dimensionId($arguments[0] ?? null)]));
		}, "getPlayers"));

		$world->set("getEntities", Builtins::fn(function(array $arguments) : JsArray{
			$dimension = $this->createDimension(self::dimensionId($arguments[0] ?? null));
			return $this->describeEntities($dimension, $this->callHost("world.getEntities", [$dimension->get("id")]));
		}, "getEntities"));

		$world->set("getDynamicProperty", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("world.getDynamicProperty", [Interpreter::toString($arguments[0] ?? "")]), "getDynamicProperty"));
		$world->set("setDynamicProperty", Builtins::fn(function(array $arguments) : null{
			$this->callHost("world.setDynamicProperty", [Interpreter::toString($arguments[0] ?? ""), Interpreter::toString($arguments[1] ?? "")]);
			return null;
		}, "setDynamicProperty"));
		$world->set("getDynamicPropertyIds", Builtins::fn(fn(array $arguments) : JsArray => new JsArray([]), "getDynamicPropertyIds"));

		return $world;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Dimensions, entities and players
	// ---------------------------------------------------------------------------------------------------------

	public function createDimension(string $id) : JsObject{
		$dimension = new JsObject();
		$dimension->set("id", $id);
		$dimension->set("getPlayers", Builtins::fn(fn(array $arguments) : JsArray => $this->describePlayers($this->callHost("world.getPlayers", [$id])), "getPlayers"));
		$dimension->set("getEntities", Builtins::fn(fn(array $arguments) : JsArray => $this->describeEntities($dimension, $this->callHost("world.getEntities", [$id])), "getEntities"));
		$dimension->set("getBlock", Builtins::fn(function(array $arguments) use ($id) : JsObject{
			return $this->createBlock($this->callHost("world.getBlock", [$id, self::coordinates($arguments[0] ?? null)]));
		}, "getBlock"));
		$dimension->set("getBlockFromRay", Builtins::fn(fn(array $arguments) : null => null, "getBlockFromRay"));
		$dimension->set("setBlockType", Builtins::fn(function(array $arguments) use ($id) : null{
			$this->callHost("world.setBlock", [$id, self::coordinates($arguments[0] ?? null), ["typeId" => Interpreter::toString($arguments[1] ?? "")]]);
			return null;
		}, "setBlockType"));
		$dimension->set("setBlockPermutation", Builtins::fn(function(array $arguments) use ($id) : null{
			$this->callHost("world.setBlock", [$id, self::coordinates($arguments[0] ?? null), self::permutationToBlockState($arguments[1] ?? null)]);
			return null;
		}, "setBlockPermutation"));
		$dimension->set("playSound", Builtins::fn(function(array $arguments) use ($id) : null{
			$this->callHost("world.playSound", [$id, Interpreter::toString($arguments[0] ?? ""), self::coordinates($arguments[1] ?? null), []]);
			return null;
		}, "playSound"));
		$dimension->set("spawnEntity", Builtins::fn(fn(array $arguments) : null => null, "spawnEntity"));
		$dimension->set("spawnParticle", Builtins::fn(fn(array $arguments) : null => null, "spawnParticle"));
		$dimension->set("runCommand", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("server.runCommand", [Interpreter::toString($arguments[0] ?? "")]), "runCommand"));
		$dimension->set("getEntitiesAtBlockLocation", Builtins::fn(fn(array $arguments) : JsArray => new JsArray([]), "getEntitiesAtBlockLocation"));
		return $dimension;
	}

	public function createPlayer(mixed $data) : JsObject{
		$player = new JsObject();
		$name = Interpreter::toString(self::dataField($data, "name"));
		$worldName = Interpreter::toString(self::dataField($data, "world"));
		$entityId = (int) Interpreter::toNumber(self::dataField($data, "id"));

		$player->set("__type", "player");
		$player->set("name", $name);
		$player->set("id", $entityId);
		$player->set("dimension", $this->createDimension($worldName));
		$player->set("location", self::createVector(self::dataField($data, "location")));
		$player->set("isValid", true);

		$player->set("sendMessage", Builtins::fn(function(array $arguments) use ($name) : null{
			$this->callHost("player.sendMessage", [$name, self::messageToString($arguments[0] ?? "")]);
			return null;
		}, "sendMessage"));

		$player->set("teleport", Builtins::fn(function(array $arguments) use ($worldName, $entityId) : null{
			$location = $arguments[0] ?? null;
			$target = $location instanceof JsObject && $location->has("facingLocation") ? $location->get("facingLocation") : $location;
			$this->callHost("entity.teleport", [$worldName, $entityId, self::coordinates($target)]);
			return null;
		}, "teleport"));

		$player->set("runCommand", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("server.runCommand", [Interpreter::toString($arguments[0] ?? "")]), "runCommand"));
		$player->set("getRotation", Builtins::fn(fn(array $arguments) : JsObject => self::createVector(self::dataField($data, "rotation")), "getRotation"));
		$player->set("hasPermission", Builtins::fn(fn(array $arguments) : bool => (bool) $this->callHost("player.hasPermission", [$name, Interpreter::toString($arguments[0] ?? "")]), "hasPermission"));
		$player->set("getComponent", Builtins::fn(fn(array $arguments) : ?JsObject => $this->createComponent($entityId, $worldName, $arguments[0] ?? null, $data), "getComponent"));
		$player->set("getDynamicProperty", Builtins::fn(fn(array $arguments) : mixed => $this->callHost("player.getDynamicProperty", [$name, Interpreter::toString($arguments[0] ?? "")]), "getDynamicProperty"));
		$player->set("setDynamicProperty", Builtins::fn(function(array $arguments) use ($name) : null{
			$this->callHost("player.setDynamicProperty", [$name, Interpreter::toString($arguments[0] ?? ""), Interpreter::toString($arguments[1] ?? "")]);
			return null;
		}, "setDynamicProperty"));

		$this->attachComputed($player, "gameMode", fn() : mixed => $this->callHost("player.getGameMode", [$name]));
		$this->attachComputed($player, "xpLevel", fn() : mixed => $this->callHost("player.getXpLevel", [$name]));
		$this->attachComputed($player, "isOp", fn() : mixed => $this->callHost("player.isOp", [$name]));

		return $player;
	}

	public function createEntity(mixed $data, JsObject $dimension) : JsObject{
		$entity = new JsObject();
		$entityId = (int) Interpreter::toNumber(self::dataField($data, "id"));
		$dimensionId = $dimension->get("id");
		$worldName = $dimensionId instanceof JsUndefined
			? Interpreter::toString(self::dataField($data, "world"))
			: Interpreter::toString($dimensionId);

		$entity->set("__type", "entity");
		$entity->set("id", $entityId);
		$entity->set("typeId", self::dataField($data, "typeId"));
		$entity->set("dimension", $dimension);
		$entity->set("location", self::createVector(self::dataField($data, "location")));

		$this->attachComputed($entity, "isValid", fn() : mixed => $this->callHost("entity.isValid", [$worldName, $entityId]));
		$this->attachComputed($entity, "nameTag", fn() : mixed => $this->callHost("entity.getNameTag", [$worldName, $entityId]));

		$entity->set("teleport", Builtins::fn(function(array $arguments) use ($worldName, $entityId) : null{
			$location = $arguments[0] ?? null;
			$target = $location instanceof JsObject && $location->has("facingLocation") ? $location->get("facingLocation") : $location;
			$this->callHost("entity.teleport", [$worldName, $entityId, self::coordinates($target)]);
			return null;
		}, "teleport"));

		$entity->set("kill", Builtins::fn(function(array $arguments) use ($worldName, $entityId) : null{
			$this->callHost("entity.kill", [$worldName, $entityId]);
			return null;
		}, "kill"));

		$entity->set("applyDamage", Builtins::fn(function(array $arguments) use ($worldName, $entityId) : bool{
			return (bool) $this->callHost("entity.applyDamage", [$worldName, $entityId, Interpreter::toNumber($arguments[0] ?? 0)]);
		}, "applyDamage"));

		$entity->set("getComponent", Builtins::fn(fn(array $arguments) : ?JsObject => $this->createComponent($entityId, $worldName, $arguments[0] ?? null, null), "getComponent"));

		return $entity;
	}

	private function createComponent(int $entityId, string $worldName, mixed $componentId, mixed $data) : ?JsObject{
		$id = Interpreter::toString($componentId);
		if($id !== "minecraft:health"){
			return null;
		}

		$component = new JsObject();
		$component->set("typeId", "minecraft:health");
		$this->attachComputed($component, "currentValue", fn() : mixed => $this->callHost("entity.getHealth", [$worldName, $entityId]));
		$this->attachComputed($component, "effectiveMax", fn() : mixed => $this->callHost("entity.getMaxHealth", [$worldName, $entityId]));
		$component->set("setCurrentValue", Builtins::fn(function(array $arguments) use ($worldName, $entityId) : null{
			$current = Interpreter::toNumber($this->callHost("entity.getHealth", [$worldName, $entityId]));
			$target = Interpreter::toNumber($arguments[0] ?? 0);
			if($target > $current){
				$this->callHost("entity.heal", [$worldName, $entityId, $target - $current]);
			}elseif($target < $current){
				$this->callHost("entity.applyDamage", [$worldName, $entityId, $current - $target]);
			}
			return null;
		}, "setCurrentValue"));
		return $component;
	}

	/**
	 * Defines a computed property, mirroring a JavaScript getter.
	 *
	 * @param Closure() : mixed $getter
	 */
	private function attachComputed(JsObject $target, string $name, Closure $getter) : void{
		$target->defineGetter($name, $getter);
	}

	/**
	 * @return JsArray
	 */
	private function describePlayers(mixed $data) : JsArray{
		$players = [];
		foreach(self::listOf($data) as $entry){
			$players[] = $this->createPlayer($entry);
		}
		return new JsArray($players);
	}

	private function describeEntities(JsObject $dimension, mixed $data) : JsArray{
		$entities = [];
		foreach(self::listOf($data) as $entry){
			$entities[] = $this->createEntity($entry, $dimension);
		}
		return new JsArray($entities);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Events
	// ---------------------------------------------------------------------------------------------------------

	private function createSignal(string $name, bool $before) : JsObject{
		$signal = new JsObject();
		$signal->set("subscribe", Builtins::fn(function(array $arguments) use ($name, $before) : JsFunction{
			$callback = $arguments[0] ?? null;
			if(!$callback instanceof JsFunction){
				throw new JsThrow("TypeError: subscribe expects a function");
			}
			if($before){
				$this->beforeEvents[$name][] = $callback;
			}else{
				$this->afterEvents[$name][] = $callback;
			}
			return Builtins::fn(function(array $more) use ($name, $before, $callback) : null{
				$this->unsubscribe($name, $before, $callback);
				return null;
			}, "unsubscribe");
		}, "subscribe"));
		$signal->set("unsubscribe", Builtins::fn(function(array $arguments) use ($name, $before) : null{
			$callback = $arguments[0] ?? null;
			if($callback instanceof JsFunction){
				$this->unsubscribe($name, $before, $callback);
			}
			return null;
		}, "unsubscribe"));
		return $signal;
	}

	private function unsubscribe(string $name, bool $before, JsFunction $callback) : void{
		$target = $before ? $this->beforeEvents : $this->afterEvents;
		$remaining = [];
		foreach($target[$name] ?? [] as $existing){
			if($existing !== $callback){
				$remaining[] = $existing;
			}
		}
		if($before){
			$this->beforeEvents[$name] = $remaining;
		}else{
			$this->afterEvents[$name] = $remaining;
		}
	}

	/**
	 * Builds the event object scripts receive, mirroring the factories in the JavaScript host library.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function createEventObject(string $name, array $payload) : mixed{
		return match($name){
			"worldLoad", "worldInitialize" => new JsObject(["worldName" => $payload["worldName"] ?? ""]),
			"playerSpawn" => new JsObject([
				"player" => $this->createPlayer($payload["player"] ?? []),
				"initialSpawn" => (bool) ($payload["initialSpawn"] ?? false)
			]),
			"playerLeave" => new JsObject([
				"playerId" => Interpreter::toString(self::dataField($payload["player"] ?? [], "id")),
				"playerName" => self::dataField($payload["player"] ?? [], "name")
			]),
			"entityDie" => new JsObject([
				"deadEntity" => $this->createPlayer($payload["deadEntity"] ?? []),
				"damageSource" => $this->createDamageSource($payload["damageSource"] ?? null)
			]),
			"chatSend" => new JsObject([
				"message" => Interpreter::toString($payload["message"] ?? ""),
				"sender" => $this->createPlayer($payload["sender"] ?? []),
				"cancel" => false,
				"sendToPlayers" => true
			]),
			default => Builtins::fromNative($payload)
		};
	}

	/**
	 * Delivers an event to its subscribers, returning the (possibly mutated) event object.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function dispatchEvent(string $name, array $payload, bool $cancelable) : mixed{
		$subscribers = $cancelable ? ($this->beforeEvents[$name] ?? []) : ($this->afterEvents[$name] ?? []);
		if($subscribers === []){
			return null;
		}

		$eventObject = $this->createEventObject($name, $payload);
		foreach($subscribers as $callback){
			try{
				(new Interpreter())->invokeFunction($callback, [$eventObject], null);
			}catch(\Throwable $error){
				$this->logError("Uncaught error in a \"" . $name . "\" script handler: " . $error->getMessage());
			}
		}

		if($name === "chatSend" && $cancelable && $eventObject instanceof JsObject){
			if(Interpreter::truthy($eventObject->get("cancel"))){
				$this->callHost("event.cancel", [Interpreter::toString($payload["handle"] ?? "")]);
			}
			$message = $eventObject->get("message");
			if(\is_string($message) && $message !== Interpreter::toString($payload["message"] ?? "")){
				$this->callHost("event.setMessage", [Interpreter::toString($payload["handle"] ?? ""), $message]);
			}
		}

		return $eventObject;
	}

	// ---------------------------------------------------------------------------------------------------------
	// system
	// ---------------------------------------------------------------------------------------------------------

	private function createSystem() : JsObject{
		$system = new JsObject();
		$system->set("currentTick", 0);
		$system->set("run", Builtins::fn(function(array $arguments) : int{
			$callback = $arguments[0] ?? null;
			if($callback instanceof JsFunction){
				$this->timeouts[] = ["ticks" => 0, "callback" => $callback, "at" => $this->currentTick];
			}
			return 0;
		}, "run"));
		$system->set("runInterval", Builtins::fn(function(array $arguments) : int{
			$callback = $arguments[0] ?? null;
			$period = max((int) Interpreter::toNumber($arguments[1] ?? 1), 1);
			if($callback instanceof JsFunction){
				$this->intervals[] = ["ticks" => $period, "callback" => $callback, "next" => $this->currentTick + $period];
			}
			return 0;
		}, "runInterval"));
		$system->set("runTimeout", Builtins::fn(function(array $arguments) : int{
			$callback = $arguments[0] ?? null;
			$delay = max((int) Interpreter::toNumber($arguments[1] ?? 0), 0);
			if($callback instanceof JsFunction){
				$this->timeouts[] = ["ticks" => $delay, "callback" => $callback, "at" => $this->currentTick + $delay];
			}
			return 0;
		}, "runTimeout"));
		$system->set("clearRun", Builtins::fn(function(array $arguments) : null{
			$id = $arguments[0] ?? null;
			return null;
		}, "clearRun"));
		$system->set("runJob", Builtins::fn(fn(array $arguments) : null => null, "runJob"));
		$system->set("beforeEvents", new JsObject());
		$system->set("afterEvents", new JsObject(["scriptTick" => $this->createSignal("scriptTick", false)]));
		return $system;
	}

	/**
	 * Advances the scheduler by one tick, running due intervals and timeouts.
	 */
	public function tick() : void{
		$this->currentTick++;
		$system = $this->getWorld()->has("system") ? null : null;

		foreach($this->intervals as $index => $interval){
			if($this->currentTick >= $interval["next"]){
				$this->intervals[$index]["next"] = $this->currentTick + $interval["ticks"];
				$this->runCallback($interval["callback"], [], "runInterval");
			}
		}

		$remaining = [];
		foreach($this->timeouts as $timeout){
			if($this->currentTick >= $timeout["at"]){
				$this->runCallback($timeout["callback"], [], "runTimeout");
			}else{
				$remaining[] = $timeout;
			}
		}
		$this->timeouts = $remaining;

		foreach($this->afterEvents["scriptTick"] ?? [] as $callback){
			$this->runCallback($callback, [new JsObject(["currentTick" => $this->currentTick])], "scriptTick");
		}
	}

	/**
	 * @param list<mixed> $arguments
	 */
	private function runCallback(JsFunction $callback, array $arguments, string $context) : void{
		try{
			(new Interpreter())->invokeFunction($callback, $arguments, null);
		}catch(\Throwable $error){
			$this->logError("Uncaught error in a \"" . $context . "\" script handler: " . $error->getMessage());
		}
	}

	public function getCurrentTick() : int{
		return $this->currentTick;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Small helpers
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * @param list<mixed> $arguments
	 */
	private function callHost(string $name, array $arguments) : mixed{
		return ($this->hostCall)($name, $arguments);
	}

	private function logError(string $message) : void{
		($this->hostCall)("system.logError", [$message]);
	}

	private static function createVector(mixed $data) : JsObject{
		return new JsObject([
			"x" => self::numberField($data, "x"),
			"y" => self::numberField($data, "y"),
			"z" => self::numberField($data, "z")
		]);
	}

	/**
	 * @return array{x: float, y: float, z: float}
	 */
	private static function coordinates(mixed $value) : array{
		if($value instanceof JsObject){
			return [
				"x" => (float) Interpreter::toNumber($value->get("x")),
				"y" => (float) Interpreter::toNumber($value->get("y")),
				"z" => (float) Interpreter::toNumber($value->get("z"))
			];
		}
		if(\is_array($value)){
			return [
				"x" => (float) Interpreter::toNumber($value["x"] ?? 0),
				"y" => (float) Interpreter::toNumber($value["y"] ?? 0),
				"z" => (float) Interpreter::toNumber($value["z"] ?? 0)
			];
		}
		return ["x" => 0.0, "y" => 0.0, "z" => 0.0];
	}

	/**
	 * @return array{typeId: string}
	 */
	private static function permutationToBlockState(mixed $permutation) : array{
		if($permutation instanceof JsObject){
			$typeId = Interpreter::toString($permutation->get("typeId"));
			return ["typeId" => $typeId];
		}
		return ["typeId" => Interpreter::toString($permutation)];
	}

	private static function messageToString(mixed $message) : string{
		if($message instanceof JsObject || $message instanceof JsArray){
			$encoded = \json_encode(Builtins::toNative($message), \JSON_UNESCAPED_SLASHES);
			return $encoded === false ? "" : $encoded;
		}
		return Interpreter::toString($message);
	}

	private static function dimensionId(mixed $value) : string{
		if($value === null || $value instanceof JsUndefined){
			return "minecraft:overworld";
		}
		if($value instanceof JsObject){
			$id = $value->get("id");
			return $id instanceof JsUndefined ? "minecraft:overworld" : Interpreter::toString($id);
		}
		$text = Interpreter::toString($value);
		return $text === "" || $text === "null" ? "minecraft:overworld" : $text;
	}

	private function createDamageSource(mixed $payload) : JsObject{
		return new JsObject([
			"cause" => Interpreter::toString(self::dataField($payload, "cause") ?? "none"),
			"damagingEntity" => JsUndefined::instance()
		]);
	}

	private static function dataField(mixed $data, string $name) : mixed{
		if($data instanceof JsObject){
			return $data->get($name);
		}
		if(\is_array($data)){
			return $data[$name] ?? null;
		}
		return null;
	}

	private static function numberField(mixed $data, string $name) : mixed{
		$value = self::dataField($data, $name);
		return $value === null ? 0 : $value;
	}

	/**
	 * @return list<mixed>
	 */
	private static function listOf(mixed $data) : array{
		if($data instanceof JsArray){
			return $data->elements;
		}
		if(\is_array($data)){
			return \array_values($data);
		}
		return [];
	}

	private function createBlock(mixed $data) : JsObject{
		$block = new JsObject();
		$block->set("typeId", self::dataField($data, "typeId") ?? "minecraft:air");
		$block->set("location", self::createVector(self::dataField($data, "location")));
		$block->set("isAir", Interpreter::truthy(self::dataField($data, "isAir")));
		$block->set("isLiquid", Interpreter::truthy(self::dataField($data, "isLiquid")));
		$block->set("isSolid", Interpreter::truthy(self::dataField($data, "isSolid")));
		$block->set("permutation", new JsObject(["typeId" => self::dataField($data, "typeId") ?? "minecraft:air"]));
		return $block;
	}

	private function createBlockPermutation() : JsFunction{
		$constructor = new JsFunction(name: "BlockPermutation");
		$constructor->set("resolve", Builtins::fn(static function(array $arguments) : JsObject{
			$typeId = Interpreter::toString($arguments[0] ?? "");
			$states = $arguments[1] ?? null;
			$permutation = new JsObject([
				"typeId" => $typeId,
				"__nativeString" => Builtins::fn(static fn(array $a) : string => $typeId)
			]);
			if($states instanceof JsObject){
				$permutation->set("states", $states);
			}
			return $permutation;
		}, "resolve"));
		return $constructor;
	}

	private function createDamageCause() : JsObject{
		$cause = new JsObject();
		foreach([
			"anvil", "blockExplosion", "campfire", "charging", "contact", "drowning", "entityAttack",
			"entityExplosion", "fall", "fallingBlock", "fire", "fireTick", "fireworks", "flyIntoWall",
			"freezing", "lava", "lightning", "magic", "magma", "none", "override", "piston", "projectile",
			"ramAttack", "selfDestruct", "sonicBoom", "soulCampfire", "stalactite", "stalagmite", "starve",
			"suffocation", "temperature", "thorns", "void", "wither"
		] as $name){
			$cause->set($name, $name);
		}
		return $cause;
	}

	private function createGameMode() : JsObject{
		return new JsObject([
			"survival" => "survival",
			"creative" => "creative",
			"adventure" => "adventure",
			"spectator" => "spectator"
		]);
	}

	private function createEquipmentSlot() : JsObject{
		return new JsObject([
			"Mainhand" => "Mainhand",
			"Offhand" => "Offhand",
			"Head" => "Head",
			"Chest" => "Chest",
			"Legs" => "Legs",
			"Feet" => "Feet"
		]);
	}
}
