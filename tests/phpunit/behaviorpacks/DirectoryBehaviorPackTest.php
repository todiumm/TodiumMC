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

namespace pocketmine\behaviorpacks;

use PHPUnit\Framework\TestCase;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function strlen;
use function substr;
use function sys_get_temp_dir;
use function uniqid;

final class DirectoryBehaviorPackTest extends TestCase{

        private string $packDirectory;

        protected function setUp() : void{
                $this->packDirectory = sys_get_temp_dir() . "/todium-pack-" . uniqid();
                mkdir($this->packDirectory . "/scripts", 0777, true);

                file_put_contents($this->packDirectory . "/manifest.json", json_encode([
                        "format_version" => 2,
                        "header" => [
                                "name" => "Test Pack",
                                "description" => "unit test",
                                "uuid" => "6f1a5d2c-1f3e-4a5b-9c7d-2b8e4f6a1d30",
                                "version" => [1, 2, 3],
                                "min_engine_version" => [1, 21, 0]
                        ],
                        "modules" => [
                                ["type" => "data", "uuid" => "11111111-1111-1111-1111-111111111111", "version" => [1, 0, 0]],
                                ["type" => "script", "language" => "javascript", "entry" => "scripts/main.js", "uuid" => "22222222-2222-2222-2222-222222222222", "version" => [1, 0, 0]]
                        ],
                        "dependencies" => [
                                ["module_name" => "@minecraft/server", "version" => [1, 0, 0]]
                        ]
                ]));
                file_put_contents($this->packDirectory . "/scripts/main.js", "export const hello = 1;\n");
        }

        public function testManifestIsExposed() : void{
                $pack = new DirectoryBehaviorPack($this->packDirectory);

                self::assertSame("Test Pack", $pack->getPackName());
                self::assertSame("6f1a5d2c-1f3e-4a5b-9c7d-2b8e4f6a1d30", $pack->getPackId());
                self::assertSame("1.2.3", $pack->getPackVersion());
        }

        public function testScriptModuleIsDetected() : void{
                $pack = new DirectoryBehaviorPack($this->packDirectory);

                self::assertTrue($pack->hasScripts());
                self::assertSame(["scripts/main.js"], $pack->getScriptEntries());
                self::assertSame([BehaviorPack::MODULE_TYPE_DATA, BehaviorPack::MODULE_TYPE_SCRIPT], $pack->getModuleTypes());
        }

        public function testDataAndScriptModulesMakeThePackAnAddon() : void{
                self::assertTrue((new DirectoryBehaviorPack($this->packDirectory))->isAddonPack());
        }

        public function testResourceOnlyPackIsNotAnAddon() : void{
                $directory = sys_get_temp_dir() . "/todium-pack-" . uniqid();
                mkdir($directory, 0777, true);
                file_put_contents($directory . "/manifest.json", json_encode([
                        "format_version" => 2,
                        "header" => [
                                "name" => "Resources Only",
                                "description" => "",
                                "uuid" => "aaaaaaaa-bbbb-cccc-dddd-eeeeeeeeeeee",
                                "version" => [1, 0, 0]
                        ],
                        "modules" => [
                                ["type" => "resources", "uuid" => "33333333-3333-3333-3333-333333333333", "version" => [1, 0, 0]]
                        ]
                ]));

                $pack = new DirectoryBehaviorPack($directory);
                self::assertFalse($pack->hasScripts());
                self::assertFalse($pack->isAddonPack());
        }

        public function testFilesAreReadable() : void{
                $pack = new DirectoryBehaviorPack($this->packDirectory);

                self::assertTrue($pack->fileExists("scripts/main.js"));
                self::assertSame("export const hello = 1;\n", $pack->readFile("scripts/main.js"));
                self::assertFalse($pack->fileExists("scripts/missing.js"));
        }

        public function testArchiveIsServedToClients() : void{
                $pack = new DirectoryBehaviorPack($this->packDirectory);

                self::assertGreaterThan(0, $pack->getPackSize());
                self::assertSame(32, strlen($pack->getSha256(false)));
                self::assertSame("PK", substr($pack->getPackChunk(0, 1024), 0, 2));
        }

        public function testMissingDirectoryIsRejected() : void{
                $this->expectException(BehaviorPackException::class);
                new DirectoryBehaviorPack(sys_get_temp_dir() . "/does-not-exist-" . uniqid());
        }

        public function testMissingManifestIsRejected() : void{
                $directory = sys_get_temp_dir() . "/todium-pack-" . uniqid();
                mkdir($directory, 0777, true);

                $this->expectException(BehaviorPackException::class);
                new DirectoryBehaviorPack($directory);
        }
}
