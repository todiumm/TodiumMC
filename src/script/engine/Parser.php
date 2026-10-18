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
use function in_array;
use function is_array;
use function is_string;

/**
 * A recursive-descent parser for the JavaScript subset behaviour pack scripts use.
 *
 * Expressions use Pratt-style binding powers. Statements are parsed by the usual lookahead rules, with lenient
 * automatic semicolon insertion so scripts written without trailing semicolons still load.
 */
final class Parser{

	private const ASSIGNMENT_OPERATORS = [
		"=" => true, "+=" => true, "-=" => true, "*=" => true, "/=" => true, "%=" => true,
		"**=" => true, "&=" => true, "|=" => true, "^=" => true, "<<=" => true, ">>=" => true,
		">>>=" => true, "&&=" => true, "||=" => true, "??=" => true
	];

	/** Binary operator binding powers; a higher number binds more tightly. */
	private const BINARY_POWER = [
		"??" => 1,
		"||" => 2,
		"&&" => 3,
		"|" => 4,
		"^" => 5,
		"&" => 6,
		"==" => 7, "!=" => 7, "===" => 7, "!==" => 7,
		"<" => 8, ">" => 8, "<=" => 8, ">=" => 8, "instanceof" => 8, "in" => 8,
		"<<" => 9, ">>" => 9, ">>>" => 9,
		"+" => 10, "-" => 10,
		"*" => 11, "/" => 11, "%" => 11,
		"**" => 12
	];

	private const LOGICAL_OPERATORS = ["&&" => true, "||" => true, "??" => true];

	private const UNARY_OPERATORS = ["!" => true, "~" => true, "+" => true, "-" => true, "typeof" => true, "void" => true, "delete" => true];

	/** @var list<Token> */
	private array $tokens;
	private int $index = 0;

	public function __construct(
		string $source,
		private readonly string $name = "<script>"
	){
		$this->tokens = (new Lexer($source, $name))->tokenize();
	}

	public function parse() : Node{
		$body = [];
		while(!$this->atEnd()){
			$body[] = $this->parseStatement();
		}
		return new Node("program", ["body" => $body]);
	}

