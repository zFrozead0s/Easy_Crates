<?php

declare(strict_types=1);

namespace cykp\crates;

use muqsit\invmenu\InvMenu;
use muqsit\invmenu\type\InvMenuTypeIds;
use pocketmine\item\VanillaItems;
use pocketmine\player\Player;
use pocketmine\scheduler\ClosureTask;
use pocketmine\utils\TextFormat;

final class RouletteSession{

    private const CENTER_SLOT = 13;
    private const ROULETTE_SLOTS = [9, 10, 11, 12, 13, 14, 15, 16, 17];
    private const CENTER_INDEX = 4;

    private Main $plugin;
    private Player $player;
    private string $type;
    private InvMenu $menu;
    private bool $running = false;
    private bool $finished = false;
    private ?\pocketmine\scheduler\TaskHandler $taskHandler = null;
    private ?\pocketmine\item\Item $finalReward = null;
    /** @var \pocketmine\item\Item[] */
    private array $lineItems = [];

    /** @var array<int, array{item: \pocketmine\item\Item, weight: int}> */
    private array $rewards = [];

    public function __construct(Main $plugin, Player $player, string $type, array $config){
        $this->plugin = $plugin;
        $this->player = $player;
        $this->type = $type;

        $this->loadRewards($config);
        $this->menu = InvMenu::create(InvMenuTypeIds::TYPE_CHEST);
        $title = isset($config["display"]) ? TextFormat::colorize((string) $config["display"]) : "Crate";
        $this->menu->setName($title);
        $this->menu->setListener(InvMenu::readonly());

        $this->menu->setInventoryCloseListener(function(Player $player, \pocketmine\inventory\Inventory $inventory) : void{
            if($this->running && !$this->finished){
                $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($player) : void{
                    if($player->isOnline()){
                        $this->menu->send($player);
                    }
                }), 1);
            }
        });
    }

    public function start() : void{
        if(count($this->rewards) === 0){
            $this->player->sendMessage(TextFormat::RED . "This crate has no valid rewards.");
            $this->plugin->clearSession($this->player);
            return;
        }

        $this->running = true;
        $inv = $this->menu->getInventory();
        for($i = 0; $i < $inv->getSize(); $i++){
            $inv->clear($i);
        }

        $this->lineItems = [];
        foreach(self::ROULETTE_SLOTS as $index => $slot){
            $item = $this->pickWeightedReward();
            $this->lineItems[$index] = $item;
            $inv->setItem($slot, $item);
        }
        $this->finalReward = $this->lineItems[self::CENTER_INDEX] ?? null;

        $this->menu->send($this->player);

        $totalSteps = 40;
        $this->scheduleStep(0, $totalSteps);
    }

    public function cancel() : void{
        $this->running = false;
        $this->finished = true;
        if($this->taskHandler !== null){
            $this->taskHandler->cancel();
        }
        $this->plugin->clearSession($this->player);
    }

    private function finish() : void{
        if($this->finished){
            return;
        }

        if($this->taskHandler !== null){
            $this->taskHandler->cancel();
        }
        $this->finished = true;
        $reward = $this->finalReward !== null ? clone $this->finalReward : $this->pickWeightedReward();
        $inv = $this->menu->getInventory();
        foreach(self::ROULETTE_SLOTS as $slot){
            $inv->clear($slot);
        }
        $inv->setItem(self::CENTER_SLOT, $reward);

        $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($reward) : void{
            if($this->player->isOnline()){
                $left = $this->player->getInventory()->addItem($reward);
                foreach($left as $drop){
                    $this->player->getWorld()->dropItem($this->player->getPosition(), $drop);
                }
                $this->player->sendMessage(TextFormat::GREEN . "You won: " . $reward->getName());
                $this->player->removeCurrentWindow();
            }
            $this->running = false;
            $this->plugin->clearSession($this->player);
        }), 20);
    }

    private function scheduleStep(int $step, int $totalSteps) : void{
        $baseDelay = 2;
        $maxDelay = 10;
        $t = $totalSteps > 0 ? $step / $totalSteps : 1.0;
        $delay = (int) round($baseDelay + ($maxDelay - $baseDelay) * ($t * $t));
        $delay = max(1, $delay);

        $this->taskHandler = $this->plugin->getScheduler()->scheduleDelayedTask(new ClosureTask(function() use ($step, $totalSteps) : void{
            if(!$this->player->isOnline()){
                $this->cancel();
                return;
            }
            if($step >= $totalSteps){
                $this->finish();
                return;
            }

            $inv = $this->menu->getInventory();
            array_shift($this->lineItems);
            $this->lineItems[] = $this->pickWeightedReward();
            foreach(self::ROULETTE_SLOTS as $index => $slot){
                $inv->setItem($slot, $this->lineItems[$index]);
            }
            $this->finalReward = $this->lineItems[self::CENTER_INDEX] ?? null;

            $this->scheduleStep($step + 1, $totalSteps);
        }), $delay);
    }

    private function loadRewards(array $config) : void{
        $rewards = $config["rewards"] ?? [];
        if(!is_array($rewards)){
            return;
        }

        foreach($rewards as $entry){
            if(!is_array($entry)){
                continue;
            }
            $weight = max(1, (int) ($entry["weight"] ?? 1));

            if(isset($entry["nbt"])){
                $item = ItemCodec::decode((string) $entry["nbt"]);
                if($item === null){
                    $this->plugin->getLogger()->warning("Invalid NBT in crate {$this->type}.");
                    continue;
                }
                $this->rewards[] = ["item" => $item, "weight" => $weight];
                continue;
            }

            if(isset($entry["item"])){
                $itemId = (string) $entry["item"];
                $item = \pocketmine\item\StringToItemParser::getInstance()->parse($itemId);
                if($item === null){
                    $this->plugin->getLogger()->warning("Invalid item in crate {$this->type}: {$itemId}");
                    continue;
                }
                $amount = (int) ($entry["amount"] ?? 1);
                $item->setCount(max(1, $amount));

                if(isset($entry["name"])){
                    $item->setCustomName(TextFormat::colorize((string) $entry["name"]));
                }

                if(isset($entry["lore"]) && is_array($entry["lore"])){
                    $lore = [];
                    foreach($entry["lore"] as $line){
                        $lore[] = TextFormat::colorize((string) $line);
                    }
                    $item->setLore($lore);
                }

                $this->rewards[] = ["item" => $item, "weight" => $weight];
            }
        }
    }

    private function pickWeightedReward() : \pocketmine\item\Item{
        $total = 0;
        foreach($this->rewards as $reward){
            $total += $reward["weight"];
        }

        $roll = random_int(1, max(1, $total));
        $running = 0;
        foreach($this->rewards as $reward){
            $running += $reward["weight"];
            if($roll <= $running){
                return clone $reward["item"];
            }
        }

        return clone $this->rewards[0]["item"];
    }
}

