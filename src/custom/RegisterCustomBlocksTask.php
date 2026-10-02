<?php

declare(strict_types=1);

namespace pocketmine\custom;

use Closure;
use pmmp\thread\ThreadSafeArray;
use pocketmine\scheduler\AsyncTask;

/**
 * Replays custom block registrations on an async worker, with the exact type IDs of the main thread.
 */
final class RegisterCustomBlocksTask extends AsyncTask{
	private ThreadSafeArray $typeIds;
	private ThreadSafeArray $blockFuncs;
	private ThreadSafeArray $serializers;
	private ThreadSafeArray $deserializers;

	/**
	 * @param array<string, array{int, Closure, Closure|null, Closure|null}> $data
	 */
	public function __construct(array $data){
		$this->typeIds = new ThreadSafeArray();
		$this->blockFuncs = new ThreadSafeArray();
		$this->serializers = new ThreadSafeArray();
		$this->deserializers = new ThreadSafeArray();
		foreach($data as $identifier => [$typeId, $blockFunc, $serializer, $deserializer]){
			$this->typeIds[$identifier] = $typeId;
			$this->blockFuncs[$identifier] = $blockFunc;
			$this->serializers[$identifier] = $serializer;
			$this->deserializers[$identifier] = $deserializer;
		}
	}

	public function onRun() : void{
		foreach($this->blockFuncs as $identifier => $blockFunc){
			CustomBlockFactory::registerWithTypeId($this->typeIds[$identifier], $identifier, $blockFunc, $this->serializers[$identifier], $this->deserializers[$identifier], null);
		}
	}
}
