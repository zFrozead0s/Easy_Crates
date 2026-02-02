<?php

declare(strict_types=1);

namespace cykp\crates;

use pocketmine\event\Listener;
use pocketmine\event\block\BlockPistonExtendEvent;
use pocketmine\event\block\BlockPistonRetractEvent;

final class PistonListener implements Listener{

    public function __construct(private Main $plugin){}

    public function onPistonExtend(BlockPistonExtendEvent $event) : void{
        foreach($event->getBlocks() as $block){
            if($this->plugin->isPlacementAt(
                $block->getPosition()->getWorld()->getFolderName(),
                $block->getPosition()->getFloorX(),
                $block->getPosition()->getFloorY(),
                $block->getPosition()->getFloorZ()
            )){
                $event->cancel();
                return;
            }
        }
    }

    public function onPistonRetract(BlockPistonRetractEvent $event) : void{
        foreach($event->getBlocks() as $block){
            if($this->plugin->isPlacementAt(
                $block->getPosition()->getWorld()->getFolderName(),
                $block->getPosition()->getFloorX(),
                $block->getPosition()->getFloorY(),
                $block->getPosition()->getFloorZ()
            )){
                $event->cancel();
                return;
            }
        }
    }
}
