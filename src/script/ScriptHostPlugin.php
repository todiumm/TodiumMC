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

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerChatEvent;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\world\WorldLoadEvent;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginBase;
use pocketmine\plugin\PluginDescription;
use pocketmine\plugin\PluginLoader;
use pocketmine\plugin\ResourceProvider;
use pocketmine\Server;

/**
 * Internal plugin that owns the native Script API runtime.
 *
 * The runtime is exposed to the plugin manager like any other plugin so that scripts can use the familiar event
 * dispatch machinery. Scripts are not registered as PHP listeners: instead this plugin translates PocketMine events
 * into Script API events and forwards them to the native JavaScript engine.
 */
final class ScriptHostPlugin extends PluginBase implements Listener{

	private ScriptRuntime $runtime;

	/**
	 * Builds the host plugin with the plugin-manager metadata it needs, wiring in the runtime afterwards so that the
	 * inherited constructor signature stays intact.
	 */
	public static function create(ScriptRuntime $runtime, Server $server, PluginLoader $loader) : self{
		$dataFolder = $server->getDataPath() . "script_api/";
		$plugin = new self(
			$loader,
			$server,
			new PluginDescription([
				"name" => "TodiumScriptApi",
				"version" => "1.0.0",
				"main" => self::class,
				"api" => ["5.0.0"],
				"description" => "Native Bedrock Script API runtime"
			]),
			$dataFolder,
			$dataFolder,
			new class implements ResourceProvider{
				public function getResource(string $filename){
					return null;
				}

				public function getResources() : array{
					return [];
				}
			}
		);
		$plugin->runtime = $runtime;
		return $plugin;
	}

	protected function onEnable() : void{
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
	}

	/**
	 * @priority MONITOR
	 */
	public function onWorldLoad(WorldLoadEvent $event) : void{
		$this->runtime->dispatchEvent("worldLoad", [
			"worldName" => $event->getWorld()->getFolderName()
		], false);
	}

	public function onPlayerJoin(PlayerJoinEvent $event) : void{
		$player = $event->getPlayer();
		$this->runtime->dispatchEvent("playerSpawn", [
			"player" => $this->runtime->describePlayer($player),
			"initialSpawn" => !$player->hasPlayedBefore()
		], false);
	}

	public function onPlayerQuit(PlayerQuitEvent $event) : void{
		$player = $event->getPlayer();
		$this->runtime->dispatchEvent("playerLeave", [
			"player" => $this->runtime->describePlayer($player)
		], false);
	}

	public function onPlayerDeath(PlayerDeathEvent $event) : void{
		$player = $event->getPlayer();
		$this->runtime->dispatchEvent("entityDie", [
			"deadEntity" => $this->runtime->describePlayer($player),
			"damageSource" => [
				"cause" => "none",
				"damagingEntity" => null
			]
		], false);
	}

	/**
	 * Chat is a "before" event because scripts are allowed to mutate or cancel it.
	 *
	 * @priority MONITOR
	 */
	public function onPlayerChat(PlayerChatEvent $event) : void{
		$handle = $this->runtime->registerEventHandle($event);
		try{
			$this->runtime->dispatchEvent("chatSend", [
				"handle" => $handle,
				"message" => $event->getMessage(),
				"sender" => $this->runtime->describePlayer($event->getPlayer())
			], true, ScriptRuntime::BEFORE_EVENT_TIMEOUT);
		}finally{
			$this->runtime->releaseEventHandle($handle);
		}
	}

	public function getRuntime() : ScriptRuntime{
		return $this->runtime;
	}
}
