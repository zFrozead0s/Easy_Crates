<?php

declare(strict_types=1);

namespace cykp\crates;

use DavyCraft648\PMServerUI\ActionFormData;
use DavyCraft648\PMServerUI\ActionFormResponse;
use DavyCraft648\PMServerUI\ModalFormData;
use DavyCraft648\PMServerUI\ModalFormResponse;
use DavyCraft648\PMServerUI\PMServerUI;
use muqsit\invmenu\InvMenu;
use muqsit\invmenu\InvMenuHandler;
use muqsit\invmenu\transaction\InvMenuTransaction;
use muqsit\invmenu\transaction\InvMenuTransactionResult;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\event\Listener;
use pocketmine\event\block\BlockBreakEvent;
use pocketmine\event\block\BlockExplodeEvent;
use pocketmine\event\entity\EntityExplodeEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\inventory\Inventory;
use pocketmine\math\Vector3;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\TextFormat;
use pocketmine\world\particle\FloatingTextParticle;

final class Main extends PluginBase implements Listener{

    /** @var array<string, RouletteSession> */
    private array $sessions = [];
    /** @var array<string, array{menu: InvMenu, type: string}> */
    private array $editors = [];
    /** @var array<string, string> */
    private array $pendingPlace = [];
    /** @var array<string, bool> */
    private array $pendingRemove = [];
    /** @var array<string, array{id: string, pos: Vector3, world: string}> */
    private array $placements = [];
    // visual task removed

