<?php

/**
 *  ____                           _   _  ___
 * |  _ \ _ __ ___  ___  ___ _ __ | |_| |/ (_)_ __ ___
 * | |_) | '__/ _ \/ __|/ _ \ '_ \| __| ' /| | '_ ` _ \
 * |  __/| | |  __/\__ \  __/ | | | |_| . \| | | | | | |
 * |_|   |_|  \___||___/\___|_| |_|\__|_|\_\_|_| |_| |_|
 *
 * @author       PresentKim (debe3721@gmail.com)
 * @link         https://github.com/PresentKim
 *
 *   (\ /)
 *  ( . .) ♥
 *  c(")(")
 *
 * @noinspection PhpUnused
 */

declare(strict_types=1);

namespace kim\present\koritemname;

use pocketmine\block\BaseCoral;
use pocketmine\block\CoralBlock;
use pocketmine\block\Dirt;
use pocketmine\block\MobHead;
use pocketmine\block\Sponge;
use pocketmine\block\utils\ColoredTrait;
use pocketmine\block\utils\DirtType;
use pocketmine\item\Item;
use pocketmine\item\Medicine;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\plugin\PluginBase;

use function class_uses;
use function in_array;
use function method_exists;
use function str_replace;
use function strpos;
use function strtolower;
use function strtr;
use function substr;

final class KorItemName extends PluginBase{

    private static self $instance;

    /**
     * Map of translates that mapped based on item key
     *
     * @var string[] [ $key => $koreanName ]
     * @phpstan-var array<string, string>
     */
    private static array $translates = [];

    /**
     * Map of cache that mapped based on item state id
     *
     * @var string[] [ $stateId => $koreanName ]
     * @phpstan-var array<int, string>
     */
    private static array $cache = [];

    /**
     * Map of fallbacks that mapped based on network id
     *
     * @var string[] [ $netId => $koreanName ]
     */
    private static array $fallback = [];

    protected function onLoad() : void{
        self::$instance = $this;

        $this->saveResource("translates.yml");
        foreach(yaml_parse_file($this->getDataFolder() . "translates.yml") as $key => $value){
            self::$translates[strtolower($key)] = $value;
        }

        foreach(TypeConverter::getInstance()->getItemTypeDictionary()->getEntries() as $entry){
            if(isset(self::$translates[$key = $entry->getStringId()])
                || isset(self::$translates[$key = substr($key, strpos($key, ":") + 1)])
                || isset(self::$translates[$key = strtr($key, [
                        "item." => "",
                        "lit_" => "",
                        "unpowered_" => "",
                        "powered_" => "",
                        "wall_" => "",
                        "standing_" => "",
                        "glazed_" => "",
                        "normal_" => "",
                        "_inverted" => "",
                        "double_slab" => "slab",
                        "double_stone_slab" => "stone_slab",
                    ])])
                || isset(self::$translates[$key .= "_block"])
                || isset(self::$translates[$key = substr($key, 0, -7)])
            ){
                self::$fallback[$entry->getNumericId()] = self::$translates[$key];
            }
        }
    }

    private static function getKeyFrom(Item $item) : string{
        $key = strtolower(str_replace(" ", "_", $item->getVanillaName()));

        if($item instanceof TieredTool){
            return match ($tier = $item->getTier()) {
                ToolTier::WOOD, ToolTier::GOLD => strtolower($tier->name) . "en_$key",
                default                        => strtolower($tier->name) . "_$key"
            };
        }

        if($item instanceof Medicine){
            return $key . "_" . strtolower($item->getType()->name);
        }

        $block = $item->getBlock();
        if($block instanceof BaseCoral || $block instanceof CoralBlock){
            return ($block->isDead() ? "dead_" : "") . strtolower($block->getCoralType()->name) . "_$key";
        }

        if($block instanceof Sponge){
            return strtolower($block->isWet() ? "wet_" : "") . $key;
        }

        if($block instanceof MobHead){
            return strtolower(str_replace(" ", "_", $block->getMobHeadType()->getDisplayName()));
        }

        if($block instanceof Dirt){
            return match ($block->getDirtType()) {
                DirtType::NORMAL => "dirt",
                DirtType::COARSE => "coarse_dirt",
                DirtType::ROOTED => "dirt_with_roots"
            };
        }

        $classUses = class_uses($block);
        if(method_exists($block, "getColor") || in_array(ColoredTrait::class, $classUses, true)){
            /** @var ColoredTrait $block */
            $color = $block->getColor();
            return strtolower($color->name) . "_$key";
        }

        return $key;
    }

    public static function translate(Item $item) : string{
        $stateId = $item->getStateId();
        if(isset(self::$cache[$stateId])){
            return self::$cache[$stateId];
        }

        $key = self::getKeyFrom($item);
        if(isset(self::$translates[$key])){
            return self::$cache[$stateId] = self::$translates[$key];
        }

        $netId = $item->isNull() ? 0 : TypeConverter::getInstance()->getItemTranslator()->toNetworkId($item)[0];
        if(isset(self::$fallback[$netId])){
            return self::$cache[$stateId] = self::$fallback[$netId];
        }

        try{
            $stringId = TypeConverter::getInstance()->getItemTypeDictionary()->fromIntId($netId);
        }catch(\InvalidArgumentException){
            $stringId = "unknown_$netId";
        }
        $info = "{$item->getVanillaName()} : $stringId ($key)";
        self::$instance->getLogger()->error("Failed to translate item : $info");

        return $item->getName();
    }
}
