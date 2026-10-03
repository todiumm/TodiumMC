<?php

declare(strict_types=1);

namespace pocketmine\custom;

use pocketmine\item\Item;

/**
 * Internal bridge used when a native item class needs constructor state that
 * Customies' class-based registration API cannot pass to the constructor.
 *
 * The entry is consumed synchronously by CustomItemFactory::register()
 * and is always cleared by CustomItemFactory afterwards.
 */
final class NativeCustomItemRegistry{

    /** @var array<class-string<Item>, Item> */
    private static array $pending = [];

    private function __construct(){
    }

    public static function set(Item $prototype) : void{
        self::$pending[$prototype::class] = $prototype;
    }

    public static function consume(string $className) : Item{
        $prototype = self::$pending[$className] ?? null;
        if(!$prototype instanceof Item){
            throw new \RuntimeException("No pending native custom item prototype for $className");
        }
        unset(self::$pending[$className]);
        return $prototype;
    }

    public static function clear(string $className) : void{
        unset(self::$pending[$className]);
    }
}
