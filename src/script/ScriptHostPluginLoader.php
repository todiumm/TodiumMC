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

namespace pocketmine\script;

use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;

/**
 * Loader for the internal Script API host plugin.
 *
 * The host plugin is constructed by the server rather than loaded from disk, so this loader only exists to satisfy the
 * PluginLoader contract that {@link \pocketmine\plugin\PluginBase} requires. It never loads or describes anything.
 */
final class ScriptHostPluginLoader implements PluginLoader{

        public function canLoadPlugin(string $path) : bool{
                return false;
        }

        public function loadPlugin(string $file) : void{
                //NOOP - the host plugin is instantiated directly
        }

        public function getPluginDescription(string $file) : ?PluginDescription{
                return null;
        }

        public function getAccessProtocol() : string{
                return "";
        }
}
