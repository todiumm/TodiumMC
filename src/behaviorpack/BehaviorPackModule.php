<?php

declare(strict_types=1);

namespace behaviorpack;

use behaviorpack\custom\CustomContentLoader;
use behaviorpack\entity\EntityLoader;
use behaviorpack\loot\LootTableLoader;
use behaviorpack\recipe\RecipeLoader;
use behaviorpack\script\ScriptLoader;
use pocketmine\event\EventPriority;
use pocketmine\event\plugin\PluginEnableEvent;
use pocketmine\Server;
use Symfony\Component\Filesystem\Path;
use Throwable;
use function count;
use function is_dir;

/**
 * Loads the behavior packs dropped in the configured folder, behavior_pack
 * next to resource_packs by default.
 *
 * When Customies is installed, every loader runs as soon as Customies enables,
 * so custom items, blocks and entities are registered before Customies freezes
 * them and before the recipes and loot tables that reference them. Without
 * Customies, the loaders that need it are skipped and the others run when this
 * plugin enables.
 */
final class BehaviorPackModule{

	public const CUSTOMIES = "Customies";

	/** @var list<ContentLoader> */
	private array $loaders = [];

	/** @var list<BehaviorPack> */
	private array $packs = [];

	private bool $loaded = false;

	public function __construct(
		private Server $server,
		private BehaviorPackSettings $settings
	){
		if($settings->itemsAndBlocks){
			$this->loaders[] = new CustomContentLoader($server);
		}
		if($settings->entities){
			$this->loaders[] = new \behaviorpack\entity\animation\AnimationLoader($server);
			$this->loaders[] = new EntityLoader($server);
			$this->loaders[] = new \behaviorpack\spawn\SpawnRuleLoader($server);
		}
		if($settings->recipes){
			$this->loaders[] = new RecipeLoader($server);
		}
		if($settings->lootTables){
			$this->loaders[] = new LootTableLoader($server);
		}
		if($settings->scripts){
			$this->loaders[] = new ScriptLoader($server, $settings->scriptTimeoutMs);
		}
	}

	public function getPacksDirectory() : string{
		if(Path::isAbsolute($this->settings->folder)){
			return $this->settings->folder;
		}

		$dataPath = $this->server->getDataPath();
		$configured = Path::join($dataPath, $this->settings->folder);

		// Older Todium builds used "behavior_packs". Keep that configuration
		// working, but prefer the new singular "behavior_pack" directory when
		// the old directory does not exist.
		if($this->settings->folder === "behavior_packs" && !is_dir($configured)){
			$singular = Path::join($dataPath, "behavior_pack");
			if(is_dir($singular)){
				return $singular;
			}
		}

		return $configured;
	}

	/**
	 * @return list<BehaviorPack>
	 */
	public function getPacks() : array{
		return $this->packs;
	}

	public function enable() : void{
		$discovery = new PackDiscovery(Path::join($this->server->getDataPath(), "todium", "cache", "behavior_packs"), $this->server->getLogger());
		$this->packs = $discovery->discover($this->getPacksDirectory());
		foreach($this->packs as $pack){
			\behaviorpack\entity\behavior\inventory\TradeTableSystem::registerPackRoot($pack->getPath());
		}
		if(count($this->packs) === 0){
			return;
		}
		$this->server->getLogger()->info("Behavior packs found: " . count($this->packs));

		// Todium integrates the custom-content implementation directly through
		// Composer; there is no external Customies plugin lifecycle to wait for.
		$this->runLoaders(true);
	}

	public function disable() : void{
		foreach($this->loaders as $server){
			try{
				$server->close();
			}catch(Throwable $e){
				$this->server->getLogger()->logException($e);
			}
		}
	}

	private function runLoaders(bool $customies) : void{
		if($this->loaded){
			return;
		}
		$this->loaded = true;

		foreach($this->loaders as $server){
			if($server->requiresCustomies() && !$customies){
				continue;
			}
			try{
				$server->load($this->packs);
			}catch(Throwable $e){
				$this->server->getLogger()->error("Behavior packs: " . $server->getName() . " failed");
				$this->server->getLogger()->logException($e);
			}
		}
		//chunk encoding runs on workers, which must know every custom block state
		\pocketmine\custom\CustomBlockFactory::syncWorkers($this->server->getAsyncPool());
	}
}