	public function parseExpressionSource() : Node{
		$expression = $this->parseExpression();
		$this->expectSemicolon();
		return $expression;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Token helpers
	// ---------------------------------------------------------------------------------------------------------

	private function peek(int $ahead = 0) : Token{
		$index = $this->index + $ahead;
		return $this->tokens[$index] ?? $this->tokens[count($this->tokens) - 1];
	}

	private function next() : Token{
		$token = $this->peek();
		if($token->type !== Token::EOF){
			$this->index++;
		}
		return $token;
	}

	private function atEnd() : bool{
		return $this->peek()->type === Token::EOF;
	}

	private function isPunctuator(string $value, int $ahead = 0) : bool{
		$token = $this->peek($ahead);
		return $token->type === Token::PUNCTUATOR && $token->value === $value;
	}

	private function isKeyword(string $value, int $ahead = 0) : bool{
		$token = $this->peek($ahead);
		return $token->type === Token::KEYWORD && $token->value === $value;
	}

	private function matchPunctuator(string ...$values) : ?Token{
		$token = $this->peek();
		if($token->type === Token::PUNCTUATOR && in_array($token->value, $values, true)){
			return $this->next();
		}
		return null;
	}

	private function matchKeyword(string ...$values) : ?Token{
		$token = $this->peek();
		if($token->type === Token::KEYWORD && in_array($token->value, $values, true)){
			return $this->next();
		}
		return null;
	}

	/**
	 * Matches a contextual keyword, i.e. a word that is a plain identifier in JavaScript but has a special
	 * meaning in this position ("from", "as").
	 */
	private function matchContextual(string ...$values) : ?Token{
		$token = $this->peek();
		if(($token->type === Token::IDENTIFIER || $token->type === Token::KEYWORD) && in_array($token->value, $values, true)){
			return $this->next();
		}
		return null;
	}

	private function expectPunctuator(string $value) : Token{
		$token = $this->peek();
		if($token->type !== Token::PUNCTUATOR || $token->value !== $value){
			throw $this->error("Expected \"" . $value . "\" but found " . $this->describe($token));
		}
		return $this->next();
	}

	private function describe(Token $token) : string{
		if($token->type === Token::EOF){
			return "end of input";
		}
		return "\"" . $token->text() . "\"";
	}

	private function error(string $message, ?Token $token = null) : ScriptEngineException{
		$token ??= $this->peek();
		return new ScriptEngineException($message, $token->line, $token->column, $this->name);
	}

	private function expectSemicolon() : void{
		if($this->matchPunctuator(";") !== null){
			return;
		}
		if($this->isPunctuator("}") || $this->atEnd()){
			return;
		}
		$previous = $this->peek(-1);
		$current = $this->peek();
		if($current->line > $previous->line){
			return;
		}
		throw $this->error("Expected \";\" but found " . $this->describe($current));
	}

	private function identifierName() : string{
		$token = $this->peek();
		if($token->type === Token::IDENTIFIER || $token->type === Token::KEYWORD){
			$this->next();
			return $token->text();
		}
		throw $this->error("Expected an identifier but found " . $this->describe($token));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Statements
	// ---------------------------------------------------------------------------------------------------------

	private function parseStatement() : Node{
		$token = $this->peek();

		if($token->type === Token::PUNCTUATOR){
			return match($token->value){
				"{" => $this->parseBlock(),
				";" => (function() : Node{ $this->next(); return new Node("empty"); })(),
				default => $this->parseExpressionStatement()
			};
		}

		if($token->type === Token::KEYWORD){
			switch($token->value){
				case "const":
				case "let":
				case "var":
					return $this->parseVariableDeclaration();
				case "if":
					return $this->parseIf();
				case "while":
					return $this->parseWhile();
				case "do":
					return $this->parseDoWhile();
				case "for":
					return $this->parseFor();
				case "return":
					return $this->parseReturn();
				case "break":
				case "continue":
					return $this->parseBreakOrContinue();
				case "throw":
					return $this->parseThrow();
				case "try":
					return $this->parseTry();
				case "switch":
					return $this->parseSwitch();
				case "function":
					return $this->parseFunctionDeclaration(false);
				case "class":
					return $this->parseClassDeclaration();
				case "import":
					return $this->parseImport();
				case "export":
					return $this->parseExport();
				case "async":
					if($this->isKeyword("function", 1)){
						$this->next();
						return $this->parseFunctionDeclaration(true);
					}
					return $this->parseExpressionStatement();
				case "debugger":
					$this->next();
					$this->expectSemicolon();
					return new Node("empty");
				default:
					break;
			}
		}

		return $this->parseExpressionStatement();
	}

	private function parseBlock() : Node{
		$start = $this->expectPunctuator("{");
		$body = [];
		while(!$this->isPunctuator("}") && !$this->atEnd()){
			$body[] = $this->parseStatement();
		}
		$this->expectPunctuator("}");
		return new Node("block", ["body" => $body], $start->line, $start->column);
	}

	private function parseExpressionStatement() : Node{
		$start = $this->peek();
		$expression = $this->parseExpression();
		$this->expectSemicolon();
		return new Node("exprStmt", ["expression" => $expression], $start->line, $start->column);
	}

	private function parseVariableDeclaration(bool $consumeSemicolon = true) : Node{
		$start = $this->next();
		$kind = $start->text();
		$declarations = [];
		do{
			$id = $this->parseBindingTarget();
			$init = null;
			if($this->matchPunctuator("=") !== null){
				$init = $this->parseAssignment();
			}
			$declarations[] = ["id" => $id, "init" => $init];
		}while($this->matchPunctuator(",") !== null);

		if($consumeSemicolon){
			$this->expectSemicolon();
		}
		return new Node("varDecl", ["declKind" => $kind, "declarations" => $declarations], $start->line, $start->column);
	}

	private function parseBindingTarget() : Node{
		if($this->isPunctuator("[")){
			return $this->parseArrayPattern();
		}
		if($this->isPunctuator("{")){
			return $this->parseObjectPattern();
		}
		$token = $this->peek();
		$name = $this->identifierName();
		return new Node("identifier", ["name" => $name], $token->line, $token->column);
	}

	private function parseArrayPattern() : Node{
		$start = $this->expectPunctuator("[");
		$elements = [];
		while(!$this->isPunctuator("]") && !$this->atEnd()){
			if($this->isPunctuator(",")){
				$this->next();
				$elements[] = null;
				continue;
			}
			if($this->matchPunctuator("...") !== null){
				$elements[] = new Node("spread", ["argument" => $this->parseBindingTarget()]);
			}else{
				$target = $this->parseBindingTarget();
				if($this->matchPunctuator("=") !== null){
					$target = new Node("assignment", ["operator" => "=", "left" => $target, "right" => $this->parseAssignment()]);
				}
				$elements[] = $target;
			}
			if($this->matchPunctuator(",") === null){
				break;
			}
		}
		$this->expectPunctuator("]");
		return new Node("arrayPattern", ["elements" => $elements], $start->line, $start->column);
	}

	private function parseObjectPattern() : Node{
		$start = $this->expectPunctuator("{");
		$properties = [];
		while(!$this->isPunctuator("}") && !$this->atEnd()){
			if($this->matchPunctuator("...") !== null){
				$properties[] = ["key" => null, "value" => $this->parseBindingTarget(), "computed" => false, "kind" => "rest"];
			}else{
				$key = $this->parsePropertyKey();
				$value = $key->kind === "literal" && is_string($key->field("value"))
					? new Node("identifier", ["name" => $key->stringField("value")], $key->line, $key->column)
					: $key;
				if($this->matchPunctuator(":") !== null){
					$value = $this->parseBindingTarget();
				}
				if($this->matchPunctuator("=") !== null){
					$value = new Node("assignment", ["operator" => "=", "left" => $value, "right" => $this->parseAssignment()]);
				}
				$properties[] = ["key" => $key, "value" => $value, "computed" => false, "kind" => "init"];
			}
			if($this->matchPunctuator(",") === null){
				break;
			}
		}
		$this->expectPunctuator("}");
		return new Node("objectPattern", ["properties" => $properties], $start->line, $start->column);
	}

	private function parsePropertyKey() : Node{
		$token = $this->peek();
		if($this->isPunctuator("[")){
			$this->next();
			$expression = $this->parseAssignment();
			$this->expectPunctuator("]");
			return new Node("computedKey", ["expression" => $expression], $token->line, $token->column);
		}
		if($token->type === Token::STRING){
			$this->next();
			return new Node("literal", ["value" => $token->text()], $token->line, $token->column);
		}
		if($token->type === Token::NUMBER){
			$this->next();
			return new Node("literal", ["value" => $token->value], $token->line, $token->column);
		}
		$name = $this->identifierName();
		return new Node("literal", ["value" => $name], $token->line, $token->column);
	}

	private function parseIf() : Node{
		$start = $this->next();
		$this->expectPunctuator("(");
		$test = $this->parseExpression();
		$this->expectPunctuator(")");
		$consequent = $this->parseStatement();
		$alternate = null;
		if($this->matchKeyword("else") !== null){
			$alternate = $this->parseStatement();
		}
		return new Node("if", ["test" => $test, "consequent" => $consequent, "alternate" => $alternate], $start->line, $start->column);
	}

	private function parseWhile() : Node{
		$start = $this->next();
		$this->expectPunctuator("(");
		$test = $this->parseExpression();
		$this->expectPunctuator(")");
		$body = $this->parseStatement();
		return new Node("while", ["test" => $test, "body" => $body], $start->line, $start->column);
	}

	private function parseDoWhile() : Node{
		$start = $this->next();
		$body = $this->parseStatement();
		$this->matchKeyword("while");
		$this->expectPunctuator("(");
		$test = $this->parseExpression();
		$this->expectPunctuator(")");
		$this->matchPunctuator(";");
		return new Node("doWhile", ["body" => $body, "test" => $test], $start->line, $start->column);
	}

	private function parseFor() : Node{
		$start = $this->next();
		$this->expectPunctuator("(");

		$init = null;
		$declKind = null;
		if($this->isPunctuator(";")){
			$this->next();
		}elseif($this->isKeyword("const") || $this->isKeyword("let") || $this->isKeyword("var")){
			$declKind = $this->peek()->text();
			$init = $this->parseVariableDeclaration(false);
		}else{
			$init = $this->parseExpression();
		}

		if($declKind !== null && ($this->isKeyword("of") || $this->isKeyword("in"))){
			$keyword = $this->next()->text();
			$right = $this->parseExpression();
			$this->expectPunctuator(")");
			$body = $this->parseStatement();
			$target = null;
			if($init instanceof Node){
				$declarationList = $init->recordList("declarations");
				$first = $declarationList[0] ?? null;
				$candidate = $first["id"] ?? null;
				$target = $candidate instanceof Node ? $candidate : null;
			}
			return new Node(
				$keyword === "of" ? "forOf" : "forIn",
				["left" => $target, "right" => $right, "body" => $body, "declKind" => $declKind],
				$start->line,
				$start->column
			);
		}

		if($init instanceof Node && $init->kind === "varDecl"){
			$this->expectPunctuator(";");
		}else{
			if($this->isKeyword("in") || $this->isKeyword("of")){
				$keyword = $this->next()->text();
				$right = $this->parseExpression();
				$this->expectPunctuator(")");
				$body = $this->parseStatement();
				return new Node(
					$keyword === "of" ? "forOf" : "forIn",
					["left" => $init, "right" => $right, "body" => $body, "declKind" => null],
					$start->line,
					$start->column
				);
			}
			$this->expectPunctuator(";");
		}

		$test = null;
		if(!$this->isPunctuator(";")){
			$test = $this->parseExpression();
		}
		$this->expectPunctuator(";");

		$update = null;
		if(!$this->isPunctuator(")")){
			$update = $this->parseExpression();
		}
		$this->expectPunctuator(")");

		$body = $this->parseStatement();
		return new Node("for", ["init" => $init, "test" => $test, "update" => $update, "body" => $body], $start->line, $start->column);
	}

	private function parseReturn() : Node{
		$start = $this->next();
		$argument = null;
		if(!$this->isPunctuator(";") && !$this->isPunctuator("}") && !$this->atEnd() && $this->peek()->line === $start->line){
			$argument = $this->parseExpression();
		}
		$this->expectSemicolon();
		return new Node("return", ["argument" => $argument], $start->line, $start->column);
	}

	private function parseBreakOrContinue() : Node{
		$start = $this->next();
		$label = null;
		if($this->peek()->type === Token::IDENTIFIER){
			$label = $this->next()->text();
		}
		$this->expectSemicolon();
		return new Node($start->text() === "break" ? "break" : "continue", ["label" => $label], $start->line, $start->column);
	}

	private function parseThrow() : Node{
		$start = $this->next();
		$argument = $this->parseExpression();
		$this->expectSemicolon();
		return new Node("throw", ["argument" => $argument], $start->line, $start->column);
	}

	private function parseTry() : Node{
		$start = $this->next();
		$block = $this->parseBlock();
		$handler = null;
		$finalizer = null;

		if($this->matchKeyword("catch") !== null){
			$param = null;
			if($this->matchPunctuator("(") !== null){
				$param = $this->parseBindingTarget();
				$this->expectPunctuator(")");
			}
			$handler = ["param" => $param, "body" => $this->parseBlock()];
		}
		if($this->matchKeyword("finally") !== null){
			$finalizer = $this->parseBlock();
		}
		return new Node("try", ["block" => $block, "handler" => $handler, "finalizer" => $finalizer], $start->line, $start->column);
	}

	private function parseSwitch() : Node{
		$start = $this->next();
		$this->expectPunctuator("(");
		$discriminant = $this->parseExpression();
		$this->expectPunctuator(")");
		$this->expectPunctuator("{");

		$cases = [];
		while(!$this->isPunctuator("}") && !$this->atEnd()){
			$test = null;
			if($this->matchKeyword("case") !== null){
				$test = $this->parseExpression();
			}else{
				$this->matchKeyword("default");
			}
			$this->expectPunctuator(":");
			$body = [];
			while(!$this->isPunctuator("}") && !$this->isKeyword("case") && !$this->isKeyword("default") && !$this->atEnd()){
				$body[] = $this->parseStatement();
			}
			$cases[] = ["test" => $test, "body" => $body];
		}
		$this->expectPunctuator("}");
		return new Node("switch", ["discriminant" => $discriminant, "cases" => $cases], $start->line, $start->column);
	}

	private function parseFunctionDeclaration(bool $async) : Node{
		$start = $this->next();
		$generator = $this->matchPunctuator("*") !== null;
		$id = new Node("identifier", ["name" => $this->identifierName()], $start->line, $start->column);
		$params = $this->parseParameterList();
		$body = $this->parseBlock();
		return new Node("functionDecl", [
			"id" => $id,
			"params" => $params,
			"body" => $body,
			"async" => $async,
			"generator" => $generator
		], $start->line, $start->column);
	}

	/**
	 * @return list<Node>
	 */
	private function parseParameterList() : array{
		$this->expectPunctuator("(");
		$params = [];
		while(!$this->isPunctuator(")") && !$this->atEnd()){
			if($this->matchPunctuator("...") !== null){
				$params[] = new Node("spread", ["argument" => $this->parseBindingTarget()]);
			}else{
				$target = $this->parseBindingTarget();
				if($this->matchPunctuator("=") !== null){
					$target = new Node("assignment", ["operator" => "=", "left" => $target, "right" => $this->parseAssignment()]);
				}
				$params[] = $target;
			}
			if($this->matchPunctuator(",") === null){
				break;
			}
		}
		$this->expectPunctuator(")");
		return $params;
	}

	private function parseClassDeclaration() : Node{
		$start = $this->next();
		$id = new Node("identifier", ["name" => $this->identifierName()], $start->line, $start->column);
		$superClass = null;
		if($this->matchKeyword("extends") !== null){
			$superClass = $this->parseLeftHandSide();
		}
		$body = $this->parseClassBody();
		return new Node("classDecl", ["id" => $id, "superClass" => $superClass, "body" => $body], $start->line, $start->column);
	}

	/**
	 * @return list<Node>
	 */
	private function parseClassBody() : array{
		$this->expectPunctuator("{");
		$members = [];
		while(!$this->isPunctuator("}") && !$this->atEnd()){
			if($this->matchPunctuator(";") !== null){
				continue;
			}
			$memberStart = $this->peek();
			$isStatic = $this->matchKeyword("static") !== null;
			$kind = "method";
			if($this->isKeyword("get") || $this->isKeyword("set")){
				$lookahead = $this->peek(1);
				if(!($lookahead->type === Token::PUNCTUATOR && ($lookahead->value === "(" || $lookahead->value === "="))){
					$kind = $this->next()->text();
				}
			}

			$computed = $this->isPunctuator("[");
			$key = $this->parsePropertyKey();

			if($this->isPunctuator("(")){
				$params = $this->parseParameterList();
				$body = $this->parseBlock();
				$members[] = new Node("methodDef", [
					"key" => $key,
					"params" => $params,
					"body" => $body,
					"kind" => $kind,
					"static" => $isStatic,
					"computed" => $computed
				], $memberStart->line, $memberStart->column);
			}else{
				$value = null;
				if($this->matchPunctuator("=") !== null){
					$value = $this->parseAssignment();
				}
				$this->matchPunctuator(";");
				$members[] = new Node("propertyDef", [
					"key" => $key,
					"value" => $value,
					"static" => $isStatic,
					"computed" => $computed
				], $memberStart->line, $memberStart->column);
			}
		}
		$this->expectPunctuator("}");
		return $members;
	}

	private function parseImport() : Node{
		$start = $this->next();
		$specifiers = [];

		if($this->peek()->type === Token::STRING){
			$source = $this->next()->text();
			$this->expectSemicolon();
			return new Node("importDecl", ["specifiers" => [], "source" => $source], $start->line, $start->column);
		}

		if($this->peek()->type === Token::IDENTIFIER){
			$specifiers[] = ["imported" => "default", "local" => $this->next()->text()];
			$this->matchPunctuator(",");
		}

		if($this->matchPunctuator("*") !== null){
			$this->matchContextual("as");
			$specifiers[] = ["imported" => "*", "local" => $this->identifierName()];
		}elseif($this->isPunctuator("{")){
			$this->next();
			while(!$this->isPunctuator("}") && !$this->atEnd()){
				$imported = $this->identifierName();
				$local = $imported;
				if($this->matchContextual("as") !== null){
					$local = $this->identifierName();
				}
				$specifiers[] = ["imported" => $imported, "local" => $local];
				if($this->matchPunctuator(",") === null){
					break;
				}
			}
			$this->expectPunctuator("}");
		}

		$this->matchContextual("from");
		$token = $this->peek();
		if($token->type !== Token::STRING){
			throw $this->error("Expected a module specifier string but found " . $this->describe($token));
		}
		$this->next();
		$this->expectSemicolon();

		return new Node("importDecl", ["specifiers" => $specifiers, "source" => $token->text()], $start->line, $start->column);
	}

	private function parseExport() : Node{
		$start = $this->next();

		if($this->matchKeyword("default") !== null){
			if($this->isKeyword("function")){
				$declaration = $this->parseFunctionDeclaration(false);
				return new Node("exportDecl", ["declaration" => $declaration, "specifiers" => [], "source" => null, "default" => true], $start->line, $start->column);
			}
			if($this->isKeyword("class")){
				$declaration = $this->parseClassDeclaration();
				return new Node("exportDecl", ["declaration" => $declaration, "specifiers" => [], "source" => null, "default" => true], $start->line, $start->column);
			}
			$expression = $this->parseAssignment();
			$this->expectSemicolon();
			return new Node("exportDecl", ["declaration" => new Node("exprStmt", ["expression" => $expression]), "specifiers" => [], "source" => null, "default" => true], $start->line, $start->column);
		}

		if($this->isKeyword("const") || $this->isKeyword("let") || $this->isKeyword("var")){
			$declaration = $this->parseVariableDeclaration();
			return new Node("exportDecl", ["declaration" => $declaration, "specifiers" => [], "source" => null, "default" => false], $start->line, $start->column);
		}
		if($this->isKeyword("function")){
			$declaration = $this->parseFunctionDeclaration(false);
			return new Node("exportDecl", ["declaration" => $declaration, "specifiers" => [], "source" => null, "default" => false], $start->line, $start->column);
		}
		if($this->isKeyword("class")){
			$declaration = $this->parseClassDeclaration();
			return new Node("exportDecl", ["declaration" => $declaration, "specifiers" => [], "source" => null, "default" => false], $start->line, $start->column);
		}
		if($this->isKeyword("async") && $this->isKeyword("function", 1)){
			$this->next();
			$declaration = $this->parseFunctionDeclaration(true);
			return new Node("exportDecl", ["declaration" => $declaration, "specifiers" => [], "source" => null, "default" => false], $start->line, $start->column);
		}

		$specifiers = [];
		$source = null;
		if($this->matchPunctuator("*") !== null){
			$this->matchContextual("from");
			$token = $this->peek();
			if($token->type !== Token::STRING){
				throw $this->error("Expected a module specifier string");
			}
			$this->next();
			$source = $token->text();
		}else{
			$this->expectPunctuator("{");
			while(!$this->isPunctuator("}") && !$this->atEnd()){
				$local = $this->identifierName();
				$exported = $local;
				if($this->matchContextual("as") !== null){
					$exported = $this->identifierName();
				}
				$specifiers[] = ["local" => $local, "exported" => $exported];
				if($this->matchPunctuator(",") === null){
					break;
				}
			}
			$this->expectPunctuator("}");
			if($this->matchContextual("from") !== null){
				$token = $this->peek();
				if($token->type !== Token::STRING){
					throw $this->error("Expected a module specifier string");
				}
				$this->next();
				$source = $token->text();
			}
		}
		$this->expectSemicolon();
		return new Node("exportDecl", ["declaration" => null, "specifiers" => $specifiers, "source" => $source, "default" => false], $start->line, $start->column);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Expressions
	// ---------------------------------------------------------------------------------------------------------

	private function parseExpression(bool $noIn = false) : Node{
		$first = $this->parseAssignment($noIn);
		if(!$this->isPunctuator(",")){
			return $first;
		}
		$expressions = [$first];
		while($this->matchPunctuator(",") !== null){
			$expressions[] = $this->parseAssignment($noIn);
		}
		return new Node("sequence", ["expressions" => $expressions]);
	}

	private function parseAssignment(bool $noIn = false) : Node{
		$start = $this->peek();

		if($this->isKeyword("yield")){
			$this->next();
			$argument = null;
			if(!$this->isPunctuator(")") && !$this->isPunctuator(";") && !$this->isPunctuator("}") && !$this->atEnd() && !$this->isPunctuator(",")){
				$argument = $this->parseAssignment($noIn);
			}
			return new Node("yield", ["argument" => $argument], $start->line, $start->column);
		}

		$arrow = $this->tryParseArrowFunction();
		if($arrow !== null){
			return $arrow;
		}

		$left = $this->parseConditional($noIn);
		$token = $this->peek();
		if($token->type === Token::PUNCTUATOR && isset(self::ASSIGNMENT_OPERATORS[$token->text()])){
			$this->next();
			$right = $this->parseAssignment($noIn);
			return new Node("assignment", ["operator" => $token->text(), "left" => $left, "right" => $right], $start->line, $start->column);
		}
		return $left;
	}

	private function tryParseArrowFunction() : ?Node{
		$start = $this->peek();

		$async = false;
		$offset = 0;
		if($this->isKeyword("async")){
			$lookahead = $this->peek(1);
			$isArrowStart = ($lookahead->type === Token::IDENTIFIER)
				|| ($lookahead->type === Token::PUNCTUATOR && $lookahead->value === "(");
			if(!$isArrowStart){
				return null;
			}
			$async = true;
			$offset = 1;
		}

		$token = $this->peek($offset);
		if($token->type === Token::IDENTIFIER && $this->isPunctuator("=>", $offset + 1)){
			if($async){
				$this->next();
			}
			$param = new Node("identifier", ["name" => $this->next()->text()], $token->line, $token->column);
			$this->expectPunctuator("=>");
			$body = $this->parseArrowBody();
			return new Node("function", ["id" => null, "params" => [$param], "body" => $body, "expression" => $body->kind !== "block", "async" => $async, "generator" => false, "arrow" => true], $start->line, $start->column);
		}

		if(!($token->type === Token::PUNCTUATOR && $token->value === "(")){
			return null;
		}

		$close = $this->findMatchingParen($this->index + $offset);
		if($close === null || !$this->isPunctuator("=>", $close - $this->index + 1)){
			return null;
		}

		if($async){
			$this->next();
		}
		$params = $this->parseParameterList();
		$this->expectPunctuator("=>");
		$body = $this->parseArrowBody();
		return new Node("function", ["id" => null, "params" => $params, "body" => $body, "expression" => $body->kind !== "block", "async" => $async, "generator" => false, "arrow" => true], $start->line, $start->column);
	}

	private function parseArrowBody() : Node{
		if($this->isPunctuator("{")){
			return $this->parseBlock();
		}
		$expression = $this->parseAssignment();
		return new Node("return", ["argument" => $expression]);
	}

	/**
	 * Returns the absolute token index of the ")" matching the "(" at the given index, or null.
	 */
	private function findMatchingParen(int $openIndex) : ?int{
		$depth = 0;
		$count = count($this->tokens);
		for($i = $openIndex; $i < $count; $i++){
			$token = $this->tokens[$i];
			if($token->type !== Token::PUNCTUATOR){
				continue;
			}
			if($token->value === "("){
				$depth++;
			}elseif($token->value === ")"){
				$depth--;
				if($depth === 0){
					return $i;
				}
			}
		}
		return null;
	}

	private function parseConditional(bool $noIn) : Node{
		$start = $this->peek();
		$test = $this->parseBinary(0, $noIn);
		if($this->matchPunctuator("?") !== null){
			$consequent = $this->parseAssignment();
			$this->expectPunctuator(":");
			$alternate = $this->parseAssignment($noIn);
			return new Node("conditional", ["test" => $test, "consequent" => $consequent, "alternate" => $alternate], $start->line, $start->column);
		}
		return $test;
	}

	private function parseBinary(int $minPower, bool $noIn) : Node{
		$left = $this->parseUnary();
		while(true){
			$token = $this->peek();
			$operator = null;

			if($token->type === Token::PUNCTUATOR && isset(self::BINARY_POWER[$token->text()])){
				$operator = $token->text();
			}elseif($token->type === Token::KEYWORD && ($token->value === "instanceof" || ($token->value === "in" && !$noIn))){
				$operator = $token->text();
			}
			if($operator === null){
				break;
			}

			$power = self::BINARY_POWER[$operator];
			if($power < $minPower){
				break;
			}
			$this->next();

			// "**" is right-associative, everything else is left-associative
			$nextMinPower = $operator === "**" ? $power : $power + 1;
			$right = $this->parseBinary($nextMinPower, $noIn);

			$left = isset(self::LOGICAL_OPERATORS[$operator])
				? new Node("logical", ["operator" => $operator, "left" => $left, "right" => $right], $token->line, $token->column)
				: new Node("binary", ["operator" => $operator, "left" => $left, "right" => $right], $token->line, $token->column);
		}
		return $left;
	}

	private function parseUnary() : Node{
		$token = $this->peek();

		if($token->type === Token::PUNCTUATOR && isset(self::UNARY_OPERATORS[$token->text()])){
			$this->next();
			$argument = $this->parseUnary();
			return new Node("unary", ["operator" => $token->text(), "argument" => $argument, "prefix" => true], $token->line, $token->column);
		}
		if($token->type === Token::KEYWORD && ($token->value === "typeof" || $token->value === "void" || $token->value === "delete")){
			$this->next();
			$argument = $this->parseUnary();
			return new Node("unary", ["operator" => $token->text(), "argument" => $argument, "prefix" => true], $token->line, $token->column);
		}
		if($token->type === Token::KEYWORD && $token->value === "await"){
			$this->next();
			$argument = $this->parseUnary();
			return new Node("await", ["argument" => $argument], $token->line, $token->column);
		}
		if($token->type === Token::PUNCTUATOR && ($token->value === "++" || $token->value === "--")){
			$this->next();
			$argument = $this->parseUnary();
			return new Node("update", ["operator" => $token->text(), "argument" => $argument, "prefix" => true], $token->line, $token->column);
		}

		return $this->parsePostfix();
	}

	private function parsePostfix() : Node{
		$expression = $this->parseLeftHandSide();
		$token = $this->peek();
		if($token->type === Token::PUNCTUATOR && ($token->value === "++" || $token->value === "--") && $token->line === $this->peek(-1)->line){
			$this->next();
			return new Node("update", ["operator" => $token->text(), "argument" => $expression, "prefix" => false], $token->line, $token->column);
		}
		return $expression;
	}

	private function parseLeftHandSide() : Node{
		$expression = $this->parsePrimary();

		while(true){
			$token = $this->peek();

			if($token->type === Token::PUNCTUATOR && $token->value === "."){
				$this->next();
				$name = $this->identifierName();
				$expression = new Node("member", [
					"object" => $expression,
					"property" => new Node("literal", ["value" => $name]),
					"computed" => false,
					"optional" => false
				], $token->line, $token->column);
				continue;
			}
			if($token->type === Token::PUNCTUATOR && $token->value === "?."){
				$this->next();
				if($this->isPunctuator("(")){
					$arguments = $this->parseArguments();
					$expression = new Node("call", ["callee" => $expression, "arguments" => $arguments, "optional" => true], $token->line, $token->column);
					continue;
				}
				if($this->isPunctuator("[")){
					$this->next();
					$property = $this->parseExpression();
					$this->expectPunctuator("]");
					$expression = new Node("member", ["object" => $expression, "property" => $property, "computed" => true, "optional" => true], $token->line, $token->column);
					continue;
				}
				$name = $this->identifierName();
				$expression = new Node("member", [
					"object" => $expression,
					"property" => new Node("literal", ["value" => $name]),
					"computed" => false,
					"optional" => true
				], $token->line, $token->column);
				continue;
			}
			if($token->type === Token::PUNCTUATOR && $token->value === "["){
				$this->next();
				$property = $this->parseExpression();
				$this->expectPunctuator("]");
				$expression = new Node("member", ["object" => $expression, "property" => $property, "computed" => true, "optional" => false], $token->line, $token->column);
				continue;
			}
			if($token->type === Token::PUNCTUATOR && $token->value === "("){
				$arguments = $this->parseArguments();
				$expression = new Node("call", ["callee" => $expression, "arguments" => $arguments, "optional" => false], $token->line, $token->column);
				continue;
			}
			if($token->type === Token::TEMPLATE){
				$template = $this->parseTemplate();
				$expression = new Node("taggedTemplate", ["tag" => $expression, "template" => $template], $token->line, $token->column);
				continue;
			}
			break;
		}

		return $expression;
	}

	/**
	 * @return list<Node>
	 */
	private function parseArguments() : array{
		$this->expectPunctuator("(");
		$arguments = [];
		while(!$this->isPunctuator(")") && !$this->atEnd()){
			if($this->matchPunctuator("...") !== null){
				$arguments[] = new Node("spread", ["argument" => $this->parseAssignment()]);
			}else{
				$arguments[] = $this->parseAssignment();
			}
			if($this->matchPunctuator(",") === null){
				break;
			}
		}
		$this->expectPunctuator(")");
		return $arguments;
	}

	private function parsePrimary() : Node{
		$token = $this->peek();

		switch($token->type){
			case Token::NUMBER:
				$this->next();
				return new Node("literal", ["value" => $token->value], $token->line, $token->column);

			case Token::STRING:
				$this->next();
				return new Node("literal", ["value" => $token->text()], $token->line, $token->column);

			case Token::TEMPLATE:
				return $this->parseTemplate();

			case Token::REGEX:
				$this->next();
				$flags = $token->extra["flags"] ?? "";
				return new Node("regex", ["pattern" => $token->text(), "flags" => is_string($flags) ? $flags : ""], $token->line, $token->column);

			case Token::IDENTIFIER:
				$this->next();
				return new Node("identifier", ["name" => $token->text()], $token->line, $token->column);

			case Token::PUNCTUATOR:
				if($token->value === "("){
					$this->next();
					$expression = $this->parseExpression();
					$this->expectPunctuator(")");
					return $expression;
				}
				if($token->value === "["){
					return $this->parseArrayLiteral();
				}
				if($token->value === "{"){
					return $this->parseObjectLiteral();
				}
				break;

			case Token::KEYWORD:
				switch($token->value){
					case "this":
						$this->next();
						return new Node("this", [], $token->line, $token->column);
					case "super":
						$this->next();
						return new Node("super", [], $token->line, $token->column);
					case "true":
						$this->next();
						return new Node("literal", ["value" => true], $token->line, $token->column);
					case "false":
						$this->next();
						return new Node("literal", ["value" => false], $token->line, $token->column);
					case "null":
						$this->next();
						return new Node("literal", ["value" => null], $token->line, $token->column);
					case "undefined":
						$this->next();
						return new Node("literal", ["value" => null], $token->line, $token->column);
					case "function":
						return $this->parseFunctionExpression(false);
					case "class":
						return $this->parseClassExpression();
					case "new":
						return $this->parseNewExpression();
					case "import":
						$this->next();
						$this->expectPunctuator("(");
						$argument = $this->parseAssignment();
						$this->expectPunctuator(")");
						return new Node("importExpression", ["argument" => $argument], $token->line, $token->column);
					default:
						break;
				}
				break;
			default:
				break;
		}

		throw $this->error("Unexpected " . $this->describe($token));
	}

	private function parseTemplate() : Node{
		$token = $this->peek();
		if($token->type !== Token::TEMPLATE){
			throw $this->error("Expected a template literal");
		}
		$this->next();

		$rawParts = $token->value;
		$parts = [];
		if(is_array($rawParts)){
			foreach($rawParts as $part){
				if(!is_array($part)){
					continue;
				}
				$text = $part["text"] ?? null;
				$source = $part["source"] ?? null;
				if(is_string($text)){
					$parts[] = new Node("literal", ["value" => $text]);
				}elseif(is_string($source)){
					$sub = new Parser($source, $this->name);
					$parts[] = $sub->parseExpressionSource();
				}
			}
		}
		return new Node("template", ["parts" => $parts], $token->line, $token->column);
	}

	private function parseArrayLiteral() : Node{
		$start = $this->expectPunctuator("[");
		$elements = [];
		while(!$this->isPunctuator("]") && !$this->atEnd()){
			if($this->isPunctuator(",")){
				$this->next();
				$elements[] = null;
				continue;
			}
			if($this->matchPunctuator("...") !== null){
				$elements[] = new Node("spread", ["argument" => $this->parseAssignment()]);
			}else{
				$elements[] = $this->parseAssignment();
			}
			if($this->matchPunctuator(",") === null){
				break;
			}
		}
		$this->expectPunctuator("]");
		return new Node("array", ["elements" => $elements], $start->line, $start->column);
	}

	private function parseObjectLiteral() : Node{
		$start = $this->expectPunctuator("{");
		$properties = [];

		while(!$this->isPunctuator("}") && !$this->atEnd()){
			if($this->matchPunctuator("...") !== null){
				$properties[] = [
					"key" => null,
					"value" => $this->parseAssignment(),
					"computed" => false,
					"kind" => "spread"
				];
			}else{
				$kind = "init";
				if($this->isKeyword("get") || $this->isKeyword("set")){
					$lookahead = $this->peek(1);
					$isAccessor = $lookahead->type === Token::IDENTIFIER
						|| $lookahead->type === Token::STRING
						|| $lookahead->type === Token::NUMBER
						|| ($lookahead->type === Token::PUNCTUATOR && $lookahead->value === "[");
					if($isAccessor){
						$kind = $this->next()->text();
					}
				}

				$computed = $this->isPunctuator("[");
				$key = $this->parsePropertyKey();

				if($this->isPunctuator("(")){
					$params = $this->parseParameterList();
					$body = $this->parseBlock();
					$properties[] = [
						"key" => $key,
						"value" => new Node("function", [
							"id" => null,
							"params" => $params,
							"body" => $body,
							"expression" => false,
							"async" => false,
							"generator" => false,
							"arrow" => false
						], $key->line, $key->column),
						"computed" => $computed,
						"kind" => $kind === "init" ? "method" : $kind
					];
				}elseif($this->matchPunctuator(":") !== null){
					$properties[] = ["key" => $key, "value" => $this->parseAssignment(), "computed" => $computed, "kind" => "init"];
				}else{
					$properties[] = ["key" => $key, "value" => $key, "computed" => $computed, "kind" => "init"];
				}
			}

			if($this->matchPunctuator(",") === null){
				break;
			}
		}
		$this->expectPunctuator("}");
		return new Node("object", ["properties" => $properties], $start->line, $start->column);
	}

	private function parseFunctionExpression(bool $async) : Node{
		$start = $this->next();
		$generator = $this->matchPunctuator("*") !== null;
		$id = null;
		if($this->peek()->type === Token::IDENTIFIER){
			$token = $this->next();
			$id = new Node("identifier", ["name" => $token->text()], $token->line, $token->column);
		}
		$params = $this->parseParameterList();
		$body = $this->parseBlock();
		return new Node("function", [
			"id" => $id,
			"params" => $params,
			"body" => $body,
			"expression" => false,
			"async" => $async,
			"generator" => $generator,
			"arrow" => false
		], $start->line, $start->column);
	}

	private function parseClassExpression() : Node{
		$start = $this->next();
		$id = null;
		if($this->peek()->type === Token::IDENTIFIER){
			$token = $this->next();
			$id = new Node("identifier", ["name" => $token->text()], $token->line, $token->column);
		}
		$superClass = null;
		if($this->matchKeyword("extends") !== null){
			$superClass = $this->parseLeftHandSide();
		}
		$body = $this->parseClassBody();
		return new Node("classDecl", ["id" => $id, "superClass" => $superClass, "body" => $body], $start->line, $start->column);
	}

	private function parseNewExpression() : Node{
		$start = $this->next();
		$callee = $this->parsePrimary();
		while($this->isPunctuator(".") || $this->isPunctuator("[")){
			if($this->matchPunctuator(".") !== null){
				$name = $this->identifierName();
				$callee = new Node("member", [
					"object" => $callee,
					"property" => new Node("literal", ["value" => $name]),
					"computed" => false,
					"optional" => false
				]);
			}else{
				$this->next();
				$property = $this->parseExpression();
				$this->expectPunctuator("]");
				$callee = new Node("member", ["object" => $callee, "property" => $property, "computed" => true, "optional" => false]);
			}
		}
		$arguments = $this->isPunctuator("(") ? $this->parseArguments() : [];
		return new Node("new", ["callee" => $callee, "arguments" => $arguments], $start->line, $start->column);
	}
}
