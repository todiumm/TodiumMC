# Todium sobre Altay (1.26.50)

Base: Altay-master (NetherNet com transporte em thread, pacotes de protocolo 1.26.50).

## Adicionado
- `src/custom/` API nativa de Custom Blocks, Items e Entities, sem Customies:
  - `CustomItemFactory::register($id, fn(ItemIdentifier) => ..., ?CreativeInventoryInfo)`
  - `CustomBlockFactory::register($id, fn(BlockIdentifier) => ..., ?CreativeInventoryInfo)`
  - `CustomEntityFactory::getInstance()->registerEntity($class, $id)`
  - Runtime ID de bloco = hash FNV-1a 32 bits do NBT (nome + states ordenados). Validado contra os 22091 blocos de `block_palette.nbt`.
  - Workers de chunk recebem os blocos custom via `RegisterCustomBlocksTask`, com os mesmos type IDs da thread principal.
- `src/behaviorpack/` sistema de behavior packs (pasta `behavior_packs/`), ligado direto no `Server`.
- `Server::getScheduler()` (scheduler nativo), `PluginManager::registerNativeEvent()` e `unregisterNativeEvents()`.
- Config em `todium.yml` (seção `behavior-packs`).

## Nao testado
Nada foi executado num servidor real. Só `php -l` em todos os arquivos tocados.
