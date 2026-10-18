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
use function str_ends_with;

/**
 * Evaluates the abstract syntax tree.
 *
 * The interpreter is a straightforward tree walker: it evaluates statements for their effect and expressions for
 * their value, using {@see Scope} for bindings and {@see JsObject} for objects. Control flow that would unwind in
 * JavaScript is carried by the small signal exceptions above.
 */
final class Interpreter{

	public function __construct(
		private readonly string $name = "<script>"
	){}

	/**
	 * Executes a program in the given scope.
	 */
	public function executeProgram(Node $program, Scope $scope) : void{
		try{
			$this->executeBlock($program->childList("body"), $scope, false);
		}catch(ReturnSignal){
			//a top-level return simply ends the module
		}
	}

	/**
	 * Executes a list of statements, optionally introducing a new block scope.
	 *
	 * @param list<Node> $statements
	 */
	public function executeBlock(array $statements, Scope $scope, bool $scoped = true) : void{
		$scope = $scoped ? $scope->child() : $scope;
		foreach($statements as $statement){
			$this->executeStatement($statement, $scope);
		}
	}

	/**
	 * Executes a single statement. Exposed so the module loader can hoist declarations before running a module.
	 */
	public function executeStatementPublic(Node $statement, Scope $scope) : void{
		$this->executeStatement($statement, $scope);
	}

	private function executeStatement(Node $node, Scope $scope) : void{
		switch($node->kind){
			case "empty":
				return;

			case "exprStmt":
				$this->evaluate($this->requireChild($node, "expression"), $scope);
				return;

			case "varDecl":
				$this->executeVariableDeclaration($node, $scope);
				return;

			case "block":
				$this->executeBlock($node->childList("body"), $scope);
				return;

			case "if":
				if(self::truthy($this->evaluate($this->requireChild($node, "test"), $scope))){
					$this->executeStatement($this->requireChild($node, "consequent"), $scope);
				}else{
					$alternate = $node->child("alternate");
					if($alternate !== null){
						$this->executeStatement($alternate, $scope);
					}
				}
				return;

			case "while":
				$this->executeWhile($node, $scope);
				return;

			case "doWhile":
				$this->executeDoWhile($node, $scope);
				return;

			case "for":
				$this->executeFor($node, $scope);
				return;

			case "forOf":
				$this->executeForOf($node, $scope);
				return;

			case "forIn":
				$this->executeForIn($node, $scope);
				return;

			case "return":
				$argument = $node->child("argument");
				throw new ReturnSignal($argument === null ? JsUndefined::instance() : $this->evaluate($argument, $scope));

			case "break":
				throw new BreakSignal($node->nullableStringField("label"));

			case "continue":
				throw new ContinueSignal($node->nullableStringField("label"));

			case "throw":
				throw new JsThrow($this->evaluate($this->requireChild($node, "argument"), $scope));

			case "try":
				$this->executeTry($node, $scope);
				return;

			case "switch":
				$this->executeSwitch($node, $scope);
				return;

			case "functionDecl":
				$id = $node->child("id");
				if($id !== null){
					$name = $id->stringField("name");
					$scope->declare($name, $this->makeFunction($node, $scope, $name));
				}
				return;

			case "classDecl":
				$this->executeClassDeclaration($node, $scope);
				return;

			case "importDecl":
				//modules are resolved before execution; nothing to do at run time
				return;

			case "exportDecl":
				$declaration = $node->child("declaration");
				if($declaration !== null){
					$this->executeStatement($declaration, $scope);
				}
				return;

			default:
				throw $this->error($node, "Unsupported statement \"" . $node->kind . "\"");
		}
	}

	private function executeVariableDeclaration(Node $node, Scope $scope) : void{
		$kind = $node->stringField("declKind", "var");
		$declarations = $node->recordList("declarations");
		foreach($declarations as $declaration){
			if(!\is_array($declaration)){
				continue;
			}
			$target = $declaration["id"] ?? null;
			$init = $declaration["init"] ?? null;
			if(!$target instanceof Node){
				continue;
			}
			$value = $init instanceof Node ? $this->evaluate($init, $scope) : JsUndefined::instance();
			$this->bindPattern($target, $value, $scope, $kind === "const");
		}
	}

