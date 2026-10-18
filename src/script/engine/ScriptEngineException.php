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

namespace pocketmine\script\engine;

/**
 * Raised for any error while running a behaviour pack script.
 */
final class ScriptEngineException extends \RuntimeException{

	public function __construct(
		string $message,
		public readonly int $scriptLine = 0,
		public readonly int $scriptColumn = 0,
		public readonly string $scriptName = ""
	){
		$location = $scriptName !== "" ? $scriptName : "<script>";
		if($scriptLine > 0){
			$location .= ":" . $scriptLine . ":" . $scriptColumn;
		}
		parent::__construct($location . ": " . $message);
	}
}
