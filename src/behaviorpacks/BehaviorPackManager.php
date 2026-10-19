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

namespace pocketmine\behaviorpacks;

use pocketmine\utils\Config;
use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function array_values;
use function copy;
use function count;
use function file_exists;
use function gettype;
use function implode;
use function in_array;
use function is_array;
use function is_dir;
use function is_float;
use function is_int;
use function is_string;
use function mkdir;
use function pathinfo;
use function rtrim;
use function scandir;
use function strlen;
use function strtolower;
use const DIRECTORY_SEPARATOR;
use const PATHINFO_EXTENSION;

/**
 * Loads and keeps track of the behaviour packs available on the server.
 *
 * Behaviour packs are offered to clients alongside resource packs, but the script modules they declare are executed
 * server-side by the Script API runtime rather than being sent to the client for execution.
 */
class BehaviorPackManager{

	/** Name of the stack key in behavior_packs.yml, kept parallel with resource_packs.yml. */
	private const STACK_KEY = "behavior_stack";
	private const FORCE_KEY = "force_packs";

	private string $path;
	private bool $serverForcePacks = false;

	/**
	 * @var BehaviorPack[]
	 * @phpstan-var list<BehaviorPack>
	 */
	private array $behaviorPacks = [];

	/**
	 * @var BehaviorPack[]
	 * @phpstan-var array<string, BehaviorPack>
	 */
	private array $uuidList = [];

	/**
	 * @var string[]
	 * @phpstan-var array<string, string>
	 */
	private array $encryptionKeys = [];

	/**
	 * @param string $path Path to the behavior-packs directory.
	 */
	public function __construct(string $path, \Logger $logger){
		$this->path = $path;

		if(!file_exists($this->path)){
			$logger->debug("Behaviour packs path $path does not exist, creating directory");
			mkdir($this->path, 0777, true);
		}elseif(!is_dir($this->path)){
			throw new \InvalidArgumentException("Behaviour packs path $path exists and is not a directory");
		}

		$behaviorPacksYml = Path::join($this->path, "behavior_packs.yml");
		if(!file_exists($behaviorPacksYml)){
			copy(Path::join(\pocketmine\RESOURCE_PATH, "behavior_packs.yml"), $behaviorPacksYml);
		}

		$config = new Config($behaviorPacksYml, Config::YAML, []);

		$this->serverForcePacks = (bool) $config->get(self::FORCE_KEY, false);

		$logger->info("Loading behaviour packs...");

		$stack = $config->get(self::STACK_KEY, []);
		if(!is_array($stack)){
			throw new \InvalidArgumentException("\"" . self::STACK_KEY . "\" key should contain a list of pack names");
		}

		foreach(Utils::promoteKeys($stack) as $pos => $pack){
			if(!is_string($pack) && !is_int($pack) && !is_float($pack)){
				$logger->critical("Found invalid entry in behaviour pack list at offset $pos of type " . gettype($pack));
				continue;
			}
			$this->registerPackFromName((string) $pack, $logger);
		}

		//auto-discover packs dropped in the folder but missing from behavior_packs.yml
		$listed = [];
		foreach($stack as $entry){
			if(is_string($entry) || is_int($entry) || is_float($entry)){
				$listed[(string) $entry] = true;
			}
		}
		$discovered = scandir($this->path);
		foreach($discovered === false ? [] : $discovered as $name){
			if($name === "." || $name === ".." || $name[0] === "." || isset($listed[$name])){
				continue;
			}
			$full = Path::join($this->path, $name);
			$extension = strtolower(pathinfo($name, PATHINFO_EXTENSION));
			if(!is_dir($full) && !in_array($extension, ["zip", "mcpack", "mcaddon"], true)){
				continue; //behavior_packs.yml, .key files, etc.
			}
			if($this->registerPackFromName($name, $logger)){
				$logger->info("Auto-discovered behaviour pack \"$name\" (add it to behavior_packs.yml to control its order)");
			}
		}

		$logger->debug("Successfully loaded " . count($this->behaviorPacks) . " behaviour packs");
	}

