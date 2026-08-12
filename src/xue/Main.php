<?php

declare(strict_types=1);

namespace xue;

use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\entity\Effect;
use pocketmine\event\entity\EntityLevelChangeEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerDeathEvent;
use pocketmine\event\player\PlayerInteractEvent;
use pocketmine\event\player\PlayerJoinEvent;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\player\PlayerRespawnEvent;
use pocketmine\item\Item;
use pocketmine\Player;
use pocketmine\plugin\PluginBase;
use pocketmine\utils\Config;

class Main extends PluginBase implements Listener{

    private const MAX_HEALTH = 40;

    /** @var Config */
    private $dataConfig;

    /** @var array 扣减点数 */
    private $reduction = [];

    /** @var array 玩家经验等级持久化缓存 */
    private $expCache = [];

    /** @var string[] 禁用世界 */
    private $disabledWorlds = [];

    /** @var array 死亡世界记录 */
    private $deathWorld = [];

    /** @var array 待复活标记 */
    private $pendingRespawn = [];

    public function onEnable() : void{
        $this->saveDefaultConfig();
        $this->disabledWorlds = $this->getConfig()->get("disabled-worlds", []);

        $this->dataConfig = new Config($this->getDataFolder() . "data.yml", Config::YAML, [
            "reduction" => [],
            "exp" => []
        ]);
        $data = $this->dataConfig->getAll();
        $this->reduction = $data["reduction"] ?? [];
        $this->expCache  = $data["exp"] ?? [];

        $this->getServer()->getPluginManager()->registerEvents($this, $this);
    }

    /**
     * 保存所有数据到文件
     */
    private function saveAll() : void{
        $this->dataConfig->set("reduction", $this->reduction);
        $this->dataConfig->set("exp", $this->expCache);
        $this->dataConfig->save();
    }

    // ---------- 事件处理 ----------

    public function onJoin(PlayerJoinEvent $event) : void{
        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        // 恢复玩家经验（如果文件中有记录）
        if(isset($this->expCache[$name])){
            $player->setExpLevel($this->expCache[$name]);
        }

        // 如果在禁用世界，恢复满血
        if($this->isWorldDisabled($player->getLevel()->getFolderName())){
            $player->setMaxHealth(20);
        }
    }

    public function onQuit(PlayerQuitEvent $event) : void{
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        // 保存退出时的经验等级
        $this->expCache[$name] = $player->getExpLevel();
        $this->saveAll();
    }

    public function onDeath(PlayerDeathEvent $event) : void{
        $player = $event->getPlayer();
        $name = strtolower($player->getName());
        $this->deathWorld[$name] = $player->getLevel()->getFolderName();
        $this->pendingRespawn[$name] = true;
    }

    public function onRespawn(PlayerRespawnEvent $event) : void{
        $player = $event->getPlayer();
        $name = strtolower($player->getName());

        $deathWorld = $this->deathWorld[$name] ?? "";
        unset($this->deathWorld[$name]);
        unset($this->pendingRespawn[$name]);

        $currentWorld = $player->getLevel()->getFolderName();

        // 双重保护：死亡世界或当前世界是禁用世界 → 满血，不惩罚
        if(($deathWorld !== "" && $this->isWorldDisabled($deathWorld)) || $this->isWorldDisabled($currentWorld)){
            $player->setMaxHealth(20);
            $player->sendMessage("§a你在安全世界重生，生命值已恢复满。");
            return;
        }

        $currentMax = $player->getMaxHealth();

        // 生命耗尽封禁
        if($currentMax <= 2){
            $this->banPlayer($player);
            return;
        }

        // 扣减一颗心
        $newMax = $currentMax - 2;
        if($newMax < 2) $newMax = 2;
        $player->setMaxHealth($newMax);
        $this->reduction[$name] = ($this->reduction[$name] ?? 0) + 2;

        // 扣减一级经验
        $newExp = $player->getExpLevel() - 1;
        if($newExp < 0) $newExp = 0;
        $player->setExpLevel($newExp);
        $this->expCache[$name] = $newExp; // 更新缓存

        $this->saveAll();

        $player->sendMessage("§c你因死亡而虚弱了！失去了一颗心和一级经验...");
    }

