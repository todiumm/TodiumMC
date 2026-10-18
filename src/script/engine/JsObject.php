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

/**
 * A JavaScript object: a string-keyed property map with an optional prototype.
 */
class JsObject{

	/** @var array<string, mixed> */
	public array $properties = [];

	/**
	 * Computed properties, mirroring JavaScript getters: reading the name calls the closure.
	 *
	 * @var array<string, \Closure() : mixed>
	 */
	public array $getters = [];

	public ?JsObject $prototype = null;

	/** Marks the receiver that `this` refers to when a method is extracted from this object. */
	public ?JsObject $thisBinding = null;

	/**
	 * @param array<string, mixed> $properties
	 */
	public function __construct(array $properties = [], ?JsObject $prototype = null){
		$this->properties = $properties;
		$this->prototype = $prototype;
	}

	public function has(string $name) : bool{
		if(array_key_exists($name, $this->properties) || isset($this->getters[$name])){
			return true;
		}
		return $this->prototype?->has($name) ?? false;
	}

	public function get(string $name) : mixed{
		if(isset($this->getters[$name])){
			return ($this->getters[$name])();
		}
		if(array_key_exists($name, $this->properties)){
			return $this->properties[$name];
		}
		return $this->prototype?->get($name) ?? JsUndefined::instance();
	}

	/**
	 * Registers a computed property, mirroring a JavaScript getter.
	 *
	 * @param \Closure() : mixed $getter
	 */
	public function defineGetter(string $name, \Closure $getter) : void{
		$this->getters[$name] = $getter;
	}

	public function set(string $name, mixed $value) : void{
		$this->properties[$name] = $value;
	}

	public function delete(string $name) : bool{
		if(array_key_exists($name, $this->properties)){
			unset($this->properties[$name]);
			return true;
		}
		return true;
	}

	/**
	 * @return list<string>
	 */
	public function keys() : array{
		$keys = [];
		foreach(Utils::stringifyKeys($this->properties) as $key => $_){ $keys[] = $key; }
		if($this->prototype !== null){
			foreach($this->prototype->keys() as $key){
				if(!array_key_exists($key, $this->properties)){
					$keys[] = $key;
				}
			}
		}
		return $keys;
	}
}
