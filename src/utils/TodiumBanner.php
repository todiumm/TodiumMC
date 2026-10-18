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
 * @link https://github.com/todiumofficial
 */

declare(strict_types=1);

namespace pocketmine\utils;

use function implode;
use const PHP_EOL;

/**
 * The Todium wordmark, printed once when the server starts.
 *
 * The art lives in string literals rather than a resource file because the server is normally distributed as a phar,
 * where reading a data file would need extra plumbing for no benefit.
 */
final class TodiumBanner{
	private const LOGO = [
		' _____         _ _                 ',
		'|_   _|__   __| (_)_   _ _ __ ___  ',
		'  | |/ _ \\ / _` | | | | | \'_ ` _ \\ ',
		'  | | (_) | (_| | | |_| | | | | | |',
		'  |_|\\___/ \\__,_|_|\\__,_|_| |_| |_|'
	];

	private function __construct(){
		//NOOP
	}

	/**
	 * @return list<string>
	 */
	public static function getLines() : array{
		return self::LOGO;
	}

	public static function getText() : string{
		return implode(PHP_EOL, self::LOGO);
	}

	/**
	 * The wordmark wrapped in the console's accent colour, ready to be written to the terminal.
	 */
	public static function getColoredText() : string{
		return TextFormat::GREEN . self::getText() . TextFormat::RESET;
	}
}
