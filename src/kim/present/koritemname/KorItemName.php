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
use pocketmine\block\BlockTypeIds;
use pocketmine\block\Copper;
use pocketmine\block\CopperSlab;
use pocketmine\block\CopperStairs;
use pocketmine\block\CoralBlock;
use pocketmine\block\Dirt;
use pocketmine\block\Froglight;
use pocketmine\block\MobHead;
use pocketmine\block\Sponge;
use pocketmine\block\utils\ColoredTrait;
use pocketmine\block\utils\CopperOxidation;
use pocketmine\block\utils\DirtType;
use pocketmine\block\utils\FroglightType;
use pocketmine\block\Wood;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\item\Item;
use pocketmine\item\Medicine;
use pocketmine\item\TieredTool;
use pocketmine\item\ToolTier;
use pocketmine\network\mcpe\convert\TypeConverter;
use pocketmine\player\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\scheduler\ClosureTask;
use SOFe\AwaitGenerator\Await;

use function array_change_key_case;
use function array_merge;
use function class_uses;
use function file_get_contents;
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
use function yaml_parse;
use function yaml_parse_file;

use const YAML_UTF8_ENCODING;

final class KorItemName extends PluginBase{

    private const STRING_ID_REPLACEMENTS = [
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
    ];

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
        $this->fallback = yaml_parse(file_get_contents($this->getResourcePath("translations.yml")));

        self::$translations = array_change_key_case(array_merge(
            $this->fallback,
            yaml_parse_file($this->getDataFolder() . "translations.yml")
        ));

        self::loadItemTypeDictionary();
        $this->getScheduler()->scheduleDelayedTask(new ClosureTask(static function() : void{
            // Scheduled for 0-tick delay, runs as soon as the server starts
            // for run after another plugin is loaded
            self::loadItemTypeDictionary();
        }), 0);
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

    private static function getKeyFromItem(Item $item) : string{
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

        if($block instanceof Froglight){
            return match ($block->getFroglightType()){
                FroglightType::OCHRE => "ochre_froglight",
                FroglightType::PEARLESCENT => "pearlescent_froglight",
                FroglightType::VERDANT => "verdant_froglight",
                default => 'froglight'
            };
        }

        if($block instanceof Wood){
            if($block->isStripped()){
                $key = 'stripped_' . $key;
            }
            return $key;
        }

        if($block instanceof Copper || $block instanceof CopperSlab || $block instanceof CopperStairs){
            if($block->getStateId() === BlockTypeIds::CUT_COPPER){
                $key = 'cut_copper';
            }

            if($block->isWaxed()){
                $prefix = 'waxed_';
            }else{
                $prefix = '';
            }

            $prefix .= match ($block->getOxidation()){
                CopperOxidation::EXPOSED => 'exposed_',
                CopperOxidation::WEATHERED => 'weathered_',
                CopperOxidation::OXIDIZED => 'oxidized_',
                default => ''
            };

            return $prefix . $key;
        }

        $classUses = class_uses($block);
        if(method_exists($block, "getColor") || in_array(ColoredTrait::class, $classUses, true)){
            /** @var ColoredTrait $block */
            $color = $block->getColor();
            return strtolower($color->name) . "_$key";
        }

        return $key;
    }

    private static function getKeyByStringId(string $stringId) : ?string{
        if(
            // Try full string id
            isset(self::$translations[$key = $stringId])

            // Try remove namespace
            || isset(self::$translations[$key = substr($key, strpos($key, ":") + 1)])

            // Try replace useless parts
            || isset(self::$translations[$key = strtr($key, self::STRING_ID_REPLACEMENTS)])

            // Try add suffix "_block"
            || isset(self::$translations[$key .= "_block"])

            // Try remove last 1 character (The reason why it is 7 instead of 1 is to remove "_block")
            || isset(self::$translations[$key = substr($key, 0, -7)])
        ){
            return $key;
        }

        return null;
    }

    private static function canonizeKey(string $key) : string{
        return strtolower(str_replace(" ", "_", $key));
    }

    private static function loadItemTypeDictionary() : void{
        foreach(TypeConverter::getInstance()->getItemTypeDictionary()->getEntries() as $entry){
            $netId = $entry->getNumericId();
            if(isset(self::$netIdToKey[$netId])){
                continue;
            }

            $key = self::getKeyByStringId($entry->getStringId());
            if($key === null){
                continue;
            }

            self::$netIdToKey[$entry->getNumericId()] = $key;
        }
    }

    /**
     * Translate the item to Korean name
     *
     * @param Item $item The item to translate
     * @param bool $must Whether to force translation even if the item has a custom name
     *
     * @return string
     */
    public static function translate(Item $item, bool $must = false) : string{
        if(!$must && $item->hasCustomName()){
            return $item->getCustomName();
        }

        // Try to get from cache
        $stateId = $item->getStateId();
        if(isset(self::$stateIdToName[$stateId])){
            return self::$stateIdToName[$stateId];
        }

        // Try to get by item key
        $key = self::getKeyFromItem($item);
        if(isset(self::$translations[$key])){
            return self::$stateIdToName[$stateId] = self::$translations[$key];
        }

        // Try to get by network id
        $netId = $item->isNull() ? 0 : TypeConverter::getInstance()->getItemTranslator()->toNetworkId($item)[0];
        if(isset(self::$netIdToKey[$netId])){
            return self::$stateIdToName[$stateId] = self::$translations[self::$netIdToKey[$netId]];
        }

        try{
            // Try to get by string id
            $stringId = TypeConverter::getInstance()->getItemTypeDictionary()->fromIntId($netId);
            $keyByStringId = self::getKeyByStringId($stringId);
            if($keyByStringId !== null){
                return self::$stateIdToName[$stateId] = self::$translations[$keyByStringId];
            }
        }catch(\InvalidArgumentException){
            $stringId = "unknown_$netId";
        }

        // Log failure
        $info = "{$item->getVanillaName()} : $stringId ($key)";
        self::$instance->getLogger()->error("Failed to translate item : $info");
        self::$failure[$netId] = $info;

        // Return default name
        return $item->getName();
    }
}