	/**
	 * Binds a declaration or assignment target, which may be an identifier or a destructuring pattern.
	 */
	public function bindPattern(Node $target, mixed $value, Scope $scope, bool $constant = false, bool $assignOnly = false) : void{
		switch($target->kind){
			case "identifier":
				$name = $target->stringField("name");
				if($assignOnly){
					if(!$scope->assign($name, $value)){
						$scope->declare($name, $value);
					}
				}else{
					$scope->declare($name, $value, $constant);
				}
				return;

			case "arrayPattern":
				$elements = $target->field("elements", []);
				if(!\is_array($elements)){
					return;
				}
				$elements = \array_values($elements);
				$count = \count($elements);
				foreach($elements as $index => $element){
					if($element === null || !$element instanceof Node){
						continue;
					}
					if($element->kind === "spread"){
						$rest = [];
						$source = self::arrayElements($value);
						for($i = $index; $i < \count($source); $i++){
							$rest[] = $source[$i];
						}
						$argument = $element->child("argument");
						if($argument !== null){
							$this->bindPattern($argument, new JsArray($rest), $scope, $constant, $assignOnly);
						}
						break;
					}
					$this->bindPattern($element, self::elementAt($value, $index), $scope, $constant, $assignOnly);
					if($index === $count - 1){
						break;
					}
				}
				return;

			case "objectPattern":
				$properties = $target->field("properties", []);
				if(!\is_array($properties)){
					return;
				}
				$used = [];
				foreach($properties as $property){
					if(!\is_array($property)){
						continue;
					}
					$keyNode = $property["key"] ?? null;
					$keyNode = $keyNode instanceof Node ? $keyNode : null;
					$valueNode = $property["value"] ?? null;
					if(!$valueNode instanceof Node){
						continue;
					}
					if(($property["kind"] ?? "") === "rest"){
						$rest = new JsObject();
						foreach(self::objectKeys($value) as $key){
							if(!\in_array($key, $used, true)){
								$rest->set($key, self::getProperty($value, $key));
							}
						}
						$this->bindPattern($valueNode, $rest, $scope, $constant, $assignOnly);
						continue;
					}
					$key = $this->propertyKeyName($keyNode, $scope);
					$used[] = $key;
					$this->bindPattern($valueNode, self::getProperty($value, $key), $scope, $constant, $assignOnly);
				}
				return;

			case "assignment":
				//default value inside a pattern: only applied when the value is undefined
				if($value === JsUndefined::instance() || $value instanceof JsUndefined){
					$right = $target->child("right");
					$value = $right === null ? JsUndefined::instance() : $this->evaluate($right, $scope);
				}
				$left = $target->child("left");
				if($left !== null){
					$this->bindPattern($left, $value, $scope, $constant, $assignOnly);
				}
				return;

			default:
				throw $this->error($target, "Invalid assignment target \"" . $target->kind . "\"");
		}
	}

	private function propertyKeyName(?Node $keyNode, Scope $scope) : string{
		if($keyNode === null){
			return "";
		}
		if($keyNode->kind === "computedKey"){
			$expression = $keyNode->child("expression");
			return self::toString($expression === null ? JsUndefined::instance() : $this->evaluate($expression, $scope));
		}
		return self::toString($this->evaluate($keyNode, $scope));
	}

	private function executeWhile(Node $node, Scope $scope) : void{
		$test = $this->requireChild($node, "test");
		$body = $this->requireChild($node, "body");
		while(self::truthy($this->evaluate($test, $scope))){
			try{
				$this->executeStatement($body, $scope);
			}catch(ContinueSignal){
				continue;
			}catch(BreakSignal){
				return;
			}
		}
	}

	private function executeDoWhile(Node $node, Scope $scope) : void{
		$test = $this->requireChild($node, "test");
		$body = $this->requireChild($node, "body");
		do{
			try{
				$this->executeStatement($body, $scope);
			}catch(ContinueSignal){
				continue;
			}catch(BreakSignal){
				return;
			}
		}while(self::truthy($this->evaluate($test, $scope)));
	}

	private function executeFor(Node $node, Scope $scope) : void{
		$loopScope = $scope->child();
		$init = $node->child("init");
		if($init !== null){
			if($init->kind === "varDecl"){
				$this->executeStatement($init, $loopScope);
			}else{
				$this->evaluate($init, $loopScope);
			}
		}
		$test = $node->child("test");
		$update = $node->child("update");
		$body = $this->requireChild($node, "body");

		while($test === null || self::truthy($this->evaluate($test, $loopScope))){
			try{
				$this->executeStatement($body, $loopScope);
			}catch(ContinueSignal){
				//fall through to the update expression
			}catch(BreakSignal){
				return;
			}
			if($update !== null){
				$this->evaluate($update, $loopScope);
			}
		}
	}

	private function executeForOf(Node $node, Scope $scope) : void{
		$right = $this->requireChild($node, "right");
		$left = $node->child("left");
		$body = $this->requireChild($node, "body");
		$declKind = $node->nullableStringField("declKind");
		$iterable = $this->evaluate($right, $scope);

		foreach($this->iterate($iterable) as $value){
			$loopScope = $scope->child();
			if($left !== null){
				$this->bindPattern($left, $value, $loopScope, $declKind === "const", $declKind === null);
			}
			try{
				$this->executeStatement($body, $loopScope);
			}catch(ContinueSignal){
				continue;
			}catch(BreakSignal){
				return;
			}
		}
	}

	private function executeForIn(Node $node, Scope $scope) : void{
		$right = $this->requireChild($node, "right");
		$left = $node->child("left");
		$body = $this->requireChild($node, "body");
		$declKind = $node->nullableStringField("declKind");
		$target = $this->evaluate($right, $scope);

		foreach(self::objectKeys($target) as $key){
			$loopScope = $scope->child();
			if($left !== null){
				$this->bindPattern($left, $key, $loopScope, $declKind === "const", $declKind === null);
			}
			try{
				$this->executeStatement($body, $loopScope);
			}catch(ContinueSignal){
				continue;
			}catch(BreakSignal){
				return;
			}
		}
	}

