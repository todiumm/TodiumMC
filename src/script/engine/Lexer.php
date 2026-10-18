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

use function bindec;
use function ctype_alpha;
use function ctype_digit;
use function ctype_xdigit;
use function hexdec;
use function mb_chr;
use function octdec;
use function ord;
use function str_contains;
use function str_replace;
use function strlen;
use function strpos;
use function substr;

/**
 * A hand-written lexer for the JavaScript subset behaviour pack scripts use.
 *
 * Template literals and regular expression literals are decoded here, so the parser never sees raw delimiters.
 */
final class Lexer{

	private const KEYWORDS = [
		"break" => true, "case" => true, "catch" => true, "class" => true, "const" => true, "continue" => true,
		"debugger" => true, "default" => true, "delete" => true, "do" => true, "else" => true, "export" => true,
		"extends" => true, "false" => true, "finally" => true, "for" => true, "function" => true, "if" => true,
		"import" => true, "in" => true, "instanceof" => true, "let" => true, "new" => true, "null" => true,
		"of" => true, "return" => true, "super" => true, "switch" => true, "this" => true, "throw" => true,
		"true" => true, "try" => true, "typeof" => true, "undefined" => true, "var" => true, "void" => true,
		"while" => true, "yield" => true, "async" => true, "await" => true, "static" => true
	];

	/** Operators after which a slash starts a regular expression rather than a division. */
	private const REGEX_ALLOWING = [
		"(" => true, "[" => true, "{" => true, "," => true, ";" => true, ":" => true, "?" => true,
		"=" => true, "==" => true, "===" => true, "!=" => true, "!==" => true, "<" => true, ">" => true,
		"<=" => true, ">=" => true, "+" => true, "-" => true, "*" => true, "/" => true, "%" => true,
		"**" => true, "!" => true, "~" => true, "&" => true, "|" => true, "^" => true, "&&" => true,
		"||" => true, "??" => true, "=>" => true, "return" => true, "typeof" => true, "instanceof" => true,
		"in" => true, "of" => true, "new" => true, "delete" => true, "void" => true, "case" => true,
		"do" => true, "else" => true
	];

	private const PUNCTUATORS = [
		">>>=", "...", "===", "!==", "**=", "<<=", ">>=", ">>>", "&&=", "||=", "??=",
		"=>", "==", "!=", "<=", ">=", "&&", "||", "??", "?.", "++", "--", "+=", "-=", "*=", "/=",
		"%=", "&=", "|=", "^=", "<<", ">>", "**",
		"{", "}", "(", ")", "[", "]", ";", ",", "<", ">", "+", "-", "*", "/", "%", "&", "|", "^",
		"!", "~", "?", ":", "=", ".", "@"
	];

	private int $offset = 0;
	private int $line = 1;
	private int $column = 1;
	private readonly int $length;

	/** @var list<Token> */
	private array $tokens = [];

	private ?Token $pending = null;

	public function __construct(
		private readonly string $source,
		private readonly string $name = "<script>"
	){
		$this->length = strlen($source);
	}

	/**
	 * @return list<Token>
	 */
	public function tokenize() : array{
		$previous = null;
		while(true){
			$this->skipTrivia();
			if($this->offset >= $this->length){
				break;
			}

			$char = $this->source[$this->offset];
			$previousText = $previous === null ? "" : $previous->text();
			$regexAllowed = $previous === null
				|| (($previous->type === Token::KEYWORD || $previous->type === Token::PUNCTUATOR) && isset(self::REGEX_ALLOWING[$previousText]));

			if($char === "`"){
				$token = $this->readTemplate();
			}elseif($char === "\"" || $char === "'"){
				$token = $this->readString($char);
			}elseif(ctype_digit($char) || ($char === "." && $this->offset + 1 < $this->length && ctype_digit($this->source[$this->offset + 1]))){
				$token = $this->readNumber();
			}elseif($char === "/" && $regexAllowed && $this->tryReadRegex() !== null){
				$token = $this->pending;
			}elseif($this->isIdentifierStart($char)){
				$token = $this->readIdentifier();
			}else{
				$token = $this->readPunctuator();
				if($token === null){
					throw $this->error("Unexpected character \"" . $char . "\"");
				}
			}

			if($token !== null){
				$this->tokens[] = $token;
				$previous = $token;
			}
		}

		$this->tokens[] = new Token(Token::EOF, null, $this->line, $this->column);
		return $this->tokens;
	}

