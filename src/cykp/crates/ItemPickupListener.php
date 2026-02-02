<?php

declare(strict_types=1);

namespace cykp\crates;

use pocketmine\event\Listener;
use pocketmine\event\entity\EntityItemPickupEvent;
use pocketmine\player\Player;

final class ItemPickupListener implements Listener{

    public function onItemPickup(EntityItemPickupEvent $event) : void{
        $entity = $event->getEntity();
        if(!$entity instanceof Player){
            return;
        }
        $itemEntity = $event->getItem();
        $item = $itemEntity->getItem();
        $tag = $item->getNamedTag();
        if($tag->getTag("crate_visual") !== null){
            $event->cancel();
        }
    }
}