	/**
	 * @return list<mixed>
	 */
	private function iterate(mixed $value) : array{
		if($value instanceof JsArray){
			return $value->elements;
		}
		if(\is_array($value)){
			return \array_values($value);
		}
		if($value instanceof JsObject){
			$iterator = $value->get("__iterate");
			if($iterator instanceof JsFunction){
				$result = $this->invokeFunction($iterator, [], $value);
				if($result instanceof JsArray){
					return $result->elements;
				}
			}
			if($value->has("length")){
				$length = (int) self::toNumber($value->get("length"));
				$out = [];
				for($i = 0; $i < $length; $i++){
					$out[] = $value->get((string) $i);
				}
				return $out;
			}
			return [];
		}
		if(\is_string($value)){
			return \str_split($value);
		}
		return [];
	}

	private function executeTry(Node $node, Scope $scope) : void{
		$block = $this->requireChild($node, "block");
		$handler = $node->field("handler");
		$finalizer = $node->child("finalizer");

		try{
			try{
				$this->executeStatement($block, $scope);
			}catch(JsThrow $throw){
				if(!\is_array($handler)){
					throw $throw;
				}
				$catchScope = $scope->child();
				$param = $handler["param"] ?? null;
				if($param instanceof Node){
					$this->bindPattern($param, $throw->value, $catchScope);
				}
				$body = $handler["body"] ?? null;
				if($body instanceof Node){
					$this->executeStatement($body, $catchScope);
				}
			}catch(\Error $error){
				if(!\is_array($handler)){
					throw new ScriptEngineException($error->getMessage(), 0, 0, $this->name);
				}
				$catchScope = $scope->child();
				$param = $handler["param"] ?? null;
				if($param instanceof Node){
					$this->bindPattern($param, $error->getMessage(), $catchScope);
				}
				$body = $handler["body"] ?? null;
				if($body instanceof Node){
					$this->executeStatement($body, $catchScope);
				}
			}
		}finally{
			if($finalizer !== null){
				$this->executeStatement($finalizer, $scope);
			}
		}
	}

	private function executeSwitch(Node $node, Scope $scope) : void{
		$discriminant = self::toStrictValue($this->evaluate($this->requireChild($node, "discriminant"), $scope));
		$cases = $node->recordList("cases");

		$matched = false;
		try{
			foreach($cases as $case){
				if(!\is_array($case)){
					continue;
				}
				$test = $case["test"] ?? null;
				if(!$matched){
					if($test === null){
						$matched = true;
					}elseif($test instanceof Node){
						$candidate = self::toStrictValue($this->evaluate($test, $scope));
						$matched = $candidate === $discriminant;
					}
				}
				if(!$matched){
					continue;
				}
				$body = $case["body"] ?? [];
				if(!\is_array($body)){
					continue;
				}
				foreach($body as $statement){
					if($statement instanceof Node){
						$this->executeStatement($statement, $scope);
					}
				}
			}
		}catch(BreakSignal){
			return;
		}
	}

	private function executeClassDeclaration(Node $node, Scope $scope) : void{
		$id = $node->child("id");
		$superClass = $node->child("superClass");
		$parent = $superClass === null ? null : $this->evaluate($superClass, $scope);

		$prototype = new JsObject();
		$constructor = null;

		foreach($node->childList("body") as $member){
			if($member->kind === "methodDef" && $member->stringField("kind", "method") === "constructor" && !$member->boolField("static")){
				$constructor = $this->makeFunction($member, $scope, "constructor", $prototype);
				continue;
			}
			$this->defineClassMember($member, $scope, $prototype, null);
		}

		if($constructor === null){
			$constructor = new JsFunction(
				name: $id === null ? "anonymous" : $id->stringField("name"),
				body: null,
				params: [],
				scope: $scope,
				native: static function() use ($parent) : mixed{
					if($parent instanceof JsFunction){
						return null;
					}
					return null;
				}
			);
		}

		$constructor->set("prototype", $prototype);
		$prototype->set("constructor", $constructor);
		if($parent instanceof JsObject){
			$parentPrototype = $parent->get("prototype");
			$prototype->prototype = $parentPrototype instanceof JsObject ? $parentPrototype : $parent;
		}

		if($id !== null){
			$name = $id->stringField("name");
			$constructor->properties["name"] = $name;
			$scope->declare($name, $constructor);
		}
	}

	private function defineClassMember(Node $member, Scope $scope, JsObject $prototype, ?JsObject $staticTarget) : void{
		if($member->kind === "propertyDef"){
			return;
		}
		if($member->kind !== "methodDef"){
			return;
		}
		$key = $this->propertyKeyName($member->child("key"), $scope);
		$target = $member->boolField("static") ? $staticTarget : $prototype;
		if($target === null){
			return;
		}
		$target->set($key, $this->makeFunction($member, $scope, $key));
	}