	private function error(string $message) : ScriptEngineException{
		return new ScriptEngineException($message, $this->line, $this->column, $this->name);
	}

	private function isIdentifierStart(string $char) : bool{
		return ctype_alpha($char) || $char === "_" || $char === "$" || ord($char) >= 0x80;
	}

	private function isIdentifierPart(string $char) : bool{
		return $this->isIdentifierStart($char) || ctype_digit($char);
	}

	private function skipTrivia() : void{
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			if($char === "\n"){
				$this->offset++;
				$this->line++;
				$this->column = 1;
				continue;
			}
			if($char === " " || $char === "\t" || $char === "\r" || $char === "\f" || $char === "\v"){
				$this->offset++;
				$this->column++;
				continue;
			}
			if($char === "\xEF" && substr($this->source, $this->offset, 3) === "\xEF\xBB\xBF"){
				$this->offset += 3;
				continue;
			}
			if($char === "/" && $this->offset + 1 < $this->length){
				$next = $this->source[$this->offset + 1];
				if($next === "/"){
					while($this->offset < $this->length && $this->source[$this->offset] !== "\n"){
						$this->offset++;
					}
					continue;
				}
				if($next === "*"){
					$this->skipBlockComment();
					continue;
				}
			}
			break;
		}
	}

	private function skipBlockComment() : void{
		$this->offset += 2;
		$this->column += 2;
		while($this->offset < $this->length){
			if($this->source[$this->offset] === "\n"){
				$this->line++;
				$this->column = 1;
				$this->offset++;
				continue;
			}
			if($this->source[$this->offset] === "*" && $this->offset + 1 < $this->length && $this->source[$this->offset + 1] === "/"){
				$this->offset += 2;
				$this->column += 2;
				return;
			}
			$this->offset++;
			$this->column++;
		}
	}

	private function readIdentifier() : Token{
		$line = $this->line;
		$column = $this->column;
		$start = $this->offset;
		while($this->offset < $this->length && $this->isIdentifierPart($this->source[$this->offset])){
			$this->offset++;
		}
		$this->column += $this->offset - $start;
		$value = substr($this->source, $start, $this->offset - $start);
		return new Token(isset(self::KEYWORDS[$value]) ? Token::KEYWORD : Token::IDENTIFIER, $value, $line, $column);
	}

	private function readNumber() : Token{
		$line = $this->line;
		$column = $this->column;
		$start = $this->offset;

		if($this->source[$this->offset] === "0" && $this->offset + 1 < $this->length){
			$prefix = $this->source[$this->offset + 1];
			if($prefix === "x" || $prefix === "X"){
				$this->offset += 2;
				$digits = $this->consumeWhile(static fn(string $c) : bool => ctype_xdigit($c) || $c === "_");
				$this->column += 2 + strlen($digits);
				return new Token(Token::NUMBER, (float) hexdec(str_replace("_", "", $digits)), $line, $column);
			}
			if($prefix === "b" || $prefix === "B"){
				$this->offset += 2;
				$digits = $this->consumeWhile(static fn(string $c) : bool => $c === "0" || $c === "1" || $c === "_");
				$this->column += 2 + strlen($digits);
				return new Token(Token::NUMBER, (float) bindec(str_replace("_", "", $digits)), $line, $column);
			}
			if($prefix === "o" || $prefix === "O"){
				$this->offset += 2;
				$digits = $this->consumeWhile(static fn(string $c) : bool => $c >= "0" && $c <= "7");
				$this->column += 2 + strlen($digits);
				return new Token(Token::NUMBER, (float) octdec(str_replace("_", "", $digits)), $line, $column);
			}
		}

		$this->consumeWhile(static fn(string $c) : bool => ctype_digit($c) || $c === "_");
		if($this->offset < $this->length && $this->source[$this->offset] === "."){
			$this->offset++;
			$this->consumeWhile(static fn(string $c) : bool => ctype_digit($c) || $c === "_");
		}
		if($this->offset < $this->length && ($this->source[$this->offset] === "e" || $this->source[$this->offset] === "E")){
			$save = $this->offset;
			$this->offset++;
			if($this->offset < $this->length && ($this->source[$this->offset] === "+" || $this->source[$this->offset] === "-")){
				$this->offset++;
			}
			if($this->offset < $this->length && ctype_digit($this->source[$this->offset])){
				$this->consumeWhile(static fn(string $c) : bool => ctype_digit($c));
			}else{
				$this->offset = $save;
			}
		}
		if($this->offset < $this->length && $this->source[$this->offset] === "n"){
			$this->offset++;
		}

		$raw = str_replace("_", "", substr($this->source, $start, $this->offset - $start));
		$this->column += $this->offset - $start;
		return new Token(Token::NUMBER, self::parseNumericLiteral($raw), $line, $column);
	}

	public static function parseNumericLiteral(string $raw) : int|float{
		if(str_contains($raw, ".") || str_contains($raw, "e") || str_contains($raw, "E")){
			return (float) $raw;
		}
		return (int) $raw;
	}

	private function readString(string $quote) : Token{
		$line = $this->line;
		$column = $this->column;
		$this->offset++;
		$this->column++;
		$result = "";
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			if($char === "\\"){
				$this->offset++;
				$this->column++;
				$result .= $this->readEscape();
				continue;
			}
			if($char === $quote){
				$this->offset++;
				$this->column++;
				return new Token(Token::STRING, $result, $line, $column);
			}
			if($char === "\n"){
				break;
			}
			$result .= $char;
			$this->offset++;
			$this->column++;
		}
		throw new ScriptEngineException("Unterminated string literal", $line, $column, $this->name);
	}

	private function readEscape() : string{
		if($this->offset >= $this->length){
			return "";
		}
		$char = $this->source[$this->offset];
		$this->offset++;
		$this->column++;
		return match($char){
			"n" => "\n",
			"t" => "\t",
			"r" => "\r",
			"b" => "\x08",
			"f" => "\f",
			"v" => "\x0B",
			"0" => "\0",
			"x" => $this->readHexEscape(2),
			"u" => $this->readUnicodeEscape(),
			default => $char
		};
	}

	private function readHexEscape(int $digits) : string{
		$hex = substr($this->source, $this->offset, $digits);
		$this->offset += $digits;
		$this->column += $digits;
		return mb_chr((int) hexdec($hex), "UTF-8");
	}

	private function readUnicodeEscape() : string{
		if($this->offset < $this->length && $this->source[$this->offset] === "{"){
			$end = strpos($this->source, "}", $this->offset);
			if($end !== false){
				$hex = substr($this->source, $this->offset + 1, $end - $this->offset - 1);
				$this->column += $end - $this->offset + 1;
				$this->offset = $end + 1;
				return mb_chr((int) hexdec($hex), "UTF-8");
			}
		}
		return $this->readHexEscape(4);
	}

	private function readTemplate() : Token{
		$line = $this->line;
		$column = $this->column;
		$this->offset++;
		$this->column++;

		/** @var list<array{text: ?string, source: ?string}> $parts */
		$parts = [];
		$current = "";
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			if($char === "\\"){
				$this->offset++;
				$this->column++;
				$current .= $this->readEscape();
				continue;
			}
			if($char === "`"){
				$this->offset++;
				$this->column++;
				$parts[] = ["text" => $current, "source" => null];
				return new Token(Token::TEMPLATE, $parts, $line, $column);
			}
			if($char === "$" && $this->offset + 1 < $this->length && $this->source[$this->offset + 1] === "{"){
				$this->offset += 2;
				$this->column += 2;
				$parts[] = ["text" => $current, "source" => null];
				$current = "";
				$parts[] = ["text" => null, "source" => $this->readTemplateExpression()];
				continue;
			}
			if($char === "\n"){
				$this->line++;
				$this->column = 1;
			}else{
				$this->column++;
			}
			$current .= $char;
			$this->offset++;
		}
		throw new ScriptEngineException("Unterminated template literal", $line, $column, $this->name);
	}

	private function readTemplateExpression() : string{
		$depth = 1;
		$expression = "";
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			if($char === "{"){
				++$depth;
			}elseif($char === "}"){
				--$depth;
				if($depth === 0){
					break;
				}
			}elseif($char === "`"){
				$expression .= $this->readRawTemplate();
				continue;
			}elseif($char === "\"" || $char === "'"){
				$expression .= $this->readRawString($char);
				continue;
			}
			if($char === "\n"){
				$this->line++;
				$this->column = 1;
			}else{
				$this->column++;
			}
			$expression .= $char;
			$this->offset++;
		}
		if($depth !== 0){
			throw $this->error("Unterminated template substitution");
		}
		$this->offset++;
		$this->column++;
		return $expression;
	}

	private function readRawTemplate() : string{
		$result = "`";
		$this->offset++;
		$this->column++;
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			$result .= $char;
			if($char === "\\"){
				$this->offset++;
				$this->column++;
				if($this->offset < $this->length){
					$result .= $this->source[$this->offset];
					$this->offset++;
					$this->column++;
				}
				continue;
			}
			if($char === "`"){
				$this->offset++;
				$this->column++;
				return $result;
			}
			$this->offset++;
			$this->column++;
		}
		return $result;
	}

	private function readRawString(string $quote) : string{
		$result = $quote;
		$this->offset++;
		$this->column++;
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			$result .= $char;
			if($char === "\\"){
				$this->offset++;
				$this->column++;
				if($this->offset < $this->length){
					$result .= $this->source[$this->offset];
					$this->offset++;
					$this->column++;
				}
				continue;
			}
			if($char === $quote){
				$this->offset++;
				$this->column++;
				return $result;
			}
			$this->offset++;
			$this->column++;
		}
		return $result;
	}

	private function tryReadRegex() : ?Token{
		$line = $this->line;
		$column = $this->column;
		$save = [$this->offset, $this->line, $this->column];

		$this->offset++;
		$this->column++;
		$pattern = "";
		$inClass = false;
		$closed = false;
		while($this->offset < $this->length){
			$char = $this->source[$this->offset];
			if($char === "\\"){
				$pattern .= $char;
				$this->offset++;
				if($this->offset < $this->length){
					$pattern .= $this->source[$this->offset];
					$this->offset++;
					$this->column += 2;
				}
				continue;
			}
			if($char === "\n"){
				break;
			}
			if($char === "["){
				$inClass = true;
			}elseif($char === "]"){
				$inClass = false;
			}elseif($char === "/" && !$inClass){
				$closed = true;
				break;
			}
			$pattern .= $char;
			$this->offset++;
			$this->column++;
		}

		if(!$closed){
			[$this->offset, $this->line, $this->column] = $save;
			return null;
		}

		$this->offset++;
		$this->column++;
		$flags = $this->consumeWhile(static fn(string $c) : bool => ctype_alpha($c));
		$this->column += strlen($flags);

		$this->pending = new Token(Token::REGEX, $pattern, $line, $column, ["flags" => $flags]);
		return $this->pending;
	}

	private function readPunctuator() : ?Token{
		$line = $this->line;
		$column = $this->column;
		foreach(self::PUNCTUATORS as $candidate){
			$length = strlen($candidate);
			if(substr($this->source, $this->offset, $length) === $candidate){
				$this->offset += $length;
				$this->column += $length;
				return new Token(Token::PUNCTUATOR, $candidate, $line, $column);
			}
		}
		return null;
	}

	/**
	 * @param callable(string) : bool $predicate
	 */
	private function consumeWhile(callable $predicate) : string{
		$start = $this->offset;
		while($this->offset < $this->length && $predicate($this->source[$this->offset])){
			$this->offset++;
		}
		return substr($this->source, $start, $this->offset - $start);
	}
}
