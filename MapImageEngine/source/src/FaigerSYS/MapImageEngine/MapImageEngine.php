<?php
namespace FaigerSYS\MapImageEngine;

use pocketmine\plugin\PluginBase;

use pocketmine\utils\TextFormat as CLR;

use pocketmine\item\Item;
use FaigerSYS\MapImageEngine\item\FilledMap as FilledMapItem;

use pocketmine\tile\ItemFrame;

use pocketmine\event\Listener;
use pocketmine\event\server\DataPacketReceiveEvent;
use pocketmine\event\level\ChunkLoadEvent;

use pocketmine\network\mcpe\protocol\PacketPool;

use pocketmine_backtrace\MapInfoRequestPacket;
use pocketmine_backtrace\ClientboundMapItemDataPacket;

use FaigerSYS\MapImageEngine\TranslateStrings as TS;
use FaigerSYS\MapImageEngine\storage\ImageStorage;
use FaigerSYS\MapImageEngine\command\MapImageEngineCommand;

class MapImageEngine extends PluginBase implements Listener {
	
	/** @var MapImageEngine */
	private static $instance;
	
	/** @var ImageStorage */
	private $storage;
	
	public function onEnable() {
		$old_plugin = self::$instance;
		self::$instance = $this;
		$is_reload = ($old_plugin instanceof MapImageEngine);
		
		TS::init();
		
		$this->getLogger()->info(CLR::GOLD . TS::translate($is_reload ? 'plugin-loader.reloading' : 'plugin-loader.loading'));
		$this->getLogger()->info(CLR::GOLD . TS::translate('plugin-loader.info-instruction'));
		$this->getLogger()->info(CLR::GOLD . TS::translate('plugin-loader.info-long-loading'));
		
		if ($old_plugin) {
			$this->storage = $old_plugin->storage;
		}
		
		$this->getServer()->getPluginManager()->registerEvents($this, $this);
		
		$this->registerItems();
		$this->registerPackets();
		$this->registerCommands();
		
		@mkdir($path = $this->getDataFolder());
		
		@mkdir($dir = $path . 'instructions/');
		foreach (scandir($r_dir = $this->getFile() . '/resources/instructions/') as $file) {
			if ($file[0] !== '.') {
				copy($r_dir . $file, $dir . $file);
			}
		} 
		
		@mkdir($path . 'images');
		@mkdir($path . 'cache');
		
		$this->loadImages($is_reload);
		
		$this->getLogger()->info(CLR::GOLD . TS::translate($is_reload ? 'plugin-loader.reloaded' : 'plugin-loader.loaded'));
	}
	
	private function registerCommands() {
		$this->getServer()->getCommandMap()->register('mapimageengine', new MapImageEngineCommand);
	}
	
	private function registerItems() {
		if (method_exists(Item::class, 'registerItem')) {
			Item::registerItem(new FilledMapItem);
		} else {
			foreach (Item::$list as $item) {
				if ($item !== null) {
					if (is_array($item)) {
						Item::$list[Item::FILLED_MAP ?? 358][0] = is_string(reset($item)) ? FilledMapItem::class : new FilledMapItem;
					} else {
						Item::$list[Item::FILLED_MAP ?? 358] = is_string($item) ? FilledMapItem::class : new FilledMapItem;
					}
					break;
				}
			}
		}
	}
	
	private function registerPackets() {
		try {
			if (class_exists(PacketPool::class)) {
				PacketPool::registerPacket(new MapInfoRequestPacket);
				PacketPool::registerPacket(new ClientboundMapItemDataPacket);
			} else {
				throw \Exception;
			}
		} catch (\Throwable $e) {
			$this->getServer()->getNetwork()->registerPacket(MapInfoRequestPacket::NETWORK_ID, MapInfoRequestPacket::class);
			$this->getServer()->getNetwork()->registerPacket(ClientboundMapItemDataPacket::NETWORK_ID, ClientboundMapItemDataPacket::class);
		}
	}
	
	private function loadImages(bool $is_reload = false) {
		$path = $this->getDataFolder() . 'images/';
		$storage = $this->storage ?? new ImageStorage;
		
		$files = array_filter(
			scandir($path),
			function ($file) {
				return substr($file, -4, 4) === '.mie';
			}
		);
		
		foreach ($files as $file) {
			$state = $storage->addImage($path . $file, substr($file, 0, -4), true);
			switch ($state) {
				case ImageStorage::STATE_NAME_EXISTS:
					!$is_reload && $this->getLogger()->warning(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-name-exists'));
					break;
				
				case ImageStorage::STATE_IMAGE_EXISTS:
					!$is_reload && $this->getLogger()->warning(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-image-exists'));
					break;
				
				case ImageStorage::STATE_CORRUPTED:
					$this->getLogger()->warning(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-corrupted'));
					break;
				
				case ImageStorage::STATE_UNSUPPORTED_API:
					$this->getLogger()->warning(TS::translate('image-loader.prefix', $file) . TS::translate('image-loader.err-unsupported-api'));
					break;
			}
		}
		
		$this->storage = $storage;
	}
	
	public function getImageStorage() {
		return $this->storage;
	}
	
	/**
	 * @priority LOW
	 */
	public function onRequest(DataPacketReceiveEvent $e) {
		if ($e->getPacket() instanceof MapInfoRequestPacket) {
			$this->getImageStorage()->sendImage($e->getPacket()->mapId, $e->getPlayer());
			$e->setCancelled(true);
		}
	}
	
	/**
	 * @priority LOW
	 */
	public function onChunkLoad(ChunkLoadEvent $e) {
		foreach ($e->getChunk()->getTiles() as $frame) {
			if ($frame instanceof ItemFrame) {
				$item = $frame->getItem();
				if ($item instanceof FilledMapItem) {
					$frame->setItem($item);
				}
			}
		}
	}
	
	public static function getInstance() {
		return self::$instance;
	}
	
}
