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

use Ahc\Json\Comment as CommentedJsonDecoder;
use pocketmine\behaviorpacks\json\BehaviorManifest;
use pocketmine\behaviorpacks\json\BehaviorManifestModule;
use pocketmine\utils\Utils;
use Ramsey\Uuid\Uuid;
use function array_filter;
use function array_map;
use function array_values;
use function assert;
use function ctype_digit;
use function explode;
use function fclose;
use function feof;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function gettype;
use function hash_file;
use function implode;
use function in_array;
use function is_array;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function preg_match;
use function property_exists;
use function str_ends_with;
use function str_replace;
use function strlen;
use function strtolower;
use function trim;

/**
 * A behaviour pack stored as a zip archive (.mcpack or .zip).
 */
class ZippedBehaviorPack implements BehaviorPack{
	protected string $path;
	protected BehaviorManifest $manifest;

	/** @var mixed[] */
	protected array $manifestData;

	protected ?string $sha256 = null;

	/** @var resource */
	protected $fileResource;

	/**
	 * @throws BehaviorPackException
	 */
	public function __construct(string $zipPath){
		$this->path = $zipPath;

		if(!file_exists($zipPath)){
			throw new BehaviorPackException("File not found");
		}
		$size = filesize($zipPath);
		if($size === false){
			throw new BehaviorPackException("Unable to determine size of file");
		}
		if($size === 0){
			throw new BehaviorPackException("Empty file, probably corrupted");
		}

		$archive = new \ZipArchive();
		if(($openResult = $archive->open($zipPath)) !== true){
			throw new BehaviorPackException("Encountered ZipArchive error code $openResult while trying to open $zipPath");
		}

		$manifestData = $this->extractManifest($archive);
		$archive->close();

		[$manifest, $rawData] = self::parseManifest($manifestData);

		$this->manifest = $manifest;
		$this->manifestData = $rawData;

		$this->fileResource = Utils::assumeNotFalse(fopen($zipPath, "rb"), "Failed to open the pack archive");
	}

	/**
	 * Finds and returns the contents of manifest.json inside the archive, allowing it to be nested one level deep.
	 */
	protected function extractManifest(\ZipArchive $archive) : string{
		if(($manifestData = $archive->getFromName("manifest.json")) !== false){
			return $manifestData;
		}

		$manifestPath = null;
		$manifestIdx = null;
		for($i = 0; $i < $archive->numFiles; ++$i){
			$name = Utils::assumeNotFalse($archive->getNameIndex($i), "This index should be valid");
			if(
				($manifestPath === null || strlen($name) < strlen($manifestPath)) &&
				preg_match('#.*/manifest.json$#', $name) === 1
			){
				$manifestPath = $name;
				$manifestIdx = $i;
			}
		}
		if($manifestIdx !== null){
			$manifestData = $archive->getFromIndex($manifestIdx);
			assert($manifestData !== false);
			return $manifestData;
		}
		if($archive->locateName("pack_manifest.json") !== false){
			throw new BehaviorPackException("Unsupported old pack format");
		}

		throw new BehaviorPackException("manifest.json not found in the archive root");
	}

	/**
	 * @return array{BehaviorManifest, mixed[]}
	 * @throws BehaviorPackException
	 */
	protected static function parseManifest(string $manifestData) : array{
		//maybe comments in the json, use stripped decoder (thanks mojang)
		try{
			$manifest = (new CommentedJsonDecoder())->decode($manifestData);
		}catch(\RuntimeException $e){
			throw new BehaviorPackException("Failed to parse manifest.json: " . $e->getMessage(), 0, $e);
		}
		if(!($manifest instanceof \stdClass)){
			throw new BehaviorPackException("manifest.json should contain a JSON object, not " . gettype($manifest));
		}

		$encoded = json_encode($manifest);
		if($encoded === false){
			throw new BehaviorPackException("Failed to normalise manifest.json");
		}
		$rawData = json_decode($encoded, true);
		if(!is_array($rawData)){
			throw new BehaviorPackException("Failed to normalise manifest.json");
		}

		//Bedrock allows versions to be written as strings ("1.0.0") as well as arrays ([1, 0, 0]); normalise to arrays
		self::normaliseManifestVersions($manifest);

		$mapper = new \JsonMapper();
		$mapper->bExceptionOnMissingData = true;
		$mapper->bStrictObjectTypeChecking = true;

		try{
			/** @var BehaviorManifest $manifest */
			$manifest = $mapper->map($manifest, new BehaviorManifest());
		}catch(\JsonMapper_Exception $e){
			throw new BehaviorPackException("Invalid manifest.json contents: " . $e->getMessage(), 0, $e);
		}
		if(!Uuid::isValid($manifest->header->uuid)){
			throw new BehaviorPackException("Behaviour pack has an invalid UUID");
		}

		return [$manifest, $rawData];
	}

