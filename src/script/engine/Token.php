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
 * A single lexical token. Template literals carry their parsed parts; regular expression literals carry their
 * pattern and flags; everything else carries its literal value.
 */
final class Token{

	public const NUMBER = "number";
	public const STRING = "string";
	public const TEMPLATE = "template";
	public const REGEX = "regex";
	public const IDENTIFIER = "identifier";
	public const KEYWORD = "keyword";
	public const PUNCTUATOR = "punctuator";
	public const EOF = "eof";

	/**
	 * @param list<array{text: ?string, source: ?string}>|int|float|string|null $value
	 * @param array{flags: string}|null $extra
	 */
	public function __construct(
		public readonly string $type,
		public readonly mixed $value,
		public readonly int $line,
		public readonly int $column,
		public readonly ?array $extra = null
	){}

	/**
	 * The token's text, for the token types that carry one. Punctuators, keywords and identifiers always do;
	 * literals are stringified so diagnostics read naturally.
	 */
	public function text() : string{
		if(\is_string($this->value)){
			return $this->value;
		}
		if(\is_int($this->value) || \is_float($this->value)){
			return Interpreter::formatNumber((float) $this->value);
		}
		if(\is_array($this->extra) && isset($this->extra["flags"])){
			return "/" . (\is_string($this->value) ? $this->value : "") . "/" . $this->extra["flags"];
		}
		return "";
	}

	/**
	 * A short description of the token, used in parser error messages.
	 */
	public function describe() : string{
		return match($this->type){
			self::EOF => "end of input",
			self::STRING, self::TEMPLATE, self::NUMBER => $this->text(),
			self::REGEX => $this->text(),
			default => $this->text()
		};
	}
}