    protected function onEnable() : void{
        if(!class_exists(InvMenu::class)){
            $this->getLogger()->error("InvMenu no encontrado. Instala el virion muqsit/invmenu.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }
        if(!class_exists(ActionFormData::class)){
            $this->getLogger()->error("PMServerUI no encontrado. Instala DavyCraft648/PMServerUI.");
            $this->getServer()->getPluginManager()->disablePlugin($this);
            return;
        }

        if(!InvMenuHandler::isRegistered()){
            InvMenuHandler::register($this);
        }
        PMServerUI::register($this);
        $this->saveDefaultConfig();
        $this->loadPlacements();
        $this->getServer()->getPluginManager()->registerEvents($this, $this);

        foreach($this->placements as $entry){
            $this->spawnHologram($entry["id"], $entry["world"], $entry["pos"]);
        }

    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if($command->getName() !== "crate"){
            return false;
        }

        if(!$sender instanceof Player){
            $sender->sendMessage(TextFormat::RED . "Players only.");
            return true;
        }

        if(count($args) === 0){
            $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate create <id> <format> | /crate edit <id> item|probability | /crate place <id> | /crate remove | /crate delete <id> | /crate givekey <id> <amount> <player|all> | /crate reload");
            return true;
        }

        $sub = strtolower($args[0]);
        if($sub === "reload"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            $this->reloadConfig();
            $this->loadPlacements();
            $sender->sendMessage(TextFormat::GREEN . "Config reloaded.");
            return true;
        }

        if($sub === "create"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            if(count($args) < 2){
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate create <id> <format>");
                return true;
            }
            $id = strtolower($args[1]);
            $display = count($args) >= 3 ? implode(" ", array_slice($args, 2)) : ucfirst($id);
            $this->createCrate($sender, $id, $display);
            return true;
        }

        if($sub === "edit"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            if(count($args) < 3){
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate edit <id> item|probability");
                return true;
            }
            $id = strtolower($args[1]);
            $mode = strtolower($args[2]);
            if($mode === "item"){
                $this->openEditInventory($sender, $id);
                return true;
            }
            if($mode === "probability"){
                $this->openProbabilityList($sender, $id);
                return true;
            }
            $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate edit <id> item|probability");
            return true;
        }

        if($sub === "place"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            $id = strtolower($args[1] ?? "");
            if($id === ""){
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate place <id>");
                return true;
            }
            if(!$this->crateExists($id)){
                $sender->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
                return true;
            }
            $this->pendingPlace[$sender->getName()] = $id;
            $sender->sendMessage(TextFormat::GOLD . "Touch a block to place the crate.");
            return true;
        }

        if($sub === "remove"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            $this->pendingRemove[$sender->getName()] = true;
            $sender->sendMessage(TextFormat::GOLD . "Touch a block to remove the crate.");
            return true;
        }

        if($sub === "delete"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            $id = strtolower($args[1] ?? "");
            if($id === ""){
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate delete <id>");
                return true;
            }
            if(!$this->deleteCrate($id)){
                $sender->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
                return true;
            }
            $sender->sendMessage(TextFormat::GREEN . "Crate '$id' deleted.");
            return true;
        }

        if($sub === "givekey"){
            if(!$sender->hasPermission("crates.admin")){
                $sender->sendMessage(TextFormat::RED . "No permission.");
                return true;
            }
            if(count($args) < 4){
                $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate givekey <id> <amount> <player|all>");
                return true;
            }
            $id = strtolower($args[1]);
            $amount = max(1, (int) $args[2]);
            $target = $args[3];
            $this->giveKey($sender, $id, $amount, $target);
            return true;
        }

        $sender->sendMessage(TextFormat::YELLOW . "Usage: /crate create <id> <format> | /crate edit <id> item|probability | /crate place <id> | /crate remove | /crate delete <id> | /crate givekey <id> <amount> <player|all> | /crate reload");
        return true;
    }

    public function openCrate(Player $player, string $id) : void{
        $id = strtolower($id);
        if(isset($this->sessions[$player->getName()])){
            $player->sendMessage(TextFormat::RED . "You already have a crate open.");
            return;
        }

        $crates = $this->getConfig()->get("crates", []);
        if(!is_array($crates) || !isset($crates[$id])){
            $player->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
            return;
        }

        $session = new RouletteSession($this, $player, $id, $crates[$id]);
        $this->sessions[$player->getName()] = $session;
        $session->start();
    }

    public function clearSession(Player $player) : void{
        unset($this->sessions[$player->getName()]);
    }

    private function createCrate(Player $player, string $id, string $display) : void{
        $config = $this->getConfig();
        $crates = $config->get("crates", []);
        if(!is_array($crates)){
            $crates = [];
        }
        if(isset($crates[$id])){
            $player->sendMessage(TextFormat::RED . "That crate already exists.");
            return;
        }

        $crates[$id] = [
            "id" => $id,
            "display" => $display,
            "lore" => [
                "&7Left-click to view items",
                "&eRight-click to open"
            ],
            "rewards" => []
        ];

        $config->set("crates", $crates);
        $config->save();

        $player->sendMessage(TextFormat::GREEN . "Crate '$id' created. Now add items.");
        $this->openEditInventory($player, $id);
    }

    private function openEditInventory(Player $player, string $id) : void{
        $id = strtolower($id);
        if(isset($this->sessions[$player->getName()]) || isset($this->editors[$player->getName()])){
            $player->sendMessage(TextFormat::RED . "You already have a crate open.");
            return;
        }

        $config = $this->getConfig();
        $crates = $config->get("crates", []);
        if(!is_array($crates) || !isset($crates[$id]) || !is_array($crates[$id])){
            $player->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
            return;
        }

        $menu = InvMenu::create(InvMenuTypeIds::TYPE_DOUBLE_CHEST);
        $title = isset($crates[$id]["display"]) ? TextFormat::colorize((string) $crates[$id]["display"]) : "Crate";
        $menu->setName($title);
        $menu->setListener(function(InvMenuTransaction $transaction) : InvMenuTransactionResult{
            return $transaction->continue();
        });

        $this->loadEditInventory($menu->getInventory(), (array) ($crates[$id]["rewards"] ?? []));

        $menu->setInventoryCloseListener(function(Player $player, Inventory $inventory) use ($id) : void{
            $this->saveEdit($id, $inventory);
            unset($this->editors[$player->getName()]);
            $player->sendMessage(TextFormat::GREEN . "Crate '$id' saved.");
        });

        $this->editors[$player->getName()] = ["menu" => $menu, "type" => $id];
        $menu->send($player);
    }

    private function openProbabilityList(Player $player, string $id) : void{
        $id = strtolower($id);
        $crates = $this->getConfig()->get("crates", []);
        if(!is_array($crates) || !isset($crates[$id])){
            $player->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
            return;
        }

        $rewards = (array) ($crates[$id]["rewards"] ?? []);
        if(count($rewards) === 0){
            $player->sendMessage(TextFormat::RED . "This crate has no items.");
            return;
        }

        $buttons = [];
        foreach($rewards as $entry){
            if(!is_array($entry) || !isset($entry["nbt"])){
                continue;
            }
            $item = ItemCodec::decode((string) $entry["nbt"]);
            if($item === null){
                continue;
            }
            $slot = (int) ($entry["slot"] ?? -1);
            $weight = (int) ($entry["weight"] ?? 1);
            $buttons[] = [
                "slot" => $slot,
                "text" => $item->getName() . " (slot $slot)\nWeight: $weight"
            ];
        }

        if(count($buttons) === 0){
            $player->sendMessage(TextFormat::RED . "No valid items in this crate.");
            return;
        }

        $form = ActionFormData::create()
            ->title("Probabilities - $id")
            ->body("Select an item to edit its weight.");
        foreach($buttons as $button){
            $form->button($button["text"]);
        }
        $form->show($player)->then(function(Player $player, ActionFormResponse $response) use ($buttons, $id) : void{
            if($response->canceled){
                return;
            }
            $index = $response->selection;
            if($index === null || !isset($buttons[$index])){
                return;
            }
            $slot = $buttons[$index]["slot"];
            $this->openProbabilityInput($player, $id, $slot);
        });
    }

    private function openProbabilityInput(Player $player, string $id, int $slot) : void{
        $form = ModalFormData::create()
            ->title("Weight - $id")
            ->label("Slot: $slot")
            ->textField("Weight (number)", "1");

        $form->show($player)->then(function(Player $player, ModalFormResponse $response) use ($id, $slot) : void{
            if($response->canceled || $response->formValues === null){
                return;
            }
            $value = $response->formValues[1] ?? "1";
            $weight = (int) $value;
            if($weight < 1){
                $weight = 1;
            }
            $this->setWeight($player, $id, $slot, $weight);
        });
    }

    private function loadEditInventory(Inventory $inventory, array $rewards) : void{
        foreach($rewards as $entry){
            if(!is_array($entry)){
                continue;
            }
            $slot = isset($entry["slot"]) ? (int) $entry["slot"] : -1;
            if($slot < 0 || $slot >= $inventory->getSize()){
                continue;
            }
            if(!isset($entry["nbt"])){
                continue;
            }
            $item = ItemCodec::decode((string) $entry["nbt"]);
            if($item === null){
                continue;
            }
            $inventory->setItem($slot, $item);
        }
    }

    private function saveEdit(string $id, Inventory $inventory) : void{
        $config = $this->getConfig();
        $crates = $config->get("crates", []);
        if(!is_array($crates)){
            $crates = [];
        }
        if(!isset($crates[$id]) || !is_array($crates[$id])){
            $crates[$id] = ["id" => $id, "display" => "&6Crate " . ucfirst($id), "rewards" => []];
        }

        $weightsBySlot = $this->getWeightsBySlot((array) ($crates[$id]["rewards"] ?? []));
        $rewards = [];
        for($slot = 0; $slot < $inventory->getSize(); $slot++){
            $item = $inventory->getItem($slot);
            if($item->isNull()){
                continue;
            }
            $rewards[] = [
                "slot" => $slot,
                "nbt" => ItemCodec::encode($item),
                "weight" => $weightsBySlot[$slot] ?? 1
            ];
        }

        $crates[$id]["id"] = $id;
        $crates[$id]["rewards"] = $rewards;
        $config->set("crates", $crates);
        $config->save();
    }

    private function getWeightsBySlot(array $rewards) : array{
        $weights = [];
        foreach($rewards as $entry){
            if(!is_array($entry)){
                continue;
            }
            if(!isset($entry["slot"]) || !isset($entry["weight"])){
                continue;
            }
            $slot = (int) $entry["slot"];
            $weights[$slot] = max(1, (int) $entry["weight"]);
        }
        return $weights;
    }

    private function setWeight(Player $player, string $id, int $slot, int $weight) : void{
        $id = strtolower($id);
        $config = $this->getConfig();
        $crates = $config->get("crates", []);
        if(!is_array($crates) || !isset($crates[$id]) || !is_array($crates[$id])){
            $player->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
            return;
        }

        $rewards = (array) ($crates[$id]["rewards"] ?? []);
        $updated = false;
        foreach($rewards as $index => $entry){
            if(!is_array($entry)){
                continue;
            }
            if(((int) ($entry["slot"] ?? -1)) === $slot){
                $rewards[$index]["weight"] = max(1, $weight);
                $updated = true;
                break;
            }
        }

        if(!$updated){
            $player->sendMessage(TextFormat::RED . "No item in that slot.");
            return;
        }

        $crates[$id]["rewards"] = $rewards;
        $config->set("crates", $crates);
        $config->save();
        $player->sendMessage(TextFormat::GREEN . "Weight updated for crate '$id' slot $slot.");
    }

    private function createKeyItem(string $id, int $amount) : \pocketmine\item\Item{
        $item = VanillaItems::PAPER();
        $item->setCustomName(TextFormat::colorize("&6Key &f" . $id));
        $item->setCount(max(1, $amount));
        $item->getNamedTag()->setString("crate_key", $id);
        return $item;
    }

    private function giveKey(Player $sender, string $id, int $amount, string $target) : void{
        if(!$this->crateExists($id)){
            $sender->sendMessage(TextFormat::RED . "Unknown crate: " . $id);
            return;
        }
        $item = $this->createKeyItem($id, $amount);
        if(strtolower($target) === "all"){
            foreach($this->getServer()->getOnlinePlayers() as $player){
                $left = $player->getInventory()->addItem(clone $item);
                foreach($left as $drop){
                    $player->getWorld()->dropItem($player->getPosition(), $drop);
                }
            }
            $sender->sendMessage(TextFormat::GREEN . "Keys given to todos.");
            return;
        }

        $player = $this->getServer()->getPlayerExact($target);
        if($player === null){
            $sender->sendMessage(TextFormat::RED . "Player not found: " . $target);
            return;
        }
        $left = $player->getInventory()->addItem($item);
        foreach($left as $drop){
            $player->getWorld()->dropItem($player->getPosition(), $drop);
        }
        $sender->sendMessage(TextFormat::GREEN . "Keys given to " . $player->getName());
    }

    private function crateExists(string $id) : bool{
        $crates = $this->getConfig()->get("crates", []);
        return is_array($crates) && isset($crates[strtolower($id)]);
    }

    private function loadPlacements() : void{
        $this->placements = [];
        $data = $this->getConfig()->get("placements", []);
        if(!is_array($data)){
            return;
        }
        foreach($data as $entry){
            if(!is_array($entry)){
                continue;
            }
            $id = (string) ($entry["id"] ?? "");
            $world = (string) ($entry["world"] ?? "");
            $x = (int) ($entry["x"] ?? 0);
            $y = (int) ($entry["y"] ?? 0);
            $z = (int) ($entry["z"] ?? 0);
            if($id === "" || $world === ""){
                continue;
            }
            $key = $this->posKey($world, $x, $y, $z);
            $this->placements[$key] = [
                "id" => strtolower($id),
                "world" => $world,
                "pos" => new Vector3($x + 0.5, $y + 1.2, $z + 0.5)
            ];
        }
    }

    private function savePlacement(string $id, string $world, int $x, int $y, int $z) : void{
        $config = $this->getConfig();
        $data = $config->get("placements", []);
        if(!is_array($data)){
            $data = [];
        }
        $filtered = [];
        foreach($data as $entry){
            if(!is_array($entry)){
                continue;
            }
            if(((string) ($entry["world"] ?? "")) === $world
                && ((int) ($entry["x"] ?? 0)) === $x
                && ((int) ($entry["y"] ?? 0)) === $y
                && ((int) ($entry["z"] ?? 0)) === $z){
                continue;
            }
            $filtered[] = $entry;
        }
        $data = $filtered;
        $data[] = [
            "id" => $id,
            "world" => $world,
            "x" => $x,
            "y" => $y,
            "z" => $z
        ];
        $config->set("placements", $data);
        $config->save();

        $key = $this->posKey($world, $x, $y, $z);
        $this->placements[$key] = [
            "id" => $id,
            "world" => $world,
            "pos" => new Vector3($x + 0.5, $y + 1.2, $z + 0.5)
        ];
        $this->spawnHologram($id, $world, $this->placements[$key]["pos"]);
    }

    private function removePlacement(string $world, int $x, int $y, int $z) : bool{
        $key = $this->posKey($world, $x, $y, $z);
        if(!isset($this->placements[$key])){
            return false;
        }

        $config = $this->getConfig();
        $data = $config->get("placements", []);
        if(!is_array($data)){
            $data = [];
        }
        $filtered = [];
        foreach($data as $entry){
            if(!is_array($entry)){
                continue;
            }
            if(((string) ($entry["world"] ?? "")) === $world
                && ((int) ($entry["x"] ?? 0)) === $x
                && ((int) ($entry["y"] ?? 0)) === $y
                && ((int) ($entry["z"] ?? 0)) === $z){
                continue;
            }
            $filtered[] = $entry;
        }
        $config->set("placements", $filtered);
        $config->save();

        unset($this->placements[$key]);
        return true;
    }

    private function deleteCrate(string $id) : bool{
        $config = $this->getConfig();
        $crates = $config->get("crates", []);
        if(!is_array($crates) || !isset($crates[$id])){
            return false;
        }
        unset($crates[$id]);
        $config->set("crates", $crates);

        $data = $config->get("placements", []);
        if(!is_array($data)){
            $data = [];
        }
        $filtered = [];
        foreach($data as $entry){
            if(!is_array($entry)){
                continue;
            }
            if(strtolower((string) ($entry["id"] ?? "")) === $id){
                continue;
            }
            $filtered[] = $entry;
        }
        $config->set("placements", $filtered);
        $config->save();

        $this->loadPlacements();
        return true;
    }
    private function spawnHologram(string $id, string $worldName, Vector3 $pos) : void{
        $world = $this->getServer()->getWorldManager()->getWorldByName($worldName);
        if($world === null){
            return;
        }
        $crates = $this->getConfig()->get("crates", []);
        $display = $id;
        $lines = [];
        if(is_array($crates) && isset($crates[$id]) && is_array($crates[$id])){
            $display = (string) ($crates[$id]["display"] ?? $display);
            $lines = (array) ($crates[$id]["lore"] ?? []);
        }
        $title = TextFormat::colorize($display);
        $text = "";
        if(count($lines) > 0){
            $colored = [];
            foreach($lines as $line){
                $colored[] = TextFormat::colorize((string) $line);
            }
            $text = implode("\n", $colored);
        }

        $particle = new FloatingTextParticle("");
        $particle->setTitle($title);
        $particle->setText($text);
        $world->addParticle($pos->add(0.0, 0.6, 0.0), $particle);
    }


    private function posKey(string $world, int $x, int $y, int $z) : string{
        return strtolower($world) . ":$x:$y:$z";
    }

    public function isPlacementAt(string $world, int $x, int $y, int $z) : bool{
        $key = $this->posKey($world, $x, $y, $z);
        return isset($this->placements[$key]);
    }

    public function onBlockBreak(BlockBreakEvent $event) : void{
        $block = $event->getBlock();
        $world = $block->getPosition()->getWorld()->getFolderName();
        $x = $block->getPosition()->getFloorX();
        $y = $block->getPosition()->getFloorY();
        $z = $block->getPosition()->getFloorZ();
        $key = $this->posKey($world, $x, $y, $z);
        if(isset($this->placements[$key])){
            $event->cancel();
            $event->getPlayer()->sendMessage(TextFormat::RED . "You cannot break this crate.");
        }
    }

    public function onBlockExplode(BlockExplodeEvent $event) : void{
        $blockList = $event->getBlockList();
        $event->setBlockList($this->filterProtectedBlocks($blockList));
    }

    public function onEntityExplode(EntityExplodeEvent $event) : void{
        $blockList = $event->getBlockList();
        $event->setBlockList($this->filterProtectedBlocks($blockList));
    }


    /**
     * @param \pocketmine\block\Block[] $blocks
     * @return \pocketmine\block\Block[]
     */
    private function filterProtectedBlocks(array $blocks) : array{
        $filtered = [];
        foreach($blocks as $block){
            $key = $this->posKey(
                $block->getPosition()->getWorld()->getFolderName(),
                $block->getPosition()->getFloorX(),
                $block->getPosition()->getFloorY(),
                $block->getPosition()->getFloorZ()
            );
            if(isset($this->placements[$key])){
                continue;
            }
            $filtered[] = $block;
        }
        return $filtered;
    }

    public function onPlayerInteract(PlayerInteractEvent $event) : void{
        $player = $event->getPlayer();
        $block = $event->getBlock();
        $world = $block->getPosition()->getWorld()->getFolderName();
        $x = $block->getPosition()->getFloorX();
        $y = $block->getPosition()->getFloorY();
        $z = $block->getPosition()->getFloorZ();

        $name = $player->getName();
        if(isset($this->pendingRemove[$name])){
            unset($this->pendingRemove[$name]);
            if($this->removePlacement($world, $x, $y, $z)){
                $player->sendMessage(TextFormat::GREEN . "Crate removed.");
                $player->sendMessage(TextFormat::GRAY . "The hologram clears when the chunk reloads.");
            }else{
                $player->sendMessage(TextFormat::RED . "No crate at that block.");
            }
            $event->cancel();
            return;
        }
        if(isset($this->pendingPlace[$name])){
            $id = $this->pendingPlace[$name];
            unset($this->pendingPlace[$name]);
            $this->savePlacement($id, $world, $x, $y, $z);
            $player->sendMessage(TextFormat::GREEN . "Crate '$id' placed.");
            $event->cancel();
            return;
        }

        $key = $this->posKey($world, $x, $y, $z);
        if(!isset($this->placements[$key])){
            return;
        }

        $event->cancel();
        $id = $this->placements[$key]["id"];
        if($event->getAction() === PlayerInteractEvent::LEFT_CLICK_BLOCK){
            $this->openItemsView($player, $id);
            return;
        }
        if($event->getAction() === PlayerInteractEvent::RIGHT_CLICK_BLOCK){
            if(!$this->consumeKey($player, $id)){
                $player->sendMessage(TextFormat::RED . "You need a key for this crate.");
                return;
            }
            $this->openCrate($player, $id);
        }
    }

    private function openItemsView(Player $player, string $id) : void{
        $crates = $this->getConfig()->get("crates", []);
        if(!is_array($crates) || !isset($crates[$id])){
            return;
        }
        $rewards = (array) ($crates[$id]["rewards"] ?? []);

        $form = ActionFormData::create()->title("Items - $id");

        if(count($rewards) === 0){
            $form->button("No items");
            $form->show($player);
            return;
        }

        foreach($rewards as $entry){
            if(!is_array($entry) || !isset($entry["nbt"])){
                continue;
            }
            $item = ItemCodec::decode((string) $entry["nbt"]);
            if($item === null){
                continue;
            }
            $weight = (int) ($entry["weight"] ?? 1);
            $form->button($item->getName() . "\nWeight: $weight");
        }

        $form->show($player);
    }

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        foreach($this->placements as $entry){
            $this->spawnHologram($entry["id"], $entry["world"], $entry["pos"]);
        }
    }

    private function consumeKey(Player $player, string $id) : bool{
        $inventory = $player->getInventory();
        foreach($inventory->getContents() as $slot => $item){
            $tag = $item->getNamedTag();
            if($tag->getTag("crate_key") !== null && $tag->getString("crate_key", "") === $id){
                $item->setCount($item->getCount() - 1);
                if($item->getCount() <= 0){
                    $inventory->clear($slot);
                }else{
                    $inventory->setItem($slot, $item);
                }
                return true;
            }
        }
        return false;
    }

    /** @priority HIGHEST */
    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        if(isset($this->sessions[$player->getName()])){
            $this->sessions[$player->getName()]->cancel();
            unset($this->sessions[$player->getName()]);
        }
        if(isset($this->editors[$player->getName()])){
            $entry = $this->editors[$player->getName()];
            $this->saveEdit($entry["type"], $entry["menu"]->getInventory());
            unset($this->editors[$player->getName()]);
        }
        unset($this->pendingPlace[$player->getName()]);
        unset($this->pendingRemove[$player->getName()]);
    }
}