	private function makeFunction(Node $node, Scope $scope, string $name, ?JsObject $boundThis = null) : JsFunction{
		$params = [];
		foreach($node->childList("params") as $param){
			$params[] = $param;
		}
		$body = $node->child("body");

		//arrow functions with an expression body are parsed as a synthetic "return" statement
		$expressionBody = null;
		if($node->boolField("arrow") && $body !== null && $body->kind === "return"){
			$expressionBody = $body->child("argument");
			$body = null;
		}

		$function = new JsFunction(
			name: $name,
			body: $body,
			params: $params,
			scope: $scope,
			arrow: $node->boolField("arrow"),
			async: $node->boolField("async"),
			expressionBody: $expressionBody
		);
		if($boundThis !== null){
			$function->thisBinding = $boundThis;
		}
		return $function;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Expressions
	// ---------------------------------------------------------------------------------------------------------

	public function evaluate(Node $node, Scope $scope) : mixed{
		switch($node->kind){
			case "literal":
				return $node->field("value");

			case "identifier":
				$name = $node->stringField("name");
				return $scope->get($name);

			case "this":
				return $scope->get("this");

			case "super":
				return $scope->get("super");

			case "template":
				return $this->evaluateTemplate($node, $scope);

			case "regex":
				return new JsObject([
					"__regex" => true,
					"source" => $node->stringField("pattern"),
					"flags" => $node->stringField("flags")
				]);

			case "array":
				return $this->evaluateArray($node, $scope);

			case "object":
				return $this->evaluateObject($node, $scope);

			case "function":
				return $this->evaluateFunctionExpression($node, $scope);

			case "classDecl":
				$declaration = $node;
				$this->executeClassDeclaration($declaration, $scope);
				$id = $declaration->child("id");
				return $id === null ? JsUndefined::instance() : $scope->get($id->stringField("name"));

			case "member":
				return $this->evaluateMember($node, $scope);

			case "call":
				return $this->evaluateCall($node, $scope);

			case "new":
				return $this->evaluateNew($node, $scope);

			case "assignment":
				return $this->evaluateAssignment($node, $scope);

			case "update":
				return $this->evaluateUpdate($node, $scope);

			case "unary":
				return $this->evaluateUnary($node, $scope);

			case "binary":
				return $this->evaluateBinary($node, $scope);

			case "logical":
				return $this->evaluateLogical($node, $scope);

			case "conditional":
				return self::truthy($this->evaluate($this->requireChild($node, "test"), $scope))
					? $this->evaluate($this->requireChild($node, "consequent"), $scope)
					: $this->evaluate($this->requireChild($node, "alternate"), $scope);

			case "sequence":
				$result = JsUndefined::instance();
				foreach($node->childList("expressions") as $expression){
					$result = $this->evaluate($expression, $scope);
				}
				return $result;

			case "spread":
				return $this->evaluate($this->requireChild($node, "argument"), $scope);

			case "await":
				return $this->evaluate($this->requireChild($node, "argument"), $scope);

			case "yield":
				$argument = $node->child("argument");
				return $argument === null ? JsUndefined::instance() : $this->evaluate($argument, $scope);

			case "importExpression":
				$argument = $this->requireChild($node, "argument");
				$importer = $scope->get("@import");
				return $importer instanceof JsFunction
					? $this->invokeFunction($importer, [self::toString($this->evaluate($argument, $scope))], null)
					: JsUndefined::instance();

			case "taggedTemplate":
				return JsUndefined::instance();

			case "computedKey":
				$expression = $node->child("expression");
				return $expression === null ? JsUndefined::instance() : $this->evaluate($expression, $scope);

			default:
				throw $this->error($node, "Unsupported expression \"" . $node->kind . "\"");
		}
	}

	private function evaluateTemplate(Node $node, Scope $scope) : string{
		$result = "";
		foreach($node->childList("parts") as $part){
			$result .= self::toString($this->evaluate($part, $scope));
		}
		return $result;
	}

	private function evaluateArray(Node $node, Scope $scope) : JsArray{
		$elements = [];
		$rawElements = $node->field("elements", []);
		if(!\is_array($rawElements)){
			return new JsArray();
		}
		foreach($rawElements as $element){
			if($element === null){
				$elements[] = JsUndefined::instance();
				continue;
			}
			if(!$element instanceof Node){
				continue;
			}
			if($element->kind === "spread"){
				$spread = $this->evaluate($this->requireChild($element, "argument"), $scope);
				foreach($this->iterate($spread) as $item){
					$elements[] = $item;
				}
				continue;
			}
			$elements[] = $this->evaluate($element, $scope);
		}
		return new JsArray($elements);
	}

	private function evaluateObject(Node $node, Scope $scope) : JsObject{
		$object = new JsObject();
		$properties = $node->recordList("properties");
		foreach($properties as $property){
			if(!\is_array($property)){
				continue;
			}
			if(($property["kind"] ?? "") === "spread"){
				$value = $property["value"] ?? null;
				if($value instanceof Node){
					$source = $this->evaluate($value, $scope);
					foreach(self::objectKeys($source) as $key){
						$object->set($key, self::getProperty($source, $key));
					}
				}
				continue;
			}
			$keyNode = $property["key"] ?? null;
			$valueNode = $property["value"] ?? null;
			if(!$keyNode instanceof Node || !$valueNode instanceof Node){
				continue;
			}
			$key = $this->propertyKeyName($keyNode, $scope);
			if(($property["kind"] ?? "") === "get" || ($property["kind"] ?? "") === "set"){
				continue;
			}
			$object->set($key, $this->evaluate($valueNode, $scope));
		}
		return $object;
	}

	private function evaluateFunctionExpression(Node $node, Scope $scope) : JsFunction{
		$id = $node->child("id");
		$name = $id === null ? "anonymous" : $id->stringField("name");
		$functionScope = $scope;
		$function = $this->makeFunction($node, $functionScope, $name);
		if($id !== null){
			$named = $functionScope->child();
			$named->declare($name, $function);
			$function = new JsFunction(
				name: $name,
				body: $function->body,
				params: $function->params,
				scope: $named,
				arrow: $function->arrow,
				async: $function->async,
				expressionBody: $function->expressionBody
			);
		}
		return $function;
	}

	private function evaluateMember(Node $node, Scope $scope) : mixed{
		$objectNode = $this->requireChild($node, "object");
		$object = $this->evaluate($objectNode, $scope);
		$optional = $node->boolField("optional");
		if($optional && ($object === null || $object instanceof JsUndefined)){
			return JsUndefined::instance();
		}

		$propertyNode = $this->requireChild($node, "property");
		$key = $node->boolField("computed")
			? self::toString($this->evaluate($propertyNode, $scope))
			: $propertyNode->stringField("value");

		$value = self::getProperty($object, $key);
		if($value instanceof JsFunction && !$value->arrow && $value->isNative()){
			$value->thisBinding = $object instanceof JsObject ? $object : null;
		}
		return $value;
	}

	private function evaluateCall(Node $node, Scope $scope) : mixed{
		$calleeNode = $this->requireChild($node, "callee");
		$thisBinding = null;

		if($calleeNode->kind === "member"){
			$object = $this->evaluate($this->requireChild($calleeNode, "object"), $scope);
			$optional = $calleeNode->boolField("optional");
			if($optional && ($object === null || $object instanceof JsUndefined)){
				return JsUndefined::instance();
			}
			$propertyNode = $this->requireChild($calleeNode, "property");
			$key = $calleeNode->boolField("computed")
				? self::toString($this->evaluate($propertyNode, $scope))
				: $propertyNode->stringField("value");
			$callee = self::getProperty($object, $key);
			$thisBinding = $object instanceof JsObject ? $object : null;
		}else{
			$callee = $this->evaluate($calleeNode, $scope);
		}

		$arguments = [];
		foreach($node->childList("arguments") as $argument){
			if($argument->kind === "spread"){
				$spread = $this->evaluate($this->requireChild($argument, "argument"), $scope);
				foreach($this->iterate($spread) as $item){
					$arguments[] = $item;
				}
				continue;
			}
			$arguments[] = $this->evaluate($argument, $scope);
		}

		return $this->callValue($callee, $arguments, $thisBinding);
	}

	/**
	 * Calls any callable value, throwing a JavaScript TypeError when it is not callable.
	 *
	 * @param list<mixed> $arguments
	 */
	public function callValue(mixed $callee, array $arguments, ?JsObject $thisBinding) : mixed{
		if($callee instanceof JsFunction){
			return $this->invokeFunction($callee, $arguments, $thisBinding);
		}
		if($callee instanceof Closure){
			return $callee(...$arguments);
		}
		throw new JsThrow("TypeError: value is not a function");
	}

	/**
	 * @param list<mixed> $arguments
	 */
	public function invokeFunction(JsFunction $function, array $arguments, ?JsObject $thisBinding) : mixed{
		if($function->native !== null){
			$native = $function->native;
			return $native($arguments, $thisBinding ?? $function->thisBinding, $this);
		}

		$scope = ($function->scope ?? new Scope())->child();
		$scope->declare("this", $function->arrow ? ($function->thisBinding ?? JsUndefined::instance()) : ($thisBinding ?? JsUndefined::instance()));

		foreach($function->params as $index => $param){
			if($param->kind === "spread"){
				$rest = [];
				for($i = $index; $i < \count($arguments); $i++){
					$rest[] = $arguments[$i];
				}
				$argument = $param->child("argument");
				if($argument !== null){
					$this->bindPattern($argument, new JsArray($rest), $scope);
				}
				break;
			}
			$value = $arguments[$index] ?? JsUndefined::instance();
			if($param->kind === "assignment"){
				$left = $param->child("left");
				$default = $param->child("right");
				if($value === JsUndefined::instance() || $value instanceof JsUndefined){
					$value = $default === null ? JsUndefined::instance() : $this->evaluate($default, $scope);
				}
				if($left !== null){
					$this->bindPattern($left, $value, $scope);
				}
				continue;
			}
			$this->bindPattern($param, $value, $scope);
		}

		if($function->expressionBody !== null){
			return $this->evaluate($function->expressionBody, $scope);
		}
		if($function->body === null){
			return JsUndefined::instance();
		}
		try{
			$this->executeBlock($function->body->childList("body"), $scope, false);
		}catch(ReturnSignal $signal){
			return $signal->value;
		}
		return JsUndefined::instance();
	}

	private function evaluateNew(Node $node, Scope $scope) : mixed{
		$callee = $this->evaluate($this->requireChild($node, "callee"), $scope);
		$arguments = [];
		foreach($node->childList("arguments") as $argument){
			if($argument->kind === "spread"){
				foreach($this->iterate($this->evaluate($this->requireChild($argument, "argument"), $scope)) as $item){
					$arguments[] = $item;
				}
				continue;
			}
			$arguments[] = $this->evaluate($argument, $scope);
		}

		if(!$callee instanceof JsFunction){
			throw new JsThrow("TypeError: value is not a constructor");
		}

		$instance = new JsObject();
		$prototype = $callee->get("prototype");
		if($prototype instanceof JsObject){
			$instance->prototype = $prototype;
		}
		$result = $this->invokeFunction($callee, $arguments, $instance);
		if($result instanceof JsObject){
			return $result;
		}
		return $instance;
	}

	private function evaluateAssignment(Node $node, Scope $scope) : mixed{
		$operator = $node->stringField("operator", "=");
		$left = $this->requireChild($node, "left");
		$right = $this->requireChild($node, "right");

		if($operator === "="){
			$value = $this->evaluate($right, $scope);
			$this->assignTo($left, $value, $scope);
			return $value;
		}

		if($operator === "&&=" || $operator === "||=" || $operator === "??="){
			$current = $this->evaluate($left, $scope);
			$shouldAssign = match($operator){
				"&&=" => self::truthy($current),
				"||=" => !self::truthy($current),
				default => $current === null || $current instanceof JsUndefined
			};
			if(!$shouldAssign){
				return $current;
			}
			$value = $this->evaluate($right, $scope);
			$this->assignTo($left, $value, $scope);
			return $value;
		}

		$current = $this->evaluate($left, $scope);
		$value = $this->evaluate($right, $scope);
		$result = $this->applyBinary(self::compoundOperator($operator), $current, $value);
		$this->assignTo($left, $result, $scope);
		return $result;
	}

	private function assignTo(Node $target, mixed $value, Scope $scope) : void{
		if($target->kind === "member"){
			$object = $this->evaluate($this->requireChild($target, "object"), $scope);
			$propertyNode = $this->requireChild($target, "property");
			$key = $target->boolField("computed")
				? self::toString($this->evaluate($propertyNode, $scope))
				: $propertyNode->stringField("value");
			self::setProperty($object, $key, $value);
			return;
		}
		if($target->kind === "identifier"){
			$name = $target->stringField("name");
			if($scope->isConstant($name)){
				throw new JsThrow("TypeError: Assignment to constant variable \"" . $name . "\"");
			}
			if(!$scope->assign($name, $value)){
				$scope->declare($name, $value);
			}
			return;
		}
		$this->bindPattern($target, $value, $scope, false, true);
	}

	private function evaluateUpdate(Node $node, Scope $scope) : mixed{
		$operator = $node->stringField("operator", "++");
		$prefix = $node->boolField("prefix", true);
		$argument = $this->requireChild($node, "argument");

		$old = self::toNumber($this->evaluate($argument, $scope));
		$new = $operator === "++" ? $old + 1 : $old - 1;
		$this->assignTo($argument, $new, $scope);
		return $prefix ? $new : $old;
	}

	private function evaluateUnary(Node $node, Scope $scope) : mixed{
		$operator = $node->stringField("operator");
		$argument = $this->requireChild($node, "argument");

		if($operator === "typeof"){
			if($argument->kind === "identifier"){
				$name = $argument->stringField("name");
				if(!$scope->has($name)){
					return "undefined";
				}
			}
			return self::typeOf($this->evaluate($argument, $scope));
		}
		if($operator === "delete"){
			if($argument->kind === "member"){
				$object = $this->evaluate($this->requireChild($argument, "object"), $scope);
				$propertyNode = $this->requireChild($argument, "property");
				$key = $argument->boolField("computed")
					? self::toString($this->evaluate($propertyNode, $scope))
					: $propertyNode->stringField("value");
				if($object instanceof JsObject){
					return $object->delete($key);
				}
			}
			return true;
		}

		$value = $this->evaluate($argument, $scope);
		return match($operator){
			"!" => !self::truthy($value),
			"~" => ~(int) self::toNumber($value),
			"+" => self::toNumber($value),
			"-" => -self::toNumber($value),
			"void" => JsUndefined::instance(),
			default => throw $this->error($node, "Unsupported unary operator \"" . $operator . "\"")
		};
	}

	private function evaluateBinary(Node $node, Scope $scope) : mixed{
		$operator = $node->stringField("operator");
		$left = $this->evaluate($this->requireChild($node, "left"), $scope);
		$right = $this->evaluate($this->requireChild($node, "right"), $scope);
		return $this->applyBinary($operator, $left, $right);
	}

	/**
	 * Strips the assignment half of a compound operator, so "+=" evaluates as "+".
	 */
	private static function compoundOperator(string $operator) : string{
		return str_ends_with($operator, "=") ? substr($operator, 0, -1) : $operator;
	}

	public function applyBinary(string $operator, mixed $left, mixed $right) : mixed{
		return match($operator){
			"+" => self::add($left, $right),
			"-" => self::toNumber($left) - self::toNumber($right),
			"*" => self::toNumber($left) * self::toNumber($right),
			"/" => self::toNumber($left) / self::toNumber($right),
			"%" => \fmod(self::toNumber($left), self::toNumber($right)),
			"**" => self::toNumber($left) ** self::toNumber($right),
			"==" => self::looseEquals($left, $right),
			"!=" => !self::looseEquals($left, $right),
			"===" => self::toStrictValue($left) === self::toStrictValue($right),
			"!==" => self::toStrictValue($left) !== self::toStrictValue($right),
			"<" => self::compare($left, $right) < 0,
			">" => self::compare($left, $right) > 0,
			"<=" => self::compare($left, $right) <= 0,
			">=" => self::compare($left, $right) >= 0,
			"&" => (int) self::toNumber($left) & (int) self::toNumber($right),
			"|" => (int) self::toNumber($left) | (int) self::toNumber($right),
			"^" => (int) self::toNumber($left) ^ (int) self::toNumber($right),
			"<<" => (int) self::toNumber($left) << (int) self::toNumber($right),
			">>" => (int) self::toNumber($left) >> (int) self::toNumber($right),
			">>>" => ((int) self::toNumber($left)) >> (int) self::toNumber($right),
			"instanceof" => self::instanceOf($left, $right),
			"in" => self::hasProperty($right, self::toString($left)),
			default => throw new JsThrow("Unsupported operator \"" . $operator . "\"")
		};
	}

	private function evaluateLogical(Node $node, Scope $scope) : mixed{
		$operator = $node->stringField("operator");
		$left = $this->evaluate($this->requireChild($node, "left"), $scope);
		return match($operator){
			"&&" => self::truthy($left) ? $this->evaluate($this->requireChild($node, "right"), $scope) : $left,
			"||" => self::truthy($left) ? $left : $this->evaluate($this->requireChild($node, "right"), $scope),
			default => ($left === null || $left instanceof JsUndefined)
				? $this->evaluate($this->requireChild($node, "right"), $scope)
				: $left
		};
	}

	private function requireChild(Node $node, string $name) : Node{
		$child = $node->child($name);
		if($child === null){
			throw $this->error($node, "Malformed node: missing \"" . $name . "\"");
		}
		return $child;
	}

	private function error(Node $node, string $message) : ScriptEngineException{
		return new ScriptEngineException($message, $node->line, $node->column, $this->name);
	}

	// ---------------------------------------------------------------------------------------------------------
	// Value semantics
	// ---------------------------------------------------------------------------------------------------------

	public static function truthy(mixed $value) : bool{
		if($value === null || $value instanceof JsUndefined){
			return false;
		}
		if(\is_bool($value)){
			return $value;
		}
		if(\is_int($value) || \is_float($value)){
			return $value !== 0 && $value !== 0.0 && !\is_nan((float) $value);
		}
		if(\is_string($value)){
			return $value !== "";
		}
		return true;
	}

	public static function toNumber(mixed $value) : int|float{
		if(\is_int($value) || \is_float($value)){
			return $value;
		}
		if(\is_bool($value)){
			return $value ? 1 : 0;
		}
		if($value === null){
			return 0;
		}
		if($value instanceof JsUndefined){
			return \NAN;
		}
		if(\is_string($value)){
			$trimmed = \trim($value);
			if($trimmed === ""){
				return 0;
			}
			if(\is_numeric($trimmed)){
				return $trimmed + 0;
			}
			return \NAN;
		}
		if($value instanceof JsObject){
			if($value->has("valueOf")){
				$valueOf = $value->get("valueOf");
				if($valueOf instanceof JsFunction){
					$primitive = (new self())->invokeFunction($valueOf, [], $value);
					if(!$primitive instanceof JsObject){
						return self::toNumber($primitive);
					}
				}
			}
			return \NAN;
		}
		return \NAN;
	}

	public static function toString(mixed $value) : string{
		if(\is_string($value)){
			return $value;
		}
		if($value === null){
			return "null";
		}
		if($value instanceof JsUndefined){
			return "undefined";
		}
		if(\is_bool($value)){
			return $value ? "true" : "false";
		}
		if(\is_int($value)){
			return (string) $value;
		}
		if(\is_float($value)){
			return self::formatNumber($value);
		}
		if($value instanceof JsArray){
			$parts = [];
			foreach($value->elements as $element){
				$parts[] = ($element === null || $element instanceof JsUndefined) ? "" : self::toString($element);
			}
			return \implode(",", $parts);
		}
		if($value instanceof JsObject){
			if($value->has("__nativeString")){
				$native = $value->get("__nativeString");
				if($native instanceof JsFunction){
					return self::toString((new self())->invokeFunction($native, [], $value));
				}
			}
			return "[object Object]";
		}
		if(\is_array($value)){
			return "[object Object]";
		}
		return "[object Object]";
	}

	public static function formatNumber(float $value) : string{
		if(\is_nan($value)){
			return "NaN";
		}
		if(\is_infinite($value)){
			return $value > 0 ? "Infinity" : "-Infinity";
		}
		if($value === \floor($value) && \abs($value) < 1.0E21){
			return (string) (int) $value;
		}
		$formatted = \rtrim(\rtrim(\sprintf("%.14F", $value), "0"), ".");
		return $formatted === "" || $formatted === "-" ? "0" : $formatted;
	}

	public static function add(mixed $left, mixed $right) : mixed{
		if($left instanceof JsArray || $right instanceof JsArray){
			return self::toString($left) . self::toString($right);
		}
		if(\is_string($left) || \is_string($right)){
			return self::toString($left) . self::toString($right);
		}
		if($left instanceof JsObject && $left->has("valueOf")){
			return self::add(self::toNumber($left), $right);
		}
		if($right instanceof JsObject && $right->has("valueOf")){
			return self::add($left, self::toNumber($right));
		}
		$a = self::toNumber($left);
		$b = self::toNumber($right);
		return ((\is_int($a) && \is_int($b)) ? $a + $b : (float) $a + (float) $b);
	}

	public static function toStrictValue(mixed $value) : mixed{
		if($value instanceof JsUndefined){
			return "undefined";
		}
		if(\is_float($value) && \is_nan($value)){
			return "NaN";
		}
		return $value;
	}

	public static function looseEquals(mixed $left, mixed $right) : bool{
		if($left === null && $right instanceof JsUndefined){
			return true;
		}
		if($right === null && $left instanceof JsUndefined){
			return true;
		}
		if(\is_bool($left) || \is_bool($right)){
			return self::truthy($left) === self::truthy($right);
		}
		if((\is_int($left) || \is_float($left)) && \is_string($right)){
			return self::toNumber($left) === self::toNumber($right);
		}
		if((\is_int($right) || \is_float($right)) && \is_string($left)){
			return self::toNumber($left) === self::toNumber($right);
		}
		return self::toStrictValue($left) === self::toStrictValue($right);
	}

	public static function compare(mixed $left, mixed $right) : int{
		if(\is_string($left) && \is_string($right)){
			return \strcmp($left, $right) <=> 0;
		}
		$a = self::toNumber($left);
		$b = self::toNumber($right);
		if(\is_nan((float) $a) || \is_nan((float) $b)){
			return 1;
		}
		return $a <=> $b;
	}

	public static function typeOf(mixed $value) : string{
		if($value instanceof JsUndefined){
			return "undefined";
		}
		if($value === null){
			return "object";
		}
		if(\is_bool($value)){
			return "boolean";
		}
		if(\is_int($value) || \is_float($value)){
			return "number";
		}
		if(\is_string($value)){
			return "string";
		}
		if($value instanceof JsFunction){
			return "function";
		}
		return "object";
	}

	public static function instanceOf(mixed $value, mixed $constructor) : bool{
		if(!$value instanceof JsObject || !$constructor instanceof JsFunction){
			return false;
		}
		$prototype = $constructor->get("prototype");
		if(!$prototype instanceof JsObject){
			return false;
		}
		$cursor = $value->prototype;
		while($cursor !== null){
			if($cursor === $prototype){
				return true;
			}
			$cursor = $cursor->prototype;
		}
		return false;
	}

	/**
	 * @return list<mixed>
	 */
	public static function arrayElements(mixed $value) : array{
		if($value instanceof JsArray){
			return $value->elements;
		}
		if(\is_array($value)){
			return \array_values($value);
		}
		if($value instanceof JsObject){
			if($value->has("length")){
				$length = (int) self::toNumber($value->get("length"));
				$out = [];
				for($i = 0; $i < $length; $i++){
					$out[] = $value->get((string) $i);
				}
				return $out;
			}
			return [];
		}
		return [];
	}

	public static function elementAt(mixed $value, int $index) : mixed{
		if($value instanceof JsArray){
			return $value->elements[$index] ?? JsUndefined::instance();
		}
		if(\is_string($value)){
			return $value[$index] ?? JsUndefined::instance();
		}
		return self::getProperty($value, (string) $index);
	}

	public static function getProperty(mixed $object, string $key) : mixed{
		if($object instanceof JsObject){
			return $object->get($key);
		}
		if($object instanceof JsArray){
			if($key === "length"){
				return $object->length();
			}
			if(\is_numeric($key)){
				return $object->elements[(int) $key] ?? JsUndefined::instance();
			}
			return Builtins::arrayMethod($object, $key);
		}
		if(\is_string($object)){
			if($key === "length"){
				return \strlen($object);
			}
			if(\is_numeric($key)){
				return $object[(int) $key] ?? JsUndefined::instance();
			}
			return Builtins::stringMethod($object, $key);
		}
		if(\is_int($object) || \is_float($object)){
			return Builtins::numberMethod($object, $key);
		}
		if($object === null || $object instanceof JsUndefined){
			throw new JsThrow("TypeError: Cannot read properties of " . self::toString($object) . " (reading \"" . $key . "\")");
		}
		return JsUndefined::instance();
	}

	public static function setProperty(mixed $object, string $key, mixed $value) : void{
		if($object instanceof JsObject){
			$object->set($key, $value);
			return;
		}
		if($object instanceof JsArray){
			if($key === "length"){
				$length = (int) self::toNumber($value);
				$object->elements = \array_values(\array_slice($object->elements, 0, $length));
				return;
			}
			if(\is_numeric($key)){
				//keep the list dense: writing past the end pads with undefined
				$index = (int) $key;
				$elements = $object->elements;
				while(\count($elements) < $index){
					$elements[] = JsUndefined::instance();
				}
				$elements[$index] = $value;
				$object->elements = \array_values($elements);
				return;
			}
			return;
		}
		throw new JsThrow("TypeError: Cannot set properties of " . self::toString($object));
	}

	public static function hasProperty(mixed $object, string $key) : bool{
		if($object instanceof JsObject){
			return $object->has($key);
		}
		if($object instanceof JsArray){
			return $key === "length" || \is_numeric($key);
		}
		if(\is_string($object)){
			return $key === "length" || \is_numeric($key);
		}
		return false;
	}

	/**
	 * @return list<string>
	 */
	public static function objectKeys(mixed $value) : array{
		if($value instanceof JsArray){
			$keys = [];
			for($i = 0; $i < $value->length(); $i++){
				$keys[] = (string) $i;
			}
			return $keys;
		}
		if($value instanceof JsObject){
			return $value->keys();
		}
		if(\is_string($value)){
			$keys = [];
			for($i = 0; $i < \strlen($value); $i++){
				$keys[] = (string) $i;
			}
			return $keys;
		}
		if(\is_array($value)){
			return \array_map(static fn(mixed $key) : string => (string) $key, \array_keys($value));
		}
		return [];
	}
}
