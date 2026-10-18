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
use pocketmine\utils\Utils;
use function abs;
use function array_is_list;
use function array_map;
use function array_reverse;
use function array_slice;
use function array_splice;
use function array_unshift;
use function array_values;
use function base_convert;
use function ceil;
use function count;
use function decbin;
use function explode;
use function floor;
use function implode;
use function intval;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_nan;
use function is_string;
use function json_decode;
use function json_encode;
use function ltrim;
use function max;
use function mb_ord;
use function min;
use function mt_getrandmax;
use function mt_rand;
use function number_format;
use function pow;
use function preg_match;
use function preg_replace;
use function preg_split;
use function range;
use function rtrim;
use function sprintf;
use function str_contains;
use function str_ends_with;
use function str_pad;
use function str_repeat;
use function str_replace;
use function str_starts_with;
use function strcasecmp;
use function strlen;
use function strpos;
use function strrpos;
use function strtolower;
use function strtoupper;
use function substr;
use function trim;
use function usort;
use const JSON_PRETTY_PRINT;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;
use const M_E;
use const M_LN10;
use const M_LN2;
use const M_PI;
use const M_SQRT2;
use const NAN;
use const PHP_FLOAT_EPSILON;
use const STR_PAD_LEFT;
use const STR_PAD_RIGHT;

/**
 * The JavaScript built-in methods and globals behaviour pack scripts rely on.
 *
 * Each method captures its receiver, so the interpreter can return it from a property access and call it without
 * threading a `this` binding through every built-in.
 */
final class Builtins{

	private static ?JsObject $globals = null;

	/**
	 * Where `console` output goes. Set by the runtime so pack logging reaches the server console.
	 *
	 * @var (Closure(string, string) : void)|null
	 */
	private static ?Closure $logger = null;

	/**
	 * @param Closure(string, string) : void $logger level, message
	 */
	public static function setLogger(Closure $logger) : void{
		self::$logger = $logger;
	}

	/**
	 * Emits a line of script output, or discards it when no runtime is listening.
	 */
	public static function log(string $level, string $message) : void{
		if(self::$logger !== null){
			(self::$logger)($level, $message);
		}
	}

	/**
	 * The global object: Math, JSON, Object, Array, console, and the common free functions.
	 */
	public static function createGlobals() : JsObject{
		if(self::$globals !== null){
			return self::$globals;
		}

		$globals = new JsObject();
		$globals->set("undefined", JsUndefined::instance());
		$globals->set("NaN", NAN);
		$globals->set("Infinity", \INF);
		$globals->set("globalThis", $globals);

		$globals->set("Math", self::createMath());
		$globals->set("JSON", self::createJson());
		$globals->set("Object", self::createObjectConstructor());
		$globals->set("Array", self::createArrayConstructor());
		$globals->set("String", self::fn(static fn(array $a) : string => Interpreter::toString($a[0] ?? JsUndefined::instance()), "String"));
		$globals->set("Number", self::fn(static fn(array $a) : int|float => Interpreter::toNumber($a[0] ?? 0), "Number"));
		$globals->set("Boolean", self::fn(static fn(array $a) : bool => Interpreter::truthy($a[0] ?? false), "Boolean"));

		$globals->set("parseInt", self::fn(static function(array $a) : int|float{
			$value = Interpreter::toString($a[0] ?? "");
			$radix = isset($a[1]) ? (int) Interpreter::toNumber($a[1]) : 10;
			if($radix === 10 && preg_match('/^\s*[-+]?0[xX]/', $value) === 1){
				$radix = 16;
			}
			$radix = ($radix < 2 || $radix > 36) ? 10 : $radix;
			if(preg_match('/^\s*[-+]?[0-9a-zA-Z]+/', $value, $matches) !== 1){
				return NAN;
			}
			return intval($matches[0], $radix);
		}, "parseInt"));

		$globals->set("parseFloat", self::fn(static function(array $a) : float{
			$value = Interpreter::toString($a[0] ?? "");
			if(preg_match('/^\s*[-+]?(\d+\.?\d*|\.\d+)([eE][-+]?\d+)?/', $value, $matches) !== 1){
				return NAN;
			}
			return (float) $matches[0];
		}, "parseFloat"));

		$globals->set("isNaN", self::fn(static fn(array $a) : bool => is_nan((float) Interpreter::toNumber($a[0] ?? JsUndefined::instance())), "isNaN"));
		$globals->set("isFinite", self::fn(static fn(array $a) : bool => is_finite((float) Interpreter::toNumber($a[0] ?? JsUndefined::instance())), "isFinite"));
		$globals->set("console", self::createConsole());
		$globals->set("Map", self::createMapConstructor());
		$globals->set("Set", self::createSetConstructor());
		$globals->set("Error", self::createErrorConstructor());
		$globals->set("Date", self::createDateConstructor());

		//scheduling is a no-op: behaviour pack scripts run inside the server tick
		$globals->set("setTimeout", self::fn(static fn(array $a) : int => 0, "setTimeout"));
		$globals->set("clearTimeout", self::fn(static fn(array $a) : null => null, "clearTimeout"));
		$globals->set("setInterval", self::fn(static fn(array $a) : int => 0, "setInterval"));
		$globals->set("clearInterval", self::fn(static fn(array $a) : null => null, "clearInterval"));

		self::$globals = $globals;
		return $globals;
	}