    public function onLevelChange(EntityLevelChangeEvent $event) : void{
        $entity = $event->getEntity();
        if(!($entity instanceof Player)) return;
        $player = $entity;
        $name = strtolower($player->getName());

        if(isset($this->pendingRespawn[$name])) return;

        $origin = $event->getOrigin()->getFolderName();
        $target = $event->getTarget()->getFolderName();
        $originDisabled = $this->isWorldDisabled($origin);
        $targetDisabled = $this->isWorldDisabled($target);

        if($targetDisabled && !$originDisabled){
            $player->setMaxHealth(20);
        }elseif(!$targetDisabled && $originDisabled){
            $reduced = $this->reduction[$name] ?? 0;
            $newMax = 20 - $reduced;
            if($newMax < 2) $newMax = 2;
            $player->setMaxHealth($newMax);
            if($player->getHealth() > $newMax) $player->setHealth($newMax);
        }
    }

    public function onInteract(PlayerInteractEvent $event) : void{
        $player = $event->getPlayer();
        if($this->isWorldDisabled($player->getLevel()->getFolderName())) return;

        $action = $event->getAction();
        if($action !== PlayerInteractEvent::RIGHT_CLICK_AIR && $action !== PlayerInteractEvent::RIGHT_CLICK_BLOCK) return;

        $item = $event->getItem();
        if($item->getId() === 466 && $item->getDamage() === 2){
            $currentMax = $player->getMaxHealth();
            $newMax = $currentMax + 2;
            if($newMax > self::MAX_HEALTH) $newMax = self::MAX_HEALTH;
            $player->setMaxHealth($newMax);

            $name = strtolower($player->getName());
            if(isset($this->reduction[$name])){
                $this->reduction[$name] = max(0, $this->reduction[$name] - 2);
                $this->saveAll();
            }

            $item->setCount($item->getCount() - 1);
            $player->getInventory()->setItemInHand($item);
            $player->sendMessage("§a生命水晶使用成功！最大生命值增加了一颗心。");
            $event->setCancelled();
        }
    }

    // ---------- 命令 ----------

    public function onCommand(CommandSender $sender, Command $command, $label, array $args) : bool{
        if(!($sender instanceof Player)){
            $sender->sendMessage("§c该指令只能由玩家执行！");
            return true;
        }
        if($this->isWorldDisabled($sender->getLevel()->getFolderName())){
            $sender->sendMessage("§c该世界禁止使用此功能！");
            return true;
        }

        $name = strtolower($sender->getName());

        switch($command->getName()){
            case "生命水晶":
                if($sender->getExpLevel() < 5){
                    $sender->sendMessage("§c经验不足5级！");
                    return true;
                }
                $sender->setExpLevel($sender->getExpLevel() - 5);
                $this->expCache[$name] = $sender->getExpLevel();
                $this->saveAll();

                $crystal = Item::get(466, 2, 1)->setCustomName("§e生命水晶");
                if($sender->getInventory()->canAddItem($crystal)){
                    $sender->getInventory()->addItem($crystal);
                }else{
                    $sender->getLevel()->dropItem($sender->asVector3(), $crystal);
                    $sender->sendMessage("§e背包已满，物品掉在地上。");
                }
                $sender->sendMessage("§a兑换成功！");
                return true;

            case "血量提取":
                if($sender->getEffect(Effect::HEALTH_BOOST) !== null || $sender->getEffect(Effect::ABSORPTION) !== null){
                    $sender->sendMessage("§c有生命提升/伤害吸收效果，无法提取！");
                    return true;
                }
                $currentMax = $sender->getMaxHealth();
                if($currentMax <= 2){
                    $sender->sendMessage("§c血量已见底！");
                    return true;
                }
                $newMax = $currentMax - 2;
                if($newMax < 2) $newMax = 2;
                $sender->setMaxHealth($newMax);
                if($sender->getHealth() > $newMax) $sender->setHealth($newMax);

                $this->reduction[$name] = ($this->reduction[$name] ?? 0) + 2;

                $sender->setExpLevel($sender->getExpLevel() + 5);
                $this->expCache[$name] = $sender->getExpLevel();
                $this->saveAll();

                $sender->sendMessage("§a付出1颗心，获得5级经验！");
                return true;

            default:
                return false;
        }
    }

    // ---------- 工具方法 ----------

    private function isWorldDisabled(string $worldName) : bool{
        return in_array($worldName, $this->disabledWorlds, true);
    }

    private function banPlayer(Player $player) : void{
        $this->getServer()->getNameBans()->addBan($player->getName(), "生命耗尽", null, "xue插件");
        $player->kick("§c你的生命已耗尽，被永久封禁！", false);
    }
}
