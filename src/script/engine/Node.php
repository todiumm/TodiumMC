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

use function array_key_exists;
use function is_array;

/**
 * A node in the abstract syntax tree.
 *
 * Nodes are deliberately generic: a `kind` names the construct and `fields` carries its parts. This keeps the
 * parser and interpreter readable without a class per grammar rule.
 *
 * The recognised kinds, and the fields each carries, are:
 *
 *   program      { body: list<Node> }
 *   varDecl      { declKind: string, declarations: list<{id: Node, init: ?Node}> }
 *   exprStmt     { expression: Node }
 *   block        { body: list<Node> }
 *   if           { test: Node, consequent: Node, alternate: ?Node }
 *   while        { test: Node, body: Node }
 *   doWhile      { body: Node, test: Node }
 *   for          { init: ?Node, test: ?Node, update: ?Node, body: Node }
 *   forIn        { left: Node, right: Node, body: Node, declKind: ?string }
 *   forOf        { left: Node, right: Node, body: Node, declKind: ?string }
 *   return       { argument: ?Node }
 *   break        { label: ?string }
 *   continue     { label: ?string }
 *   throw        { argument: Node }
 *   try          { block: Node, handler: ?{param: ?Node, body: Node}, finalizer: ?Node }
 *   switch       { discriminant: Node, cases: list<{test: ?Node, body: list<Node>}> }
 *   functionDecl { id: Node, params: list<Node>, body: Node, async: bool, generator: bool }
 *   classDecl    { id: Node, superClass: ?Node, body: list<Node> }
 *   methodDef    { key: Node, params: list<Node>, body: Node, kind: string, static: bool }
 *   propertyDef  { key: Node, value: ?Node, static: bool }
 *   importDecl   { specifiers: list<{imported: ?string, local: string}>, source: string }
 *   exportDecl   { declaration: ?Node, specifiers: list<array{local: string, exported: string}>, source: ?string, default: bool }
 *   empty        {}
 *   literal      { value: mixed }
 *   template     { parts: list<Node> }
 *   regex        { pattern: string, flags: string }
 *   identifier   { name: string }
 *   this         {}
 *   super        {}
 *   array        { elements: list<?Node> }
 *   object       { properties: list<{key: Node, value: Node, computed: bool, kind: string}> }
 *   function     { id: ?Node, params: list<Node>, body: ?Node, expression: ?Node, async: bool, generator: bool, arrow: bool }
 *   call         { callee: Node, arguments: list<Node> }
 *   new          { callee: Node, arguments: list<Node> }
 *   member       { object: Node, property: Node, computed: bool, optional: bool }
 *   unary        { operator: string, argument: Node, prefix: bool }
 *   update       { operator: string, argument: Node, prefix: bool }
 *   binary       { operator: string, left: Node, right: Node }
 *   logical      { operator: string, left: Node, right: Node }
 *   assignment   { operator: string, left: Node, right: Node }
 *   conditional  { test: Node, consequent: Node, alternate: Node }
 *   sequence     { expressions: list<Node> }
 *   spread       { argument: Node }
 *   await        { argument: Node }
 */
final class Node{

	/**
	 * @param array<string, mixed> $fields
	 */
	public function __construct(
		public readonly string $kind,
		public readonly array $fields = [],
		public readonly int $line = 0,
		public readonly int $column = 0
	){}

	public function field(string $name, mixed $default = null) : mixed{
		return $this->fields[$name] ?? $default;
	}

	public function stringField(string $name, string $default = "") : string{
		$value = $this->fields[$name] ?? null;
		return is_string($value) ? $value : $default;
	}

	public function boolField(string $name, bool $default = false) : bool{
		$value = $this->fields[$name] ?? null;
		return is_bool($value) ? $value : $default;
	}

	public function nullableStringField(string $name) : ?string{
		$value = $this->fields[$name] ?? null;
		return is_string($value) ? $value : null;
	}

	/**
	 * Reads a field holding a list of plain records, skipping anything that is not one.
	 *
	 * @return list<array<string, mixed>>
	 */
	public function recordList(string $name) : array{
		$value = $this->fields[$name] ?? null;
		if(!is_array($value)){
			return [];
		}
		$out = [];
		foreach($value as $item){
			if(is_array($item)){
				$out[] = $item;
			}
		}
		return $out;
	}

	public function child(string $name) : ?Node{
		$value = $this->fields[$name] ?? null;
		return $value instanceof Node ? $value : null;
	}

	/**
	 * @return list<Node>
	 */
	public function childList(string $name) : array{
		$value = $this->fields[$name] ?? [];
		if(!is_array($value)){
			return [];
		}
		$out = [];
		foreach($value as $item){
			if($item instanceof Node){
				$out[] = $item;
			}
		}
		return $out;
	}

	public function has(string $name) : bool{
		return array_key_exists($name, $this->fields);
	}
}