	/**
	 * Rewrites every "version" field the manifest may carry into the array form JsonMapper expects. Bedrock accepts
	 * both the array form ([1, 0, 0]) and the string form ("1.0.0"), and rejects manifests that use neither.
	 */
	private static function normaliseManifestVersions(\stdClass $manifest) : void{
		$versioned = [$manifest->header ?? null];
		foreach($manifest->modules ?? [] as $module){
			$versioned[] = $module;
		}
		foreach($manifest->dependencies ?? [] as $dependency){
			$versioned[] = $dependency;
		}
		foreach($versioned as $section){
			if(!($section instanceof \stdClass)){
				continue;
			}
			foreach(["version", "min_engine_version"] as $field){
				if(property_exists($section, $field)){
					$section->{$field} = self::toVersionArray($section->{$field});
				}
			}
		}
	}

	/**
	 * @return int[]
	 */
	private static function toVersionArray(mixed $version) : array{
		if(is_array($version)){
			$parts = [];
			foreach($version as $part){
				if(is_int($part)){
					$parts[] = $part;
				}elseif(is_string($part) && ctype_digit($part)){
					$parts[] = (int) $part;
				}else{
					throw new BehaviorPackException("Invalid version component in manifest.json");
				}
			}
			return $parts;
		}
		if(is_string($version)){
			$parts = [];
			foreach(explode(".", $version, 4) as $part){
				if($part === "" || !ctype_digit($part)){
					throw new BehaviorPackException("Invalid version \"$version\" in manifest.json");
				}
				$parts[] = (int) $part;
			}
			return $parts;
		}
		throw new BehaviorPackException("Invalid version in manifest.json");
	}

	public function __destruct(){
		fclose($this->fileResource);
	}

	public function getPath() : string{
		return $this->path;
	}

	public function getPackName() : string{
		return $this->manifest->header->name;
	}

	public function getPackVersion() : string{
		return implode(".", $this->manifest->header->version);
	}

	public function getPackId() : string{
		return strtolower($this->manifest->header->uuid);
	}

	public function getPackSize() : int{
		return Utils::assumeNotFalse(filesize($this->path), "The archive was already checked to exist");
	}

	public function getSha256(bool $cached = true) : string{
		if($this->sha256 === null || !$cached){
			$this->sha256 = Utils::assumeNotFalse(hash_file("sha256", $this->path, true), "The archive was already checked to be readable");
		}
		return $this->sha256;
	}

	public function getPackChunk(int $start, int $length) : string{
		if($length < 1){
			throw new \InvalidArgumentException("Pack length must be positive");
		}
		fseek($this->fileResource, $start);
		if(feof($this->fileResource)){
			throw new \InvalidArgumentException("Requested a resource pack chunk with invalid start offset");
		}
		return Utils::assumeNotFalse(fread($this->fileResource, $length), "Already checked that we're not at EOF");
	}

	public function getManifestData() : array{
		return $this->manifestData;
	}

	/**
	 * @return BehaviorManifestModule[]
	 */
	public function getModules() : array{
		return $this->manifest->modules;
	}