	/**
	 * Loads one pack from the packs folder and registers it, logging (not throwing) on failure.
	 *
	 * @return bool whether the pack was registered
	 */
	private function registerPackFromName(string $pack, \Logger $logger) : bool{
		try{
			$newPack = $this->loadPackFromPath(Path::join($this->path, $pack));

			$index = strtolower($newPack->getPackId());
			if(!Uuid::isValid($index)){
				throw new BehaviorPackException("Invalid UUID ($index)");
			}
			if(isset($this->uuidList[$index])){
				throw new BehaviorPackException("Another behaviour pack with the same UUID is already loaded");
			}

			$keyPath = Path::join($this->path, $pack . ".key");
			$key = null;
			if(file_exists($keyPath)){
				try{
					$key = Filesystem::fileGetContents($keyPath);
				}catch(\RuntimeException $e){
					throw new BehaviorPackException("Could not read encryption key file: " . $e->getMessage(), 0, $e);
				}
				$key = rtrim($key, "\r\n");
				if(strlen($key) !== 32){
					throw new BehaviorPackException("Invalid encryption key length, must be exactly 32 bytes");
				}
			}

			//only register once every check passed, so a broken pack never half-loads
			$this->uuidList[$index] = $newPack;
			$this->behaviorPacks[] = $newPack;
			if($key !== null){
				$this->encryptionKeys[$index] = $key;
			}

			$logger->info("Loaded behaviour pack \"" . $newPack->getPackName() . "\" (" . $newPack->getPackId() . ")");
			if($newPack->hasScripts()){
				$logger->info("Behaviour pack \"" . $newPack->getPackName() . "\" provides " . count($newPack->getScriptEntries()) . " script module(s): " . implode(", ", $newPack->getScriptEntries()));
			}
			return true;
		}catch(BehaviorPackException $e){
			$logger->critical("Could not load behaviour pack \"$pack\": " . $e->getMessage());
			return false;
		}
	}

	private function loadPackFromPath(string $packPath) : BehaviorPack{
		if(!file_exists($packPath)){
			throw new BehaviorPackException("File or directory not found");
		}

		if(is_dir($packPath)){
			return new DirectoryBehaviorPack($packPath);
		}

		$info = new \SplFileInfo($packPath);
		switch($info->getExtension()){
			case "zip":
			case "mcpack":
			case "mcaddon":
				return new ZippedBehaviorPack($packPath);
		}

		throw new BehaviorPackException("Format not recognized");
	}

	/**
	 * Returns the directory which behaviour packs are loaded from.
	 */
	public function getPath() : string{
		return $this->path . DIRECTORY_SEPARATOR;
	}

	/**
	 * Returns whether players must accept behaviour packs in order to join.
	 */
	public function packsRequired() : bool{
		return $this->serverForcePacks;
	}

	public function setPacksRequired(bool $value) : void{
		$this->serverForcePacks = $value;
	}

	/**
	 * Returns the behaviour packs in use, sorted in order of priority.
	 *
	 * @return BehaviorPack[]
	 * @phpstan-return list<BehaviorPack>
	 */
	public function getBehaviorStack() : array{
		return $this->behaviorPacks;
	}

	/**
	 * @param BehaviorPack[] $behaviorStack
	 * @phpstan-param list<BehaviorPack> $behaviorStack
	 */
	public function setBehaviorStack(array $behaviorStack) : void{
		$uuidList = [];
		foreach($behaviorStack as $pack){
			$uuid = strtolower($pack->getPackId());
			if(!Uuid::isValid($uuid)){
				throw new \InvalidArgumentException("Invalid behaviour pack UUID ($uuid)");
			}
			if(isset($uuidList[$uuid])){
				throw new \InvalidArgumentException("Cannot load two behaviour packs with the same UUID ($uuid)");
			}
			$uuidList[$uuid] = $pack;
		}
		$this->behaviorPacks = array_values($behaviorStack);
		$this->uuidList = $uuidList;
	}

	/**
	 * Returns every loaded behaviour pack that declares at least one script module, in stack order.
	 *
	 * @return BehaviorPack[]
	 * @phpstan-return list<BehaviorPack>
	 */
	public function getScriptPacks() : array{
		$result = [];
		foreach($this->behaviorPacks as $pack){
			if($pack->hasScripts()){
				$result[] = $pack;
			}
		}
		return $result;
	}

	public function getPackById(string $id) : ?BehaviorPack{
		return $this->uuidList[strtolower($id)] ?? null;
	}

	/**
	 * @return string[]
	 */
	public function getPackIdList() : array{
		return array_keys($this->uuidList);
	}

	public function getPackEncryptionKey(string $id) : ?string{
		return $this->encryptionKeys[strtolower($id)] ?? null;
	}

	public function setPackEncryptionKey(string $id, ?string $key) : void{
		$id = strtolower($id);
		if($key === null){
			unset($this->encryptionKeys[$id]);
		}elseif(isset($this->uuidList[$id])){
			if(strlen($key) !== 32){
				throw new \InvalidArgumentException("Encryption key must be exactly 32 bytes long");
			}
			$this->encryptionKeys[$id] = $key;
		}else{
			throw new \InvalidArgumentException("Unknown pack ID $id");
		}
	}
}
