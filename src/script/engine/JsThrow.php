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

/**
 * A thrown JavaScript value, kept as-is so scripts can catch anything.
 */
final class JsThrow extends \Exception{

	public function __construct(public readonly mixed $value){
		parent::__construct(self::describe($value));
	}

	/**
	 * Builds a readable message from whatever the script threw, so the console shows the real cause instead of a
	 * generic "Uncaught JavaScript exception".
	 */
	private static function describe(mixed $value) : string{
		if($value instanceof JsObject){
			$name = Interpreter::toString($value->get("name"));
			$message = Interpreter::toString($value->get("message"));
			if($message !== "undefined" && $message !== ""){
				return ($name !== "undefined" && $name !== "" ? $name . ": " : "") . $message;
			}
			$stack = Interpreter::toString($value->get("stack"));
			if($stack !== "undefined" && $stack !== ""){
				return $stack;
			}
			return "Uncaught JavaScript exception (object)";
		}
		return "Uncaught " . Interpreter::toString($value);
	}
}
