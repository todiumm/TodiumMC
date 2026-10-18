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

use Closure;

/**
 * A JavaScript scope: a chain of string-keyed bindings.
 */
final class Scope{

	/** @var array<string, mixed> */
	private array $bindings = [];

	/** @var array<string, bool> */
	private array $constants = [];

	public function __construct(
		private readonly ?Scope $parent = null
	){}

	public function child() : self{
		return new self($this);
	}

	public function declare(string $name, mixed $value, bool $constant = false) : void{
		$this->bindings[$name] = $value;
		//a fresh declaration replaces any earlier binding, including its const-ness
		if($constant){
			$this->constants[$name] = true;
		}else{
			unset($this->constants[$name]);
		}
	}

	public function has(string $name) : bool{
		if(\array_key_exists($name, $this->bindings)){
			return true;
		}
		return $this->parent?->has($name) ?? false;
	}

	public function get(string $name) : mixed{
		if(\array_key_exists($name, $this->bindings)){
			return $this->bindings[$name];
		}
		if($this->parent !== null){
			return $this->parent->get($name);
		}
		return JsUndefined::instance();
	}

	public function isConstant(string $name) : bool{
		if(isset($this->constants[$name])){
			return true;
		}
		return $this->parent?->isConstant($name) ?? false;
	}

	/**
	 * Assigns to an existing binding, walking the scope chain. Returns false when the name is not declared.
	 */
	public function assign(string $name, mixed $value) : bool{
		if(\array_key_exists($name, $this->bindings)){
			$this->bindings[$name] = $value;
			return true;
		}
		return $this->parent?->assign($name, $value) ?? false;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function flatten() : array{
		$out = $this->parent?->flatten() ?? [];
		foreach(Utils::stringifyKeys($this->bindings) as $name => $value){
			$out[$name] = $value;
		}
		return $out;
	}
}
