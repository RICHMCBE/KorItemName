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

use kim\present\libasynform\CustomForm;
use kim\present\libasynform\SimpleForm;
use pocketmine\block\BaseCoral;
use pocketmine\block\CoralBlock;
use pocketmine\block\Dirt;
use pocketmine\block\MobHead;
use pocketmine\block\Sponge;
use pocketmine\block\utils\ColoredTrait;
use pocketmine\block\utils\DirtType;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\item\Medicine;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use SOFe\AwaitGenerator\Await;

use function array_merge;
use function class_uses;
use function implode;
use function in_array;
use function is_array;
use function method_exists;
use function str_replace;
use function strpos;
use function strtolower;
use function strtr;
use function substr;
use function yaml_emit_file;
use function yaml_parse_file;

final class KorItemName extends PluginBase{

    private static self $instance;

    /**
     * Map of translations that mapped based on translation key
     *  It load from plugin resource file
     *
     * @var string[] [ $key => $koreanName ]
     * @phpstan-var array<string, string>
     */
    private static array $translations = [];

    /**
     * Caches of translated name that mapped based on state id
     *
     * @var string[] [ $stateId => $koreanName ]
     * @phpstan-var array<int, string>
     */
    private static array $stateIdToName = [];

    /**
     * Caches of translation key that mapped based on network id
     *
     * @var string[] [ $netId => $key ]
     */
    private static array $netIdToKey = [];

    /**
     * List of items that failed to translate for debugging
     *
     * @var string[] [ $key => $info ]
     */
    private static array $failure = [];

    private static bool $updated = false;

    /**
     * Map of fallback translations that mapped based on item key
     * It load from plugin resource file
     *
     * @var string[] [ $key => $koreanName ]
     * @phpstan-var array<string, string>
     */
    private readonly array $fallback;

    protected function onLoad() : void{
        self::$instance = $this;

        $this->saveResource("translations.yml");
        $this->fallback = yaml_parse_file($this->getResourcePath("translations.yml"));
        self::$translations = $this->fallback;
        foreach(yaml_parse_file($this->getDataFolder() . "translations.yml") as $key => $value){
            self::$translations[strtolower($key)] = $value;
        }

        foreach(TypeConverter::getInstance()->getItemTypeDictionary()->getEntries() as $entry){
            if(isset(self::$translations[$key = $entry->getStringId()])
                || isset(self::$translations[$key = substr($key, strpos($key, ":") + 1)])
                || isset(self::$translations[$key = strtr($key, [
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
                || isset(self::$translations[$key .= "_block"])
                || isset(self::$translations[$key = substr($key, 0, -7)])
            ){
                self::$netIdToKey[$entry->getNumericId()] = $key;
            }
        }
    }

    protected function onDisable() : void{
        if(self::$updated){
            yaml_emit_file($this->getDataFolder() . "translations.yml", self::$translations, YAML_UTF8_ENCODING);
        }
    }

    public function onCommand(CommandSender $sender, Command $command, string $label, array $args) : bool{
        if(!empty($args[0]) && !empty($args[1])){
            $key = self::canonizeKey($args[0]);
            self::$translations[$key] = $args[1];
            self::$stateIdToName = [];
            self::$updated = true;
            $sender->sendMessage("§l§6 • §r입력하신 한글 이름이 §7[$key §f=> §r§7$args[1]]§f로 등록되었습니다");
            return true;
        }

        if(!($sender instanceof Player)){
            return false;
        }

        Await::f2c(function() use ($sender) : \Generator{
            $form = SimpleForm::create("한글 아이템 이름 등록기");
            $form->addButton("한글 이름 등록하기");
            $form->addButton("기본 한글 이름 복구");
            $form->addButton("번역 실패 목록 보기");
            $response = yield from $form->send($sender);

            if($response === 0){
                yield from $this->registerTranslation($sender);
            }elseif($response === 1){
                self::$translations = array_merge(self::$translations, $this->fallback);
                self::$stateIdToName = [];
                self::$updated = true;
                $sender->sendMessage("§l§6 • §r기본 한글 이름으로 복구되었습니다");
            }elseif($response === 2){
                if(empty(self::$failure)){
                    $sender->sendMessage("§l§6 • §r번역에 실패한 아이템이 없습니다");
                    return;
                }

                $form = SimpleForm::create("번역 실패 목록");
                $form->setContent("번역에 실패한 아이템 목록입니다. 누르면 새로 등록할 수 있습니다.\n" . implode("\n", self::$failure));

                $failureKeys = [];
                foreach(self::$failure as $key => $info){
                    $failureKeys[] = $key;
                    $form->addButton($info);
                }
                $response = yield from $form->send($sender);
                if($response === null || !isset($failureKeys[$response])){
                    return;
                }

                yield from $this->registerTranslation($sender, $failureKeys[$response]);
            }
        });
        return true;
    }

    private function registerTranslation(Player $player, string $defaultKey = "") : \Generator{
        $form = CustomForm::create("한글 이름 등록하기");
        $form->addInput("아이템 구분자", "example_item_name", $defaultKey);
        $form->addInput("한글 이름", "예시 아이템 이름", "");
        $response = yield from $form->send($player);

        if(!is_array($response) || !isset($response[0], $response[1])){
            $player->sendMessage("§l§6 • §r한글 이름 등록을 취소하였습니다");
            return;
        }

        [$translationKey, $koreanName] = $response;
        self::$translations[$translationKey] = $koreanName;
        self::$stateIdToName = [];
        self::$updated = true;
        $player->sendMessage("§l§6 • §r입력하신 한글 이름이 §7$translationKey §f=> §r§7{$translationKey}§f로 등록되었습니다");
    }

    private static function getKeyFrom(Item $item) : string{
        $key = self::canonizeKey($item->getVanillaName());

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
            return self::canonizeKey($block->getMobHeadType()->getDisplayName());
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

    private static function canonizeKey(string $key) : string{
        return strtolower(str_replace(" ", "_", $key));
    }

    public static function translate(Item $item) : string{
        $stateId = $item->getStateId();
        if(isset(self::$stateIdToName[$stateId])){
            return self::$stateIdToName[$stateId];
        }

        $key = self::getKeyFrom($item);
        if(isset(self::$translations[$key])){
            return self::$stateIdToName[$stateId] = self::$translations[$key];
        }

        $netId = $item->isNull() ? 0 : TypeConverter::getInstance()->getItemTranslator()->toNetworkId($item)[0];
        if(isset(self::$netIdToKey[$netId])){
            return self::$stateIdToName[$stateId] = self::$translations[self::$netIdToKey[$netId]];
        }

        try{
            $stringId = TypeConverter::getInstance()->getItemTypeDictionary()->fromIntId($netId);
        }catch(\InvalidArgumentException){
            $stringId = "unknown_$netId";
        }
        $info = "{$item->getVanillaName()} : $stringId ($key)";
        self::$instance->getLogger()->error("Failed to translate item : $info");
        self::$failure[$netId] = $info;

        return $item->getName();
    }
}