	/**
	 * `console`, wired to the runtime's logger so pack logging reaches the server console.
	 */
	private static function createConsole() : JsObject{
		$console = new JsObject();
		$levels = [
			"log" => "info",
			"info" => "info",
			"warn" => "warning",
			"error" => "error",
			"debug" => "debug"
		];
		foreach($levels as $method => $level){
			$console->set($method, self::fn(static function(array $a) use ($level) : null{
				$parts = [];
				foreach($a as $argument){
					$parts[] = Interpreter::toString($argument);
				}
				self::log($level, implode(" ", $parts));
				return null;
			}, $method));
		}
		$console->set("trace", $console->get("debug"));
		$console->set("assert", self::fn(static function(array $a) : null{
			if(!Interpreter::truthy($a[0] ?? false)){
				self::log("error", "Assertion failed: " . Interpreter::toString($a[1] ?? ""));
			}
			return null;
		}, "assert"));
		return $console;
	}

	/**
	 * `Map`, backed by insertion-ordered arrays so iteration follows insertion order as in JavaScript.
	 */
	private static function createMapConstructor() : JsFunction{
		$constructor = new JsFunction(name: "Map", native: static function(array $a) : JsObject{
			/** @var list<array{0: mixed, 1: mixed}> $entries */
			$entries = [];
			foreach(Interpreter::arrayElements($a[0] ?? JsUndefined::instance()) as $pair){
				$parts = Interpreter::arrayElements($pair);
				if(\count($parts) >= 2){
					self::mapSet($entries, $parts[0], $parts[1]);
				}
			}

			$map = new JsObject();
			$map->set("__type", "map");
			$map->set("size", 0);
			$map->set("set", self::fn(static function(array $b) use (&$entries, $map) : JsObject{
				self::mapSet($entries, $b[0] ?? JsUndefined::instance(), $b[1] ?? JsUndefined::instance());
				$map->set("size", \count($entries));
				return $map;
			}, "set"));
			$map->set("get", self::fn(static function(array $b) use (&$entries) : mixed{
				foreach($entries as [$key, $value]){
					if(Interpreter::toStrictValue($key) === Interpreter::toStrictValue($b[0] ?? JsUndefined::instance())){
						return $value;
					}
				}
				return JsUndefined::instance();
			}, "get"));
			$map->set("has", self::fn(static function(array $b) use (&$entries) : bool{
				foreach($entries as [$key, $value]){
					if(Interpreter::toStrictValue($key) === Interpreter::toStrictValue($b[0] ?? JsUndefined::instance())){
						return true;
					}
				}
				return false;
			}, "has"));
			$map->set("delete", self::fn(static function(array $b) use (&$entries, $map) : bool{
				$target = Interpreter::toStrictValue($b[0] ?? JsUndefined::instance());
				foreach($entries as $index => [$key, $value]){
					if(Interpreter::toStrictValue($key) === $target){
						unset($entries[$index]);
						$entries = \array_values($entries);
						$map->set("size", \count($entries));
						return true;
					}
				}
				return false;
			}, "delete"));
			$map->set("clear", self::fn(static function(array $b) use (&$entries, $map) : null{
				$entries = [];
				$map->set("size", 0);
				return null;
			}, "clear"));
			$map->set("keys", self::fn(static fn(array $b) : JsArray => new JsArray(\array_map(static fn(array $e) : mixed => $e[0], $entries)), "keys"));
			$map->set("values", self::fn(static fn(array $b) : JsArray => new JsArray(\array_map(static fn(array $e) : mixed => $e[1], $entries)), "values"));
			$map->set("entries", self::fn(static fn(array $b) : JsArray => new JsArray(\array_map(static fn(array $e) : JsArray => new JsArray($e), $entries)), "entries"));
			$map->set("forEach", self::fn(static function(array $b) use (&$entries) : null{
				$callback = $b[0] ?? null;
				if($callback instanceof JsFunction){
					foreach($entries as [$key, $value]){
						(new Interpreter())->invokeFunction($callback, [$value, $key], null);
					}
				}
				return null;
			}, "forEach"));
			$map->set("__iterator", self::fn(static fn(array $b) : JsArray => new JsArray(\array_map(static fn(array $e) : JsArray => new JsArray($e), $entries)), "__iterator"));
			return $map;
		});
		return $constructor;
	}

