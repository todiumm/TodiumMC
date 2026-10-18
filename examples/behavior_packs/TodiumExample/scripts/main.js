import { world, system, BlockPermutation, EntityDamageCause } from "@minecraft/server";

// -------------------------------------------------------------------------------------------------------------------
// Events
// -------------------------------------------------------------------------------------------------------------------

world.afterEvents.worldLoad.subscribe((event) => {
	console.log(`World "${event.worldName}" is ready`);
});

world.afterEvents.playerSpawn.subscribe((event) => {
	const player = event.player;
	player.sendMessage(`Welcome to ${world.getDimension(player.dimension.id).id}, ${player.name}!`);
	console.log(`${player.name} spawned at ${player.location} in ${player.dimension.id}`);
});

world.afterEvents.playerLeave.subscribe((event) => {
	console.log(`${event.playerName} left the game`);
});

world.afterEvents.entityDie.subscribe((event) => {
	const dead = event.deadEntity;
	world.sendMessage(`${dead.typeId} died (cause: ${event.damageSource.cause})`);
});

// The "before" chat event is cancellable and its message is mutable, both of which are honoured server-side.
world.beforeEvents.chatSend.subscribe((event) => {
	// Demonstrates cancelling a "before" event and replying without the message reaching other players.
	if (event.message === "ping") {
		event.cancel = true;
		event.sender.sendMessage("pong");
	}
});

// -------------------------------------------------------------------------------------------------------------------
// A tiny command handler built on top of the chat event
// -------------------------------------------------------------------------------------------------------------------

const COMMANDS = {
	where(event) {
		const location = event.sender.location;
		event.sender.sendMessage(`You are at ${location.x.toFixed(1)}, ${location.y.toFixed(1)}, ${location.z.toFixed(1)}`);
	},
	time(event) {
		event.sender.sendMessage(`Time of day is ${world.getTimeOfDay()} (day ${world.getDay()})`);
	},
	heal(event) {
		event.sender.sendMessage("You have been healed");
	},
	platform(event) {
		const location = event.sender.location;
		const y = Math.floor(location.y) - 1;
		for (let x = -2; x <= 2; x++) {
			for (let z = -2; z <= 2; z++) {
				world.getDimension(event.sender.dimension.id).setBlockType(
					{ x: Math.floor(location.x) + x, y, z: Math.floor(location.z) + z },
					"minecraft:glass"
				);
			}
		}
		event.sender.sendMessage("Built a glass platform under you");
	},
	players(event) {
		const names = world.getAllPlayers().map((player) => player.name);
		event.sender.sendMessage(`Online (${names.length}): ${names.join(", ")}`);
	}
};

world.afterEvents.playerSpawn.subscribe((event) => {
	if (!event.initialSpawn) {
		return;
	}
	event.player.sendMessage("Try: !where, !time, !heal, !platform, !players");
});

world.beforeEvents.chatSend.subscribe((event) => {
	if (!event.message.startsWith("!")) {
		return;
	}
	const [rawName] = event.message.slice(1).split(" ");
	const command = COMMANDS[rawName.toLowerCase()];
	event.cancel = true;
	if (command === undefined) {
		event.sender.sendMessage(`Unknown command "!${rawName}"`);
		return;
	}
	command(event);
});

// -------------------------------------------------------------------------------------------------------------------
// A repeating task: give everyone a short regeneration effect by healing them every 100 ticks
// -------------------------------------------------------------------------------------------------------------------

system.runInterval(() => {
	for (const player of world.getAllPlayers()) {
		const health = player.getComponent("minecraft:health");
		if (health.currentValue < health.effectiveMax) {
			player.heal(2);
			player.sendMessage("You feel a little better");
		}
	}
}, 100);

// -------------------------------------------------------------------------------------------------------------------
// Persistent counters using dynamic properties
// -------------------------------------------------------------------------------------------------------------------

world.afterEvents.playerSpawn.subscribe((event) => {
	const key = `joins:${event.player.name}`;
	const joins = (world.getDynamicProperty(key) ?? 0) + 1;
	world.setDynamicProperty(key, joins);
	event.player.sendMessage(`This is your visit number ${joins}`);
});

// -------------------------------------------------------------------------------------------------------------------
// Block utilities
// -------------------------------------------------------------------------------------------------------------------

world.beforeEvents.chatSend.subscribe((event) => {
	if (event.message !== "!build") {
		return;
	}
	event.cancel = true;

	const dimension = world.getDimension(event.sender.dimension.id);
	const origin = event.sender.location;
	const start = { x: Math.floor(origin.x), y: Math.floor(origin.y), z: Math.floor(origin.z) };
	const permutation = BlockPermutation.resolve("minecraft:stone");

	for (let x = 0; x < 3; x++) {
		for (let z = 0; z < 3; z++) {
			dimension.setBlockPermutation({ x: start.x + x, y: start.y + 3, z: start.z + z }, permutation);
		}
	}
	dimension.playSound("random.orb", start);
	event.sender.sendMessage("Built a 3x3 stone roof above you");
});
