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
use function str_ends_with;
use function str_replace;
use function strlen;
use function substr;
use function trim;

/**
 * Runs behaviour pack scripts inside the server process.
 *
 * The engine owns the module registry and the `@minecraft/server` surface, so no external JavaScript runtime is
 * needed: a pack's scripts are parsed, linked and executed on the server's own thread, and events are delivered
 * straight to the subscribers they registered.
 */
final class ScriptEngine{

	private ?ServerApi $api = null;

	private string $lastError = "";
	private bool $started = false;

	/**
	 * @param Closure(string, list<mixed>) : mixed $hostCall dispatches a dotted host function call
	 */
	public function __construct(
		private readonly Closure $hostCall,
		private readonly string $serverName = "Todium",
		private readonly string $minecraftVersion = ""
	){}

	/**
	 * Loads the given entry points. Sources are keyed by their path inside the pack tree.
	 *
	 * @param array<string, string> $sources
	 * @param list<string> $entryPoints
	 */
	public function start(array $sources, array $entryPoints) : void{
		Builtins::setLogger(function(string $level, string $message) : void{
			($this->hostCall)("system.log", [$level, $message]);
		});

		$this->api = new ServerApi($this->hostCall, $this->serverName, $this->minecraftVersion);

		$builtinModules = [
			"@minecraft/server" => $this->api->createModule(),
			"@minecraft/server-ui" => $this->createUiModule(),
			"server" => $this->api->createModule()
		];

		$loader = new ModuleLoader($sources, $builtinModules);
		$this->started = true;

		foreach($entryPoints as $entry){
			try{
				$loader->loadPath($entry);
			}catch(\Throwable $error){
				$this->lastError = $error->getMessage();
				($this->hostCall)("system.logError", ["Failed to load script \"" . $entry . "\": " . $error->getMessage()]);
			}
		}
	}

	public function isRunning() : bool{
		return $this->started;
	}

	public function getLastError() : string{
		return $this->lastError;
	}

	public function getApi() : ?ServerApi{
		return $this->api;
	}

	/**
	 * Advances the script scheduler by one server tick.
	 */
	public function tick() : void{
		$this->api?->tick();
	}

	/**
	 * Delivers an event to the scripts.
	 *
	 * @param array<string, mixed> $payload
	 */
	public function dispatchEvent(string $name, array $payload, bool $cancelable) : void{
		$this->api?->dispatchEvent($name, $payload, $cancelable);
	}

	private function createUiModule() : JsObject{
		$module = new JsObject();

		$module->set("MessageFormData", new JsFunction(name: "MessageFormData", native: static function(array $arguments) : JsObject{
			$form = new JsObject();
			$form->set("title", Builtins::fn(static fn(array $a) : JsObject => $form));
			$form->set("body", Builtins::fn(static fn(array $a) : JsObject => $form));
			$form->set("button1", Builtins::fn(static fn(array $a) : JsObject => $form));
			$form->set("button2", Builtins::fn(static fn(array $a) : JsObject => $form));
			return $form;
		}));

		$module->set("ActionFormData", new JsFunction(name: "ActionFormData", native: static function(array $arguments) : JsObject{
			$form = new JsObject();
			$form->set("title", Builtins::fn(static fn(array $a) : JsObject => $form));
			$form->set("body", Builtins::fn(static fn(array $a) : JsObject => $form));
			$form->set("button", Builtins::fn(static fn(array $a) : JsObject => $form));
			return $form;
		}));

		$module->set("showForm", new JsFunction(name: "showForm", native: static fn(array $arguments) : JsObject => new JsObject([
			"canceled" => true
		])));

		return $module;
	}
}