	/**
	 * `Set`, stored as a list of unique values in insertion order.
	 */
	private static function createSetConstructor() : JsFunction{
		return new JsFunction(name: "Set", native: static function(array $a) : JsObject{
			/** @var list<mixed> $values */
			$values = [];
			foreach(Interpreter::arrayElements($a[0] ?? JsUndefined::instance()) as $item){
				self::setAdd($values, $item);
			}

			$set = new JsObject();
			$set->set("__type", "set");
			$set->set("size", \count($values));
			$set->set("add", self::fn(static function(array $b) use (&$values, $set) : JsObject{
				self::setAdd($values, $b[0] ?? JsUndefined::instance());
				$set->set("size", \count($values));
				return $set;
			}, "add"));
			$set->set("has", self::fn(static function(array $b) use (&$values) : bool{
				$target = Interpreter::toStrictValue($b[0] ?? JsUndefined::instance());
				foreach($values as $value){
					if(Interpreter::toStrictValue($value) === $target){
						return true;
					}
				}
				return false;
			}, "has"));
			$set->set("delete", self::fn(static function(array $b) use (&$values, $set) : bool{
				$target = Interpreter::toStrictValue($b[0] ?? JsUndefined::instance());
				foreach($values as $index => $value){
					if(Interpreter::toStrictValue($value) === $target){
						unset($values[$index]);
						$values = \array_values($values);
						$set->set("size", \count($values));
						return true;
					}
				}
				return false;
			}, "delete"));
			$set->set("clear", self::fn(static function(array $b) use (&$values, $set) : null{
				$values = [];
				$set->set("size", 0);
				return null;
			}, "clear"));
			$set->set("values", self::fn(static fn(array $b) : JsArray => new JsArray($values), "values"));
			$set->set("keys", self::fn(static fn(array $b) : JsArray => new JsArray($values), "keys"));
			$set->set("entries", self::fn(static fn(array $b) : JsArray => new JsArray(\array_map(static fn(mixed $v) : JsArray => new JsArray([$v, $v]), $values)), "entries"));
			$set->set("forEach", self::fn(static function(array $b) use (&$values) : null{
				$callback = $b[0] ?? null;
				if($callback instanceof JsFunction){
					foreach($values as $value){
						(new Interpreter())->invokeFunction($callback, [$value, $value], null);
					}
				}
				return null;
			}, "forEach"));
			$set->set("__iterator", self::fn(static fn(array $b) : JsArray => new JsArray($values), "__iterator"));
			return $set;
		});
	}

	/**
	 * Inserts or replaces a map entry, matching JavaScript `Map` semantics.
	 *
	 * @param list<array{0: mixed, 1: mixed}> $entries
	 */
	private static function mapSet(array &$entries, mixed $key, mixed $value) : void{
		$target = Interpreter::toStrictValue($key);
		foreach($entries as $index => [$existing, $unused]){
			if(Interpreter::toStrictValue($existing) === $target){
				$entries[$index] = [$key, $value];
				return;
			}
		}
		$entries[] = [$key, $value];
	}

	/**
	 * Appends a set value unless an equal one is already present.
	 *
	 * @param list<mixed> $values
	 */
	private static function setAdd(array &$values, mixed $value) : void{
		$target = Interpreter::toStrictValue($value);
		foreach($values as $existing){
			if(Interpreter::toStrictValue($existing) === $target){
				return;
			}
		}
		$values[] = $value;
	}

	/**
	 * Wraps a PHP callable as a native JavaScript function.
	 *
	 * @param callable(list<mixed>) : mixed $callable
	 */
	public static function fn(callable $callable, string $name = "") : JsFunction{
		return new JsFunction(name: $name, native: Closure::fromCallable($callable));
	}

	// ---------------------------------------------------------------------------------------------------------
	// Math
	// ---------------------------------------------------------------------------------------------------------

	private static function createMath() : JsObject{
		$math = new JsObject();
		$math->set("PI", M_PI);
		$math->set("E", M_E);
		$math->set("LN2", M_LN2);
		$math->set("LN10", M_LN10);
		$math->set("SQRT2", M_SQRT2);
		$math->set("EPSILON", PHP_FLOAT_EPSILON);
		$math->set("MAX_SAFE_INTEGER", 9007199254740991);

		$math->set("abs", self::fn(static fn(array $a) : int|float => abs(Interpreter::toNumber($a[0] ?? 0)), "abs"));
		$math->set("floor", self::fn(static fn(array $a) : float => floor((float) Interpreter::toNumber($a[0] ?? 0)), "floor"));
		$math->set("ceil", self::fn(static fn(array $a) : float => ceil((float) Interpreter::toNumber($a[0] ?? 0)), "ceil"));
		$math->set("round", self::fn(static fn(array $a) : float => \round((float) Interpreter::toNumber($a[0] ?? 0)), "round"));
		$math->set("trunc", self::fn(static fn(array $a) : float => (float) (int) Interpreter::toNumber($a[0] ?? 0), "trunc"));
		$math->set("sign", self::fn(static fn(array $a) : int => Interpreter::toNumber($a[0] ?? 0) <=> 0, "sign"));
		$math->set("sqrt", self::fn(static fn(array $a) : float => \sqrt((float) Interpreter::toNumber($a[0] ?? 0)), "sqrt"));
		$math->set("cbrt", self::fn(static fn(array $a) : float => pow((float) Interpreter::toNumber($a[0] ?? 0), 1 / 3), "cbrt"));
		$math->set("pow", self::fn(static fn(array $a) : float => pow((float) Interpreter::toNumber($a[0] ?? 0), (float) Interpreter::toNumber($a[1] ?? 0)), "pow"));
		$math->set("exp", self::fn(static fn(array $a) : float => \exp((float) Interpreter::toNumber($a[0] ?? 0)), "exp"));
		$math->set("log", self::fn(static fn(array $a) : float => \log((float) Interpreter::toNumber($a[0] ?? 0)), "log"));
		$math->set("log2", self::fn(static fn(array $a) : float => \log((float) Interpreter::toNumber($a[0] ?? 0), 2), "log2"));
		$math->set("log10", self::fn(static fn(array $a) : float => \log10((float) Interpreter::toNumber($a[0] ?? 0)), "log10"));
		$math->set("sin", self::fn(static fn(array $a) : float => \sin((float) Interpreter::toNumber($a[0] ?? 0)), "sin"));
		$math->set("cos", self::fn(static fn(array $a) : float => \cos((float) Interpreter::toNumber($a[0] ?? 0)), "cos"));
		$math->set("tan", self::fn(static fn(array $a) : float => \tan((float) Interpreter::toNumber($a[0] ?? 0)), "tan"));
		$math->set("atan", self::fn(static fn(array $a) : float => \atan((float) Interpreter::toNumber($a[0] ?? 0)), "atan"));
		$math->set("atan2", self::fn(static fn(array $a) : float => \atan2((float) Interpreter::toNumber($a[0] ?? 0), (float) Interpreter::toNumber($a[1] ?? 0)), "atan2"));
		$math->set("asin", self::fn(static fn(array $a) : float => \asin((float) Interpreter::toNumber($a[0] ?? 0)), "asin"));
		$math->set("acos", self::fn(static fn(array $a) : float => \acos((float) Interpreter::toNumber($a[0] ?? 0)), "acos"));
		$math->set("hypot", self::fn(static fn(array $a) : float => \hypot((float) Interpreter::toNumber($a[0] ?? 0), (float) Interpreter::toNumber($a[1] ?? 0)), "hypot"));

		$math->set("max", self::fn(static function(array $a) : int|float{
			if($a === []){
				return -\INF;
			}
			return max(array_map(static fn(mixed $v) : int|float => Interpreter::toNumber($v), $a));
		}, "max"));

		$math->set("min", self::fn(static function(array $a) : int|float{
			if($a === []){
				return \INF;
			}
			return min(array_map(static fn(mixed $v) : int|float => Interpreter::toNumber($v), $a));
		}, "min"));

		$math->set("random", self::fn(static fn(array $a) : float => mt_rand() / mt_getrandmax(), "random"));
		$math->set("clz32", self::fn(static fn(array $a) : int => 32 - strlen(decbin((int) Interpreter::toNumber($a[0] ?? 0))), "clz32"));
		$math->set("imul", self::fn(static fn(array $a) : int => (int) Interpreter::toNumber($a[0] ?? 0) * (int) Interpreter::toNumber($a[1] ?? 0), "imul"));

		return $math;
	}

