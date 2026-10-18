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

use pocketmine\utils\Utils;
use function array_key_exists;
use function dirname;
use function explode;
use function implode;
use function in_array;
use function is_string;
use function preg_replace;
use function str_contains;
use function str_ends_with;
use function str_starts_with;
use function strlen;
use function substr;
use function trim;

/**
 * Resolves and evaluates ES module imports against an in-memory module registry.
 *
 * Only the two Minecraft script modules and relative script paths exist inside a behaviour pack, so the resolver
 * stays deliberately small: it maps a specifier to a normalised path and evaluates each module once.
 */
final class ModuleLoader{

	/** @var array<string, JsObject> */
	private array $cache = [];
	/** @var array<string, bool> */
	private array $loading = [];

	/**
	 * @param array<string, string> $sources normalised path => source text
	 * @param array<string, JsObject> $builtinModules module specifier => namespace object
	 */
	public function __construct(
		private readonly array $sources,
		private readonly array $builtinModules = [],
		private readonly Interpreter $interpreter = new Interpreter()
	){}

	/**
	 * Loads a module by specifier, relative to the given importer path.
	 */
	public function load(string $specifier, string $importer = "") : JsObject{
		if(isset($this->builtinModules[$specifier])){
			return $this->builtinModules[$specifier];
		}

		$path = self::resolve($specifier, $importer);
		if(!array_key_exists($path, $this->sources)){
			throw new ScriptEngineException("Cannot find module \"" . $specifier . "\"");
		}
		return $this->loadPath($path);
	}

	public function loadPath(string $path) : JsObject{
		if(isset($this->cache[$path])){
			return $this->cache[$path];
		}
		if(isset($this->loading[$path])){
			//cyclic import: hand back the partially built namespace
			return $this->cache[$path] ?? new JsObject();
		}

		$this->loading[$path] = true;
		$namespace = new JsObject();
		$this->cache[$path] = $namespace;

		$scope = new Scope();
		foreach(Utils::stringifyKeys(Builtins::createGlobals()->properties) as $name => $value){
			$scope->declare($name, $value);
		}
		$scope->declare("require", Builtins::fn(function(array $arguments) use ($path) : JsObject{
			return $this->load(Interpreter::toString($arguments[0] ?? ""), $path);
		}, "require"));
		$scope->declare("@module", $namespace);
		$scope->declare("@path", $path);

		$program = (new Parser($this->sources[$path] ?? "", $path))->parse();

		//hoist exported declarations so imports see them even before the body runs
		$this->declareHoisted($program, $scope);
		$this->bindImports($program, $scope, $path);

		try{
			$this->interpreter->executeProgram($program, $scope);
		}finally{
			unset($this->loading[$path]);
		}

		//materialise the exported names onto the namespace object
		foreach(Utils::stringifyKeys($this->collectExports($program)) as $local => $exported){
			$namespace->set($exported, $scope->get($local));
		}

		return $namespace;
	}

	private function declareHoisted(Node $program, Scope $scope) : void{
		foreach($program->childList("body") as $statement){
			if($statement->kind === "functionDecl"){
				$this->interpreter->executeStatementPublic($statement, $scope);
			}elseif($statement->kind === "exportDecl"){
				$declaration = $statement->child("declaration");
				if($declaration !== null && $declaration->kind === "functionDecl"){
					$this->interpreter->executeStatementPublic($declaration, $scope);
				}
			}
		}
	}

	/**
	 * Resolves every import in the module and declares the requested bindings in its scope.
	 */
	private function bindImports(Node $program, Scope $scope, string $path) : void{
		foreach($program->childList("body") as $statement){
			if($statement->kind !== "importDecl"){
				continue;
			}
			$source = $statement->stringField("source");
			$module = $this->load($source, $path);

			$specifiers = $statement->recordList("specifiers");
			if($specifiers === []){
				continue;
			}
			foreach($specifiers as $specifier){
				$imported = $specifier["imported"] ?? "";
				$imported = \is_string($imported) ? $imported : "";
				$local = $specifier["local"] ?? "";
				$local = \is_string($local) ? $local : "";
				if($local === ""){
					continue;
				}
				$value = match($imported){
					"*" => $module,
					"default" => $module->get("default"),
					default => $module->get($imported)
				};
				$scope->declare($local, $value, true);
			}
		}
	}

	/**
	 * @return array<string, string> local name => exported name
	 */
	private function collectExports(Node $program) : array{
		$exports = [];
		foreach($program->childList("body") as $statement){
			if($statement->kind !== "exportDecl"){
				continue;
			}
			$declaration = $statement->child("declaration");
			if($declaration !== null){
				if($declaration->kind === "varDecl"){
					foreach($declaration->recordList("declarations") as $entry){
						$id = $entry["id"] ?? null;
						if($id instanceof Node && $id->kind === "identifier"){
							$name = $id->stringField("name");
							$exports[$name] = $name;
						}
					}
				}elseif($declaration->kind === "functionDecl" || $declaration->kind === "classDecl"){
					$id = $declaration->child("id");
					if($id !== null){
						$name = $id->stringField("name");
						$exports[$name] = $name;
					}
				}
				continue;
			}

			foreach($statement->recordList("specifiers") as $specifier){
				$local = $specifier["local"] ?? null;
				$exported = $specifier["exported"] ?? null;
				if(\is_string($local) && \is_string($exported)){
					$exports[$local] = $exported;
				}
			}
		}
		return $exports;
	}

	/**
	 * Normalises a module specifier to a registry path. Unresolvable specifiers are returned unchanged so the
	 * caller can report the missing module.
	 */
	public static function resolve(string $specifier, string $importer) : string{
		if(str_starts_with($specifier, "./") || str_starts_with($specifier, "../")){
			$base = $importer === "" ? "" : dirname($importer);
			$path = self::normalise($base === "." ? $specifier : $base . "/" . $specifier);
			return self::candidatePaths($path)[0];
		}
		if(str_starts_with($specifier, "/")){
			return self::normalise(substr($specifier, 1));
		}
		return $specifier;
	}

	/**
	 * @return list<string>
	 */
	private static function candidatePaths(string $path) : array{
		return [$path, $path . ".js", $path . ".mjs", $path . "/index.js"];
	}

	private static function normalise(string $path) : string{
		$path = preg_replace('#/+#', "/", $path) ?? $path;
		$parts = explode("/", $path, 1024);
		$out = [];
		foreach($parts as $part){
			if($part === "." || $part === ""){
				continue;
			}
			if($part === ".."){
				\array_pop($out);
				continue;
			}
			$out[] = $part;
		}
		return implode("/", $out);
	}
}
