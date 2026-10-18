<?php

/*
 * Todium - native behaviour pack support for Altay.
 * Licensed under the GNU Lesser General Public License, version 3 or later.
 */

declare(strict_types=1);

namespace pocketmine\script;

use PHPUnit\Framework\TestCase;
use pocketmine\script\engine\ScriptEngine;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use FilesystemIterator;
use function array_filter;
use function file_get_contents;
use function is_scalar;
use function str_contains;
use function substr;

/**
 * Runs the shipped example pack through the native engine, covering the same ground the old Node.js sidecar test did.
 *
 * The engine is pure PHP, so these tests need no external JavaScript runtime.
 */
final class ScriptEngineExamplePackTest extends TestCase{

        private const PACK_UUID = "6f1a5d2c-1f3e-4a5b-9c7d-2b8e4f6a1d30";
        private const ENTRY = self::PACK_UUID . "/scripts/main.js";

        private ScriptEngine $engine;
        /** @var list<string> */
        private array $hostCalls = [];
        /** @var list<string> */
        private array $logLines = [];

        protected function setUp() : void{
                $packDirectory = \dirname(__DIR__, 3) . "/examples/behavior_packs/TodiumExample";
                $sources = [];
                $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($packDirectory, FilesystemIterator::SKIP_DOTS));
                foreach($iterator as $file){
                        if(!$file instanceof \SplFileInfo || !$file->isFile()){
                                continue;
                        }
                        $relative = substr($file->getPathname(), \strlen($packDirectory) + 1);
                        $sources[self::PACK_UUID . "/" . $relative] = (string) file_get_contents($file->getPathname());
                }

                $this->hostCalls = [];
                $this->logLines = [];

                $this->engine = new ScriptEngine(
                        function(string $name, array $arguments) : mixed{
                                $this->hostCalls[] = $name;
                                if($name === "system.log"){
                                        $message = $arguments[1] ?? "";
                                        $this->logLines[] = is_scalar($message) ? (string) $message : "";
                                }
                                return self::stubReply($name);
                        },
                        "TodiumTest",
                        "1.21.0"
                );

                $this->engine->start($sources, [self::ENTRY]);
        }

        private static function stubReply(string $name) : mixed{
                if(str_contains($name, "getLocation") || str_contains($name, "getSpawnPoint")){
                        return ["x" => 0.5, "y" => 64.0, "z" => 0.5, "yaw" => 0.0, "pitch" => 0.0];
                }
                if(str_contains($name, "getTimeOfDay") || str_contains($name, "getDay")){
                        return 6000;
                }
                if(str_contains($name, "getWorlds")){
                        return [["id" => "minecraft:overworld", "name" => "world"]];
                }
                if(str_contains($name, "getPlayers")){
                        return [];
                }
                if(str_contains($name, "getDynamicProperty")){
                        return null;
                }
                return null;
        }

        /**
         * @return array<string, mixed>
         */
        private static function chatPayload(string $message) : array{
                return [
                        "handle" => "event-1",
                        "sender" => [
                                "name" => "Steve",
                                "location" => ["x" => 0.5, "y" => 64.0, "z" => 0.5, "yaw" => 0.0, "pitch" => 0.0],
                                "dimension" => ["id" => "minecraft:overworld"]
                        ],
                        "message" => $message
                ];
        }

        public function testExamplePackScriptsAreLoaded() : void{
                self::assertSame("", $this->engine->getLastError(), "The example pack must load without errors");
                self::assertTrue($this->engine->isRunning());
        }

        public function testWorldLoadEventReachesScripts() : void{
                $this->engine->dispatchEvent("worldLoad", ["worldName" => "test_world"], false);

                self::assertContains("system.log", $this->hostCalls);
                self::assertStringContainsString('World "test_world" is ready', \implode("\n", $this->logLines));
        }

        public function testPlayerSpawnUsesDynamicProperties() : void{
                $this->engine->dispatchEvent("playerSpawn", ["playerName" => "Steve", "initialSpawn" => true], false);

                self::assertContains("world.getDynamicProperty", $this->hostCalls);
                self::assertContains("world.setDynamicProperty", $this->hostCalls);
                self::assertContains("player.sendMessage", $this->hostCalls);
        }

        public function testChatCommandIsDispatchedToScripts() : void{
                $this->engine->dispatchEvent("chatSend", self::chatPayload("!time"), true);

                self::assertContains("event.cancel", $this->hostCalls, "The !time command should cancel the chat message");
                self::assertContains("player.sendMessage", $this->hostCalls, "The !time command should reply to the sender");
        }

        public function testPlatformCommandPlacesBlocks() : void{
                $this->engine->dispatchEvent("chatSend", self::chatPayload("!platform"), true);

                self::assertNotEmpty(
                        array_filter($this->hostCalls, static fn(string $name) : bool => $name === "world.setBlock"),
                        "The !platform command should place blocks through world.setBlock"
                );
        }

        public function testNonCommandChatIsUntouched() : void{
                $this->engine->dispatchEvent("chatSend", self::chatPayload("hello everyone"), true);

                self::assertNotContains("event.cancel", $this->hostCalls, "Ordinary chat must not be cancelled");
        }
}
