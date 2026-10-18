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

use function count;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;

/**
 * A JavaScript array. Values are kept in a plain list so arrays behave as scripts expect.
 */
final class JsArray{

	/** @var list<mixed> */
	public array $elements = [];

	/**
	 * @param list<mixed> $elements
	 */
	public function __construct(array $elements = []){
		$this->elements = $elements;
	}

	public function length() : int{
		return count($this->elements);
	}
}
