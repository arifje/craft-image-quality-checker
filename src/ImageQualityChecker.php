<?php

namespace arjanbrinkman\craftimagequalitychecker;

use Craft;
use arjanbrinkman\craftimagequalitychecker\models\Settings;
use arjanbrinkman\craftimagequalitychecker\services\ImageQualityService;
use arjanbrinkman\craftimagequalitychecker\jobs\AnalyzeImageJob;
use arjanbrinkman\craftimagequalitychecker\assetbundles\ImageQualityCheckerAsset;

use yii\base\Event;

use craft\base\Model;
use craft\base\Plugin;
use craft\elements\Asset;
use craft\events\ModelEvent;

/**
 * Image Quality Checker plugin
 *
 * @method static ImageQualityChecker getInstance()
 * @method Settings getSettings()
 */
class ImageQualityChecker extends Plugin
{
	public string $schemaVersion = '1.0.0';
	public bool $hasCpSettings = true;

	public static function config(): array
	{
		return [
			'components' => [
				'imageQualityService' => ImageQualityService::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();

		// Register HUD message if flash exists
		if (Craft::$app->getRequest()->getIsCpRequest() && Craft::$app->getSession()->hasFlash('imageQualityModalWarning')) {
			$flash = Craft::$app->getSession()->getFlash('imageQualityModalWarning');
			Craft::$app->getView()->registerAssetBundle(ImageQualityCheckerAsset::class);
			Craft::$app->getView()->registerJs("window.imageQualityModalMessage = " . $flash . ";", \yii\web\View::POS_HEAD);
		}

		$this->attachEventHandlers();

		Craft::$app->onInit(function() {
			// Reserved for deferred code (element queries, etc.)
		});
	}

	protected function createSettingsModel(): ?Model
	{
		return Craft::createObject(Settings::class);
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->view->renderTemplate('_image-quality-checker/_settings.twig', [
			'plugin' => $this,
			'settings' => $this->getSettings(),
		]);
	}

	private function attachEventHandlers(): void
	{
		Event::on(Asset::class, Asset::EVENT_AFTER_SAVE, function(ModelEvent $event) {

			/** @var Asset $asset */
			$asset = $event->sender;
			$volumeHandle = $asset->getVolume()->handle;

			// Only analyze new images
			if ($asset->kind !== 'image' || !$event->isNew) {
				return;
			}
		
			$settings = $this->getSettings();
			$allowedHandles = $settings->allowedAssetFieldHandles;
			
			Craft::info("ImageQualityChecker: " . $volumeHandle);
			
			// If no allowed fields are selected, skip
			if (empty($allowedHandles)) {
				Craft::info("ImageQualityChecker: No asset fields selected in settings — skipping.", __METHOD__);
				return;
			}
			
			
			// If field handle not in allowed list, skip
			if (!in_array($volumeHandle, $allowedHandles, true)) {
				Craft::info("ImageQualityChecker: Asset uploaded via non-selected field '{$volumeHandle}' — skipping.", __METHOD__);
				return;
			}
			
			// Queue the job
			Craft::$app->queue->push(new AnalyzeImageJob([
				'assetId' => $asset->id,
			]));
		});
	}
	
}
