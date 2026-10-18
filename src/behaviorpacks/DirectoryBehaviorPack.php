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

use pocketmine\utils\Filesystem;
use pocketmine\utils\Utils;
use Symfony\Component\Filesystem\Path;
use function array_keys;
use function fclose;
use function feof;
use function file_exists;
use function filesize;
use function fopen;
use function fread;
use function fseek;
use function hash_file;
use function is_dir;
use function is_file;
use function is_link;
use function is_readable;
use function ksort;
use function rtrim;
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function tempnam;

/**
 * A behaviour pack stored as an unpacked directory.
 *
 * Directory packs are convenient while developing, but the Bedrock client can only download a zip. This class therefore
 * builds a temporary archive of the directory on first use and serves that archive to clients, while all file reads
 * (including Script API entry points) are served directly from disk so that edits show up without a rebuild.
 */
class DirectoryBehaviorPack extends ZippedBehaviorPack{
	private string $sourceDirectory;
	private ?string $tempArchivePath = null;

	/**
	 * @throws BehaviorPackException
	 */
	public function __construct(string $directory){
		if(!is_dir($directory)){
			throw new BehaviorPackException("Directory not found");
		}
		$this->sourceDirectory = rtrim($directory, "/\\");

		if(!is_file(Path::join($this->sourceDirectory, "manifest.json"))){
			throw new BehaviorPackException("manifest.json not found in the pack root");
		}

		//the parent parses the manifest out of the generated archive; reads below still come straight from disk
		parent::__construct($this->getArchivePath());
	}

	private function getArchivePath() : string{
		if($this->tempArchivePath === null){
			$temp = tempnam(sys_get_temp_dir(), "todium_bp_");
			if($temp === false){
				throw new BehaviorPackException("Failed to create a temporary file for the generated pack archive");
			}
			self::zipDirectory($this->sourceDirectory, $temp);
			$this->tempArchivePath = $temp;
		}
		return $this->tempArchivePath;
	}

	/**
	 * @throws BehaviorPackException
	 */
	private static function zipDirectory(string $directory, string $outputPath) : void{
		$files = self::scanFiles($directory);

		$archive = new \ZipArchive();
		if($archive->open($outputPath, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true){
			throw new BehaviorPackException("Failed to create pack archive $outputPath");
		}
		try{
			foreach(Utils::stringifyKeys($files) as $relativePath => $absolutePath){
				$archive->addFile($absolutePath, $relativePath);
			}
		}finally{
			$archive->close();
		}
	}

	/**
	 * @return array<string, string> relative path => absolute path, sorted for deterministic archives
	 */
	private static function scanFiles(string $directory) : array{
		$result = [];
		$iterator = new \RecursiveIteratorIterator(
			new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS | \FilesystemIterator::CURRENT_AS_FILEINFO),
			\RecursiveIteratorIterator::SELF_FIRST
		);
		$baseLength = strlen($directory) + 1;
		/** @var \SplFileInfo $file */
		foreach($iterator as $file){
			if(is_link($file->getPathname())){
				continue;
			}
			if(!$file->isFile() || !is_readable($file->getPathname())){
				continue;
			}
			$name = $file->getFilename();
			if(str_ends_with($name, ".key")){
				continue;
			}
			$relative = str_replace("\\", "/", substr($file->getPathname(), $baseLength));
			$result[$relative] = $file->getPathname();
		}
		ksort($result, SORT_STRING);
		return $result;
	}

	public function getPath() : string{
		return $this->sourceDirectory;
	}

	public function getPackSize() : int{
		return Utils::assumeNotFalse(filesize($this->getArchivePath()), "The generated archive always exists at this point");
	}

	public function getSha256(bool $cached = true) : string{
		if($this->sha256 === null || !$cached){
			$this->sha256 = Utils::assumeNotFalse(hash_file("sha256", $this->getArchivePath(), true), "The generated archive is readable");
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

	public function readFile(string $relativePath) : ?string{
		$relativePath = self::normalisePath($relativePath);
		if($relativePath === ""){
			return null;
		}
		$absolute = Path::join($this->sourceDirectory, $relativePath);
		if(!is_file($absolute) || !is_readable($absolute)){
			return null;
		}
		return Filesystem::fileGetContents($absolute);
	}

	public function fileExists(string $relativePath) : bool{
		$relativePath = self::normalisePath($relativePath);
		return $relativePath !== "" && is_file(Path::join($this->sourceDirectory, $relativePath));
	}

	public function listFiles() : array{
		return array_keys(self::scanFiles($this->sourceDirectory));
	}

	public function __destruct(){
		fclose($this->fileResource);
		if($this->tempArchivePath !== null && file_exists($this->tempArchivePath)){
			@unlink($this->tempArchivePath);
		}
	}
}
