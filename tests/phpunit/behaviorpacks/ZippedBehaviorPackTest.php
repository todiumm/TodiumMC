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
use ZipArchive;
use function file_put_contents;
use function json_encode;
use function mkdir;
use function sys_get_temp_dir;
use function uniqid;
use function unlink;

final class ZippedBehaviorPackTest extends TestCase{

        private string $packDirectory;
        private string $zipPath;

        protected function setUp() : void{
                $this->packDirectory = sys_get_temp_dir() . "/todium-zpack-" . uniqid();
                mkdir($this->packDirectory . "/scripts", 0777, true);

                file_put_contents($this->packDirectory . "/manifest.json", json_encode([
                        "format_version" => 2,
                        "header" => [
                                "name" => "Zipped Test Pack",
                                "description" => "unit test",
                                "uuid" => "6f1a5d2c-1f3e-4a5b-9c7d-2b8e4f6a1d30",
                                "version" => [1, 0, 0]
                        ],
                        "modules" => [
                                ["type" => "script", "language" => "javascript", "entry" => "scripts/main.js", "uuid" => "22222222-2222-2222-2222-222222222222", "version" => [1, 0, 0]]
                        ]
                ]));
                file_put_contents($this->packDirectory . "/scripts/main.js", "export const hello = 1;\n");

                $this->zipPath = sys_get_temp_dir() . "/todium-zpack-" . uniqid() . ".mcpack";
                $zip = new ZipArchive();
                $zip->open($this->zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE);
                $zip->addFile($this->packDirectory . "/manifest.json", "manifest.json");
                $zip->addFile($this->packDirectory . "/scripts/main.js", "scripts/main.js");
                $zip->close();
        }

        protected function tearDown() : void{
                @unlink($this->zipPath);
        }

        public function testManifestIsReadFromArchive() : void{
                $pack = new ZippedBehaviorPack($this->zipPath);

                self::assertSame("Zipped Test Pack", $pack->getPackName());
                self::assertSame("6f1a5d2c-1f3e-4a5b-9c7d-2b8e4f6a1d30", $pack->getPackId());
                self::assertSame("1.0.0", $pack->getPackVersion());
        }

        public function testScriptModuleIsDetected() : void{
                $pack = new ZippedBehaviorPack($this->zipPath);

                self::assertTrue($pack->hasScripts());
                self::assertTrue($pack->isAddonPack());
                self::assertSame(["scripts/main.js"], $pack->getScriptEntries());
                self::assertSame(["manifest.json", "scripts/main.js"], $pack->listFiles());
        }

        public function testFilesAreReadFromArchive() : void{
                $pack = new ZippedBehaviorPack($this->zipPath);

                self::assertTrue($pack->fileExists("scripts/main.js"));
                self::assertSame("export const hello = 1;\n", $pack->readFile("scripts/main.js"));
                self::assertNull($pack->readFile("scripts/missing.js"));
        }

        public function testArchiveBytesAreServed() : void{
                $pack = new ZippedBehaviorPack($this->zipPath);

                self::assertSame("PK", substr($pack->getPackChunk(0, 1024), 0, 2));
                self::assertSame(32, strlen($pack->getSha256(false)));
        }

        public function testMissingFileIsRejected() : void{
                $this->expectException(BehaviorPackException::class);
                new ZippedBehaviorPack(sys_get_temp_dir() . "/nope-" . uniqid() . ".mcpack");
        }

        public function testPathTraversalIsBlocked() : void{
                $pack = new ZippedBehaviorPack($this->zipPath);

                $this->expectException(BehaviorPackException::class);
                $pack->readFile("../../etc/passwd");
        }
}
