<?php

declare(strict_types=1);

namespace cykp\crates;

use pocketmine\item\Item;
use pocketmine\nbt\BigEndianNbtSerializer;
use pocketmine\nbt\TreeRoot;

final class ItemCodec{

    public static function encode(Item $item) : string{
        $serializer = new BigEndianNbtSerializer();
        $root = new TreeRoot($item->nbtSerialize());
        return base64_encode($serializer->write($root));
    }

    public static function decode(string $data) : ?Item{
        $binary = base64_decode($data, true);
        if($binary === false){
            return null;
        }

        try{
            $serializer = new BigEndianNbtSerializer();
            $root = $serializer->read($binary);
            return Item::nbtDeserialize($root->mustGetCompoundTag());
        }catch(\Throwable $e){
            return null;
        }
    }
}