	public function getScriptEntries() : array{
		$entries = [];
		foreach($this->manifest->modules as $module){
			$entry = $module->getScriptEntry();
			if($entry !== null){
				$entries[] = $entry;
			}
		}
		return $entries;
	}

	public function hasScripts() : bool{
		foreach($this->manifest->modules as $module){
			if($module->isScript()){
				return true;
			}
		}
		return false;
	}

	public function isAddonPack() : bool{
		if($this->hasModuleType(BehaviorPack::MODULE_TYPE_DATA) || $this->hasModuleType(BehaviorPack::MODULE_TYPE_SCRIPT)){
			return true;
		}
		$productType = $this->manifest->metadata?->product_type;
		return $productType !== null && strtolower($productType) === "addon";
	}

	public function readFile(string $relativePath) : ?string{
		$relativePath = self::normalisePath($relativePath);
		if($relativePath === ""){
			return null;
		}

		$archive = new \ZipArchive();
		if($archive->open($this->path) !== true){
			throw new BehaviorPackException("Failed to open pack archive " . $this->path);
		}
		try{
			$contents = $archive->getFromName($relativePath);
			if($contents === false){
				//packs are sometimes zipped with a single wrapping directory
				$contents = $archive->getFromName($this->findNested($archive, $relativePath));
			}
			return $contents === false ? null : $contents;
		}finally{
			$archive->close();
		}
	}

	private function findNested(\ZipArchive $archive, string $relativePath) : string{
		$suffix = "/" . $relativePath;
		for($i = 0; $i < $archive->numFiles; ++$i){
			$name = Utils::assumeNotFalse($archive->getNameIndex($i), "This index should be valid");
			if(strlen($name) > strlen($suffix) && str_ends_with($name, $suffix)){
				return $name;
			}
		}
		return $relativePath;
	}

	public function fileExists(string $relativePath) : bool{
		return $this->readFile($relativePath) !== null;
	}

	public function listFiles() : array{
		$archive = new \ZipArchive();
		if($archive->open($this->path) !== true){
			throw new BehaviorPackException("Failed to open pack archive " . $this->path);
		}
		try{
			$result = [];
			for($i = 0; $i < $archive->numFiles; ++$i){
				$name = Utils::assumeNotFalse($archive->getNameIndex($i), "This index should be valid");
				if(str_ends_with($name, "/")){
					continue;
				}
				$result[] = str_replace("\\", "/", $name);
			}
			return $result;
		}finally{
			$archive->close();
		}
	}

	/**
	 * @return array<int, array{string, string}>
	 */
	public function getDependencies() : array{
		$result = [];
		foreach($this->manifest->dependencies ?? [] as $dependency){
			//module dependencies (e.g. "@minecraft/server") have no UUID; they are reported under their name
			$id = $dependency->uuid !== null ? strtolower($dependency->uuid) : strtolower($dependency->module_name ?? "");
			if($id === ""){
				continue;
			}
			$result[] = [$id, implode(".", $dependency->version)];
		}
		return $result;
	}

	/**
	 * @return string[]
	 */
	public function getModuleTypes() : array{
		return array_values(array_filter(
			array_map(static fn(BehaviorManifestModule $module) => $module->type, $this->manifest->modules),
			static fn(string $type) => $type !== ""
		));
	}

	public function hasModuleType(string $type) : bool{
		return in_array($type, $this->getModuleTypes(), true);
	}

	/**
	 * Normalises a pack-relative path so that it can be looked up inside the archive. Rejects any attempt to escape the
	 * pack root.
	 */
	protected static function normalisePath(string $relativePath) : string{
		$relativePath = str_replace("\\", "/", trim($relativePath));
		$relativePath = ltrim($relativePath, "/");
		$parts = [];
		foreach(explode("/", $relativePath, 1024) as $part){
			if($part === "" || $part === "."){
				continue;
			}
			if($part === ".."){
				throw new BehaviorPackException("Path \"$relativePath\" escapes the behaviour pack root");
			}
			$parts[] = $part;
		}
		return implode("/", $parts);
	}
}
