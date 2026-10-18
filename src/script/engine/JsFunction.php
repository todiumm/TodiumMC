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
 * A callable value: either a script function (an AST plus its captured scope) or a native PHP function.
 */
final class JsFunction extends JsObject{

	/**
	 * @param list<Node> $params
	 * @param \Closure(list<mixed>, ?JsObject, Interpreter) : mixed $native
	 */
	public function __construct(
		public readonly string $name = "",
		public readonly ?Node $body = null,
		public readonly array $params = [],
		public readonly ?Scope $scope = null,
		public readonly ?Closure $native = null,
		public readonly bool $arrow = false,
		public readonly bool $async = false,
		public readonly ?Node $expressionBody = null
	){
		parent::__construct();
	}

	public function isNative() : bool{
		return $this->native !== null;
	}
}
