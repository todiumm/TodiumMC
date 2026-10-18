# Todium Script API

Todium runs the JavaScript in your behaviour packs on the server, so a pack can react to
game events and change the world without a separate plugin. Scripts are written against the
familiar `@minecraft/server` surface, which means an existing pack script usually runs
unchanged.

## How it works

Todium runs the JavaScript itself. When a loaded behaviour pack declares a `script` module,
the server boots a JavaScript engine written in PHP and evaluates the pack's modules in
process:

```
behaviour pack scripts --> native engine (PHP) --> the world
```

Only the PHP side touches the world. A script asks the server to do something; the server
checks it and does it. A server with no script-carrying packs never boots the engine, so it
pays nothing for this feature.

## Requirements

None beyond the server itself. The engine is pure PHP, so there is no Node.js dependency and
nothing extra to install.

## Enabling a pack

List the pack in `resources/behavior_packs.yml`:

```yaml
behavior_stack:
  - TodiumExample.mcpack
  - MyDevelopmentPack
```

Each entry is either an archive (`.mcpack` / `.zip`) or a directory holding a `manifest.json`.
A directory pack is handy while developing: its files are read from disk, and it is zipped
automatically when the server sends it to a client.

The manifest must declare a script module, and the pack must be an addon (`metadata.product_type`
set to `"addon"`) so the client accepts it:

```json
{
  "format_version": 2,
  "header": { "name": "My Pack", "uuid": "...", "version": [1, 0, 0], "min_engine_version": [1, 21, 0] },
  "modules": [
    { "type": "data", "uuid": "...", "version": [1, 0, 0] },
    { "type": "script", "language": "javascript", "entry": "scripts/main.js", "uuid": "...", "version": [1, 0, 0] }
  ],
  "metadata": { "product_type": "addon" },
  "dependencies": [ { "module_name": "@minecraft/server", "version": "1.12.0" } ]
}
```

## API versions

Todium works out which `@minecraft/server` version a pack targets on its own, so there is
nothing to register and no list of versions to keep in step with a new release. Two things are
read when the pack is loaded, most authoritative first:

1. the `@minecraft/server` entry in the pack's `dependencies`, which is how Bedrock itself pins
   the version; then
2. a version written into an import, as in `import { world } from "@minecraft/server@1.11.0"`.

When a pack declares neither, it is treated as targeting the newest surface.

```json
"dependencies": [ { "module_name": "@minecraft/server", "version": "1.12.0" } ]
```

The detected version is reported in the log when the runtime starts:

```
Behaviour pack <uuid> targets @minecraft/server 1.12.0
```

The whole API surface is available to every pack whatever version it targets. A version is a
statement of what a pack was written against, not a limit on what the server hands out, so an
older pack still finds newer symbols instead of hitting a gap. A pack that asks for a version
newer than the one Todium implements logs a warning and keeps running against the newest
surface Todium has; nothing fails to load.

## Events

Subscribe on `world.beforeEvents` to react before something happens, and on
`world.afterEvents` to react after. A handler on a `beforeEvents` signal may cancel the
action and, for chat, may change the message.

| Signal | Kind | Fires when |
| --- | --- | --- |
| `worldLoad` | after | a world is loaded |
| `worldInitialize` | after | a world finishes loading |
| `playerSpawn` | after | a player spawns |
| `playerLeave` | after | a player leaves |
| `entitySpawn` | after | an entity spawns |
| `entityDie` | after | an entity dies |
| `entityHurt` | before, after | an entity takes damage |
| `chatSend` | before | a player sends a chat message |
| `scriptTick` | after | every server tick |

```js
import { world } from "@minecraft/server";

world.afterEvents.playerSpawn.subscribe((event) => {
        event.player.sendMessage(`Welcome, ${event.player.name}!`);
});

world.beforeEvents.chatSend.subscribe((event) => {
        if (event.message === "ping") {
                event.cancel = true;                 // stop the message reaching other players
                event.sender.sendMessage("pong");    // reply to the sender only
        }
});
```

## Scheduled work

`system.runInterval` and `system.runTimeout` schedule work on ticks. Both return a handle you
can pass to `system.clearRun` to cancel it.

```js
import { system } from "@minecraft/server";

const handle = system.runInterval(() => {
        // runs every 20 ticks (one second)
}, 20);

system.runTimeout(() => system.clearRun(handle), 200);
```

## The `world` object

| Member | Description |
| --- | --- |
| `world.getDimension(id)` | returns a `Dimension` (`"overworld"`, `"nether"`, `"the_end"`) |
| `world.getPlayers(options?)` | returns every player, or those matching `options.name` |
| `world.getAllPlayers()` | returns every player |
| `world.sendMessage(message)` | broadcasts a message to the whole server |
| `world.getTimeOfDay()` / `world.setTimeOfDay(ticks)` | reads or sets the time |
| `world.getDay()` | reads the current day number |

## The `Dimension` object

| Member | Description |
| --- | --- |
| `dimension.id` | the dimension identifier |
| `dimension.getPlayers()` | the players in this dimension |
| `dimension.getEntities()` | the entities in this dimension |
| `dimension.getBlock(location)` | the `Block` at a location |
| `dimension.setBlockType(location, typeId)` | places a block by type ID |
| `dimension.setBlockPermutation(location, permutation)` | places a block from a `BlockPermutation` |
| `dimension.playSound(soundId, location, options?)` | plays a sound |
| `dimension.getTimeOfDay()` / `dimension.setTimeOfDay(ticks)` | reads or sets this dimension's time |

## The `Entity` object

| Member | Description |
| --- | --- |
| `entity.id` | the runtime entity ID |
| `entity.typeId` | the entity type identifier |
| `entity.nameTag` | the entity's name tag |
| `entity.location` | the entity's position as a `Vector3` |
| `entity.dimension` | the dimension holding the entity |
| `entity.isValid` | whether the entity is still alive in the world |
| `entity.getRotation()` | the entity's `{ x: pitch, y: yaw }` rotation |
| `entity.getComponent(componentId)` | a component; `minecraft:health` is supported |

## The `Player` object

A `Player` is an `Entity` with these extras:

| Member | Description |
| --- | --- |
| `player.name` | the player's name |
| `player.gameMode` | the player's game mode |
| `player.xpLevel` | the player's experience level |
| `player.isOp` | whether the player is an operator |
| `player.getRotation()` | the player's `{ x: pitch, y: yaw }` rotation |
| `player.sendMessage(message)` | sends a message to this player |
| `player.hasPermission(permission)` | checks a permission |
| `player.teleport(location, options?)` | teleports the player |
| `player.runCommand(command)` | runs a server command as the player |

## The `Block` object

| Member | Description |
| --- | --- |
| `block.typeId` | the block's type identifier |
| `block.location` | the block's position as a `Vector3` |
| `block.permutation` | the block's `BlockPermutation` |
| `block.isAir` / `block.isLiquid` | flags for air and liquid blocks |
| `block.setType(type)` | replaces the block |
| `block.setPermutation(permutation)` | replaces the block from a permutation |

## Other exports

`Vector3`, `BlockPermutation`, `BlockType`, `BlockTypes`, `EntityDamageCause`, `GameMode`,
`serverName` and `minecraftVersion` are exported for convenience.

## Example

`examples/behavior_packs/TodiumExample` is a complete pack. It greets joining players, logs
deaths, answers `ping` in chat, and provides the `!where`, `!time`, `!heal`, `!platform` and
`!players` chat commands. To try it, copy the directory into your server's `behavior_packs`
folder and add `TodiumExample` to `resources/behavior_packs.yml`.