	// ---------------------------------------------------------------------------------------------------------
	// JSON
	// ---------------------------------------------------------------------------------------------------------

	private static function createJson() : JsObject{
		$json = new JsObject();
		$json->set("stringify", self::fn(static function(array $a) : mixed{
			$value = $a[0] ?? JsUndefined::instance();
			$pretty = isset($a[2]) && Interpreter::toNumber($a[2]) > 0;
			$flags = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | ($pretty ? JSON_PRETTY_PRINT : 0);
			$encoded = json_encode(self::toNative($value), $flags);
			return $encoded === false ? JsUndefined::instance() : $encoded;
		}, "stringify"));

		$json->set("parse", self::fn(static function(array $a) : mixed{
			$decoded = json_decode(Interpreter::toString($a[0] ?? ""), true);
			return self::fromNative($decoded);
		}, "parse"));

		return $json;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Object / Array / Error / Date constructors
	// ---------------------------------------------------------------------------------------------------------

	private static function createObjectConstructor() : JsFunction{
		$constructor = new JsFunction(name: "Object");
		$constructor->set("keys", self::fn(static fn(array $a) : JsArray => new JsArray(Interpreter::objectKeys($a[0] ?? JsUndefined::instance())), "keys"));
		$constructor->set("values", self::fn(static function(array $a) : JsArray{
			$target = $a[0] ?? JsUndefined::instance();
			$values = [];
			foreach(Interpreter::objectKeys($target) as $key){
				$values[] = Interpreter::getProperty($target, $key);
			}
			return new JsArray($values);
		}, "values"));
		$constructor->set("entries", self::fn(static function(array $a) : JsArray{
			$target = $a[0] ?? JsUndefined::instance();
			$entries = [];
			foreach(Interpreter::objectKeys($target) as $key){
				$entries[] = new JsArray([$key, Interpreter::getProperty($target, $key)]);
			}
			return new JsArray($entries);
		}, "entries"));
		$constructor->set("assign", self::fn(static function(array $a) : mixed{
			$target = $a[0] ?? new JsObject();
			for($i = 1; $i < count($a); $i++){
				foreach(Interpreter::objectKeys($a[$i]) as $key){
					Interpreter::setProperty($target, $key, Interpreter::getProperty($a[$i], $key));
				}
			}
			return $target;
		}, "assign"));
		$constructor->set("fromEntries", self::fn(static function(array $a) : JsObject{
			$object = new JsObject();
			foreach(Interpreter::arrayElements($a[0] ?? JsUndefined::instance()) as $entry){
				$pair = Interpreter::arrayElements($entry);
				if(count($pair) >= 2){
					$object->set(Interpreter::toString($pair[0]), $pair[1]);
				}
			}
			return $object;
		}, "fromEntries"));
		$constructor->set("freeze", self::fn(static fn(array $a) : mixed => $a[0] ?? JsUndefined::instance(), "freeze"));
		$constructor->set("is", self::fn(static fn(array $a) : bool => Interpreter::toStrictValue($a[0] ?? null) === Interpreter::toStrictValue($a[1] ?? null), "is"));
		$constructor->set("hasOwn", self::fn(static fn(array $a) : bool => Interpreter::hasProperty($a[0] ?? JsUndefined::instance(), Interpreter::toString($a[1] ?? "")), "hasOwn"));
		return $constructor;
	}

	private static function createArrayConstructor() : JsFunction{
		$constructor = new JsFunction(name: "Array");
		$constructor->set("isArray", self::fn(static fn(array $a) : bool => ($a[0] ?? null) instanceof JsArray, "isArray"));
		$constructor->set("from", self::fn(static fn(array $a) : JsArray => new JsArray(Interpreter::arrayElements($a[0] ?? JsUndefined::instance())), "from"));
		$constructor->set("of", self::fn(static fn(array $a) : JsArray => new JsArray($a), "of"));
		return $constructor;
	}

	private static function createErrorConstructor() : JsFunction{
		return new JsFunction(name: "Error", native: static function(array $a) : JsObject{
			$error = new JsObject();
			$error->set("name", "Error");
			$error->set("message", Interpreter::toString($a[0] ?? ""));
			$error->set("stack", "Error: " . Interpreter::toString($a[0] ?? ""));
			$error->set("__nativeString", self::fn(static function(array $b) use ($error) : string{
				return "Error: " . Interpreter::toString($error->get("message"));
			}));
			return $error;
		});
	}

	private static function createDateConstructor() : JsFunction{
		return new JsFunction(name: "Date", native: static function(array $a) : JsObject{
			$millis = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : (int) (\microtime(true) * 1000);
			$date = new JsObject();
			$date->set("__millis", $millis);
			$date->set("getTime", self::fn(static fn(array $b) : int => $millis));
			$date->set("valueOf", self::fn(static fn(array $b) : int => $millis));
			$date->set("toISOString", self::fn(static fn(array $b) : string => \gmdate("Y-m-d\\TH:i:s.000\\Z", (int) ($millis / 1000))));
			$date->set("__nativeString", self::fn(static fn(array $b) : string => \gmdate("D M d Y H:i:s \\G\\M\\T+0000", (int) ($millis / 1000))));
			return $date;
		});
	}

	// ---------------------------------------------------------------------------------------------------------
	// String and Array prototype methods
	// ---------------------------------------------------------------------------------------------------------

	public static function stringMethod(string $value, string $name) : mixed{
		return match($name){
			"length" => strlen($value),
			"toUpperCase" => self::fn(static fn(array $a) : string => strtoupper($value), $name),
			"toLowerCase" => self::fn(static fn(array $a) : string => strtolower($value), $name),
			"trim" => self::fn(static fn(array $a) : string => trim($value), $name),
			"trimStart" => self::fn(static fn(array $a) : string => ltrim($value), $name),
			"trimEnd" => self::fn(static fn(array $a) : string => rtrim($value), $name),
			"charAt" => self::fn(static fn(array $a) : string => $value[(int) Interpreter::toNumber($a[0] ?? 0)] ?? "", $name),
			"charCodeAt" => self::fn(static function(array $a) use ($value) : int|float{
				$index = (int) Interpreter::toNumber($a[0] ?? 0);
				return isset($value[$index]) ? \ord($value[$index]) : NAN;
			}, $name),
			"codePointAt" => self::fn(static function(array $a) use ($value) : int|float{
				$index = (int) Interpreter::toNumber($a[0] ?? 0);
				return isset($value[$index]) ? mb_ord($value[$index], "UTF-8") : NAN;
			}, $name),
			"includes" => self::fn(static fn(array $a) : bool => str_contains($value, Interpreter::toString($a[0] ?? "")), $name),
			"startsWith" => self::fn(static fn(array $a) : bool => str_starts_with($value, Interpreter::toString($a[0] ?? "")), $name),
			"endsWith" => self::fn(static fn(array $a) : bool => str_ends_with($value, Interpreter::toString($a[0] ?? "")), $name),
			"indexOf" => self::fn(static function(array $a) use ($value) : int{
				$position = strpos($value, Interpreter::toString($a[0] ?? ""));
				return $position === false ? -1 : $position;
			}, $name),
			"lastIndexOf" => self::fn(static function(array $a) use ($value) : int{
				$position = strrpos($value, Interpreter::toString($a[0] ?? ""));
				return $position === false ? -1 : $position;
			}, $name),
			"slice" => self::fn(static function(array $a) use ($value) : string{
				$length = strlen($value);
				$start = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				$end = isset($a[1]) ? (int) Interpreter::toNumber($a[1]) : $length;
				if($start < 0){ $start = max($length + $start, 0); }
				if($end < 0){ $end = $length + $end; }
				if($end <= $start){ return ""; }
				return substr($value, $start, $end - $start);
			}, $name),
			"substring" => self::fn(static function(array $a) use ($value) : string{
				$length = strlen($value);
				$start = max((int) Interpreter::toNumber($a[0] ?? 0), 0);
				$end = isset($a[1]) ? max((int) Interpreter::toNumber($a[1]), 0) : $length;
				if($start > $end){ [$start, $end] = [$end, $start]; }
				return substr($value, $start, $end - $start);
			}, $name),
			"substr" => self::fn(static function(array $a) use ($value) : string{
				$length = strlen($value);
				$start = (int) Interpreter::toNumber($a[0] ?? 0);
				if($start < 0){ $start = max($length + $start, 0); }
				$count = isset($a[1]) ? (int) Interpreter::toNumber($a[1]) : $length;
				return substr($value, $start, $count);
			}, $name),
			"split" => self::fn(static function(array $a) use ($value) : JsArray{
				$separator = $a[0] ?? JsUndefined::instance();
				$limit = isset($a[1]) ? (int) Interpreter::toNumber($a[1]) : -1;
				if($separator instanceof JsUndefined){
					return new JsArray([$value]);
				}
				$text = Interpreter::toString($separator);
				$parts = $text === ""
					? preg_split("//u", $value, -1, \PREG_SPLIT_NO_EMPTY)
					: explode($text, $value, $limit >= 0 ? $limit + 1 : PHP_INT_MAX);
				$parts = $parts === false ? [] : $parts;
				if($limit >= 0){
					$parts = array_slice($parts, 0, $limit);
				}
				return new JsArray($parts);
			}, $name),
			"replace" => self::fn(static function(array $a) use ($value) : string{
				$search = $a[0] ?? JsUndefined::instance();
				$replacement = $a[1] ?? JsUndefined::instance();
				if($replacement instanceof JsFunction){
					return self::replaceWithCallback($value, $search, $replacement);
				}
				return self::replaceLiteral($value, $search, Interpreter::toString($replacement));
			}, $name),
			"replaceAll" => self::fn(static fn(array $a) : string => str_replace(Interpreter::toString($a[0] ?? ""), Interpreter::toString($a[1] ?? ""), $value), $name),
			"repeat" => self::fn(static fn(array $a) : string => str_repeat($value, max((int) Interpreter::toNumber($a[0] ?? 0), 0)), $name),
			"padStart" => self::fn(static fn(array $a) : string => str_pad($value, (int) Interpreter::toNumber($a[0] ?? 0), Interpreter::toString($a[1] ?? " "), STR_PAD_LEFT), $name),
			"padEnd" => self::fn(static fn(array $a) : string => str_pad($value, (int) Interpreter::toNumber($a[0] ?? 0), Interpreter::toString($a[1] ?? " "), STR_PAD_RIGHT), $name),
			"toFixed" => self::fn(static function(array $a) use ($value) : string{
				$digits = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				return number_format((float) $value, $digits, ".", "");
			}, $name),
			"concat" => self::fn(static function(array $a) use ($value) : string{
				$result = $value;
				foreach($a as $item){
					$result .= Interpreter::toString($item);
				}
				return $result;
			}, $name),
			"match" => self::fn(static function(array $a) use ($value) : mixed{
				$pattern = Interpreter::toString($a[0] ?? "");
				$raw = [];
				if(preg_match('/' . str_replace("/", "\\/", $pattern) . '/', $value, $raw) !== 1){
					return null;
				}
				$matches = [];
				foreach($raw as $match){
					$matches[] = $match;
				}
				return new JsArray($matches);
			}, $name),
			"toString", "valueOf" => self::fn(static fn(array $a) : string => $value, $name),
			"localeCompare" => self::fn(static fn(array $a) : int => strcasecmp($value, Interpreter::toString($a[0] ?? "")) <=> 0, $name),
			"at" => self::fn(static function(array $a) use ($value) : string{
				$index = (int) Interpreter::toNumber($a[0] ?? 0);
				if($index < 0){ $index += strlen($value); }
				return $value[$index] ?? "";
			}, $name),
			"normalize" => self::fn(static fn(array $a) : string => $value, $name),
			default => JsUndefined::instance()
		};
	}

	/**
	 * Methods available on number primitives, so `(1.5).toFixed(2)` and friends work.
	 */
	public static function numberMethod(int|float $value, string $name) : mixed{
		return match($name){
			"toFixed" => self::fn(static function(array $a) use ($value) : string{
				$digits = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				return number_format((float) $value, $digits, ".", "");
			}, $name),
			"toPrecision" => self::fn(static function(array $a) use ($value) : string{
				$digits = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				return $digits <= 0 ? Interpreter::toString($value) : sprintf("%." . $digits . "g", (float) $value);
			}, $name),
			"toExponential" => self::fn(static function(array $a) use ($value) : string{
				$digits = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				return sprintf("%." . max($digits, 0) . "e", (float) $value);
			}, $name),
			"toString" => self::fn(static function(array $a) use ($value) : string{
				$radix = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 10;
				if($radix !== 10 && \is_int($value) && $radix >= 2 && $radix <= 36){
					return base_convert((string) $value, 10, $radix);
				}
				return Interpreter::toString($value);
			}, $name),
			"valueOf" => self::fn(static fn(array $a) : int|float => $value, $name),
			default => JsUndefined::instance()
		};
	}

	public static function arrayMethod(JsArray $array, string $name) : mixed{
		return match($name){
			"push" => self::fn(static function(array $a) use ($array) : int{
				foreach($a as $item){
					$array->elements[] = $item;
				}
				return $array->length();
			}, $name),
			"pop" => self::fn(static function(array $a) use ($array) : mixed{
				return $array->elements === [] ? JsUndefined::instance() : array_pop($array->elements);
			}, $name),
			"shift" => self::fn(static function(array $a) use ($array) : mixed{
				return $array->elements === [] ? JsUndefined::instance() : \array_shift($array->elements);
			}, $name),
			"unshift" => self::fn(static function(array $a) use ($array) : int{
				array_unshift($array->elements, ...$a);
				return $array->length();
			}, $name),
			"indexOf" => self::fn(static function(array $a) use ($array) : int{
				$needle = Interpreter::toStrictValue($a[0] ?? JsUndefined::instance());
				foreach($array->elements as $index => $element){
					if(Interpreter::toStrictValue($element) === $needle){ return $index; }
				}
				return -1;
			}, $name),
			"lastIndexOf" => self::fn(static function(array $a) use ($array) : int{
				$needle = Interpreter::toStrictValue($a[0] ?? JsUndefined::instance());
				for($i = count($array->elements) - 1; $i >= 0; $i--){
					if(Interpreter::toStrictValue($array->elements[$i]) === $needle){ return $i; }
				}
				return -1;
			}, $name),
			"includes" => self::fn(static function(array $a) use ($array) : bool{
				$needle = Interpreter::toStrictValue($a[0] ?? JsUndefined::instance());
				foreach($array->elements as $element){
					if(Interpreter::toStrictValue($element) === $needle){ return true; }
				}
				return false;
			}, $name),
			"join" => self::fn(static function(array $a) use ($array) : string{
				$separator = isset($a[0]) && !$a[0] instanceof JsUndefined ? Interpreter::toString($a[0]) : ",";
				return implode($separator, array_map(static fn(mixed $v) : string => Interpreter::toString($v), $array->elements));
			}, $name),
			"slice" => self::fn(static function(array $a) use ($array) : JsArray{
				$length = $array->length();
				$start = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				$end = isset($a[1]) ? (int) Interpreter::toNumber($a[1]) : $length;
				if($start < 0){ $start = max($length + $start, 0); }
				if($end < 0){ $end = $length + $end; }
				return new JsArray(array_values(array_slice($array->elements, $start, max($end - $start, 0))));
			}, $name),
			"concat" => self::fn(static function(array $a) use ($array) : JsArray{
				$result = $array->elements;
				foreach($a as $item){
					if($item instanceof JsArray){
						foreach($item->elements as $element){ $result[] = $element; }
					}else{
						$result[] = $item;
					}
				}
				return new JsArray($result);
			}, $name),
			"reverse" => self::fn(static function(array $a) use ($array) : JsArray{
				$array->elements = array_reverse($array->elements);
				return $array;
			}, $name),
			"sort" => self::fn(static function(array $a) use ($array) : JsArray{
				$comparator = $a[0] ?? null;
				if($comparator instanceof JsFunction){
					$interpreter = new Interpreter();
					usort($array->elements, static fn(mixed $x, mixed $y) : int => (int) Interpreter::toNumber($interpreter->invokeFunction($comparator, [$x, $y], null)));
				}else{
					usort($array->elements, static fn(mixed $x, mixed $y) : int => Interpreter::compare(Interpreter::toString($x), Interpreter::toString($y)));
				}
				return $array;
			}, $name),
			"splice" => self::fn(static function(array $a) use ($array) : JsArray{
				$start = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 0;
				if($start < 0){ $start = max($array->length() + $start, 0); }
				$deleteCount = isset($a[1]) ? (int) Interpreter::toNumber($a[1]) : $array->length() - $start;
				$removed = array_splice($array->elements, $start, $deleteCount, array_slice($a, 2));
				return new JsArray(array_values($removed));
			}, $name),
			"fill" => self::fn(static function(array $a) use ($array) : JsArray{
				$value = $a[0] ?? JsUndefined::instance();
				$filled = [];
				for($i = 0; $i < $array->length(); $i++){
					$filled[] = $value;
				}
				$array->elements = $filled;
				return $array;
			}, $name),
			"flat" => self::fn(static function(array $a) use ($array) : JsArray{
				$depth = isset($a[0]) ? (int) Interpreter::toNumber($a[0]) : 1;
				return new JsArray(self::flatten($array->elements, $depth));
			}, $name),
			"at" => self::fn(static function(array $a) use ($array) : mixed{
				$index = (int) Interpreter::toNumber($a[0] ?? 0);
				if($index < 0){ $index += $array->length(); }
				return $array->elements[$index] ?? JsUndefined::instance();
			}, $name),
			"toString" => self::fn(static function(array $a) use ($array) : string{
				return implode(",", array_map(static fn(mixed $v) : string => Interpreter::toString($v), $array->elements));
			}, $name),
			"keys" => self::fn(static function(array $a) use ($array) : JsArray{
				return new JsArray($array->length() === 0 ? [] : array_map(static fn(int $i) : int => $i, range(0, $array->length() - 1)));
			}, $name),
			"entries" => self::fn(static function(array $a) use ($array) : JsArray{
				$out = [];
				foreach($array->elements as $index => $element){
					$out[] = new JsArray([$index, $element]);
				}
				return new JsArray($out);
			}, $name),
			"map", "filter", "forEach", "find", "findIndex", "some", "every", "reduce", "reduceRight", "flatMap" =>
				self::fn(static function(array $a) use ($array, $name) : mixed{
					$callback = $a[0] ?? null;
					if(!$callback instanceof JsFunction){
						throw new JsThrow("TypeError: " . $name . " expects a function");
					}
					$interpreter = new Interpreter();
					$result = [];
					$accumulator = $a[1] ?? JsUndefined::instance();
					$hasAccumulator = isset($a[1]);

					$elements = $name === "reduceRight" ? array_reverse($array->elements, true) : $array->elements;
					foreach($elements as $index => $element){
						switch($name){
							case "forEach":
								$interpreter->invokeFunction($callback, [$element, $index, $array], null);
								break;
							case "map":
								$result[] = $interpreter->invokeFunction($callback, [$element, $index, $array], null);
								break;
							case "flatMap":
								$mapped = $interpreter->invokeFunction($callback, [$element, $index, $array], null);
								if($mapped instanceof JsArray){
									foreach($mapped->elements as $inner){ $result[] = $inner; }
								}else{
									$result[] = $mapped;
								}
								break;
							case "filter":
								if(Interpreter::truthy($interpreter->invokeFunction($callback, [$element, $index, $array], null))){
									$result[] = $element;
								}
								break;
							case "find":
								if(Interpreter::truthy($interpreter->invokeFunction($callback, [$element, $index, $array], null))){
									return $element;
								}
								break;
							case "findIndex":
								if(Interpreter::truthy($interpreter->invokeFunction($callback, [$element, $index, $array], null))){
									return $index;
								}
								break;
							case "some":
								if(Interpreter::truthy($interpreter->invokeFunction($callback, [$element, $index, $array], null))){
									return true;
								}
								break;
							case "every":
								if(!Interpreter::truthy($interpreter->invokeFunction($callback, [$element, $index, $array], null))){
									return false;
								}
								break;
							case "reduce":
							case "reduceRight":
								if(!$hasAccumulator){
									$accumulator = $element;
									$hasAccumulator = true;
									continue 2;
								}
								$accumulator = $interpreter->invokeFunction($callback, [$accumulator, $element, $index, $array], null);
								break;
						}
					}

					return match($name){
						"forEach" => JsUndefined::instance(),
						"find" => JsUndefined::instance(),
						"findIndex" => -1,
						"some" => false,
						"every" => true,
						"reduce", "reduceRight" => $accumulator,
						default => new JsArray($result)
					};
				}, $name),
			default => JsUndefined::instance()
		};
	}

	/**
	 * @param list<mixed> $elements
	 *
	 * @return list<mixed>
	 */
	private static function flatten(array $elements, int $depth) : array{
		if($depth < 1){
			return $elements;
		}
		$out = [];
		foreach($elements as $element){
			if($element instanceof JsArray){
				foreach(self::flatten($element->elements, $depth - 1) as $inner){
					$out[] = $inner;
				}
			}else{
				$out[] = $element;
			}
		}
		return $out;
	}

	// ---------------------------------------------------------------------------------------------------------
	// Conversions between JavaScript and PHP representations
	// ---------------------------------------------------------------------------------------------------------

	/**
	 * Converts a JavaScript value to plain PHP data, for JSON encoding and host calls.
	 */
	public static function toNative(mixed $value) : mixed{
		if($value instanceof JsUndefined){
			return null;
		}
		if($value instanceof JsFunction){
			return null;
		}
		if($value instanceof JsArray){
			$out = [];
			foreach($value->elements as $element){
				$out[] = self::toNative($element);
			}
			return $out;
		}
		if($value instanceof JsObject){
			$out = [];
			foreach(Utils::stringifyKeys($value->properties) as $key => $item){
				$out[$key] = self::toNative($item);
			}
			return $out;
		}
		return $value;
	}

	/**
	 * Converts plain PHP data returned by a host function into JavaScript values.
	 */
	public static function fromNative(mixed $value) : mixed{
		if($value === null){
			return null;
		}
		if(is_array($value)){
			if(array_is_list($value)){
				$out = [];
				foreach($value as $item){
					$out[] = self::fromNative($item);
				}
				return new JsArray($out);
			}
			$object = new JsObject();
			foreach(Utils::promoteKeys($value) as $key => $item){
				$object->set((string) $key, self::fromNative($item));
			}
			return $object;
		}
		if(is_bool($value) || is_int($value) || is_float($value) || is_string($value)){
			return $value;
		}
		return JsUndefined::instance();
	}

	private static function replaceLiteral(string $value, mixed $search, string $replacement) : string{
		if($search instanceof JsObject && Interpreter::truthy($search->get("__regex") ?? false)){
			$pattern = Interpreter::toString($search->get("source") ?? "");
			$flags = Interpreter::toString($search->get("flags") ?? "");
			$delimiter = "/" . str_replace("/", "\\/", $pattern) . "/" . str_replace("g", "", $flags);
			$escaped = str_replace(["\\", "$"], ["\\\\", "\\$"], $replacement);
			$result = preg_replace($delimiter, $escaped, $value);
			return $result ?? $value;
		}
		return str_replace(Interpreter::toString($search), $replacement, $value);
	}

	private static function replaceWithCallback(string $value, mixed $search, JsFunction $callback) : string{
		$interpreter = new Interpreter();
		$needle = Interpreter::toString($search);
		if($needle === ""){
			return $value;
		}
		$offset = 0;
		$result = "";
		while(($position = strpos($value, $needle, $offset)) !== false){
			$result .= substr($value, $offset, $position - $offset);
			$result .= Interpreter::toString($interpreter->invokeFunction($callback, [$needle, $position, $value], null));
			$offset = $position + strlen($needle);
		}
		return $result . substr($value, $offset);
	}
}
