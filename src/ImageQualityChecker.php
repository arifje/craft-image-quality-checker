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
use craft\services\Elements;
use craft\events\ElementEvent;
use craft\web\View;
use craft\events\TemplateEvent;
use craft\helpers\App;
use craft\helpers\ElementHelper;

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

		// Tabs (settings page)
		$this->_registerSettings();
		
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

	private function _registerSettings(): void
	{
		// Settings Template
		Event::on(View::class, View::EVENT_BEFORE_RENDER_TEMPLATE, function (
			TemplateEvent $e
		) {
			if (
				$e->template == "settings/plugins/_settings.twig"
			) {
				// Add the tabs
				$e->variables["tabs"] = [
					["label" => "ChatGPT", "url" => "#settings-tab-chatgpt"],
					["label" => "Notifications", "url" => "#settings-tab-notifications"],									
					["label" => "Volumes", "url" => "#settings-tab-volumes"],
				];
			}
		});
	}
	
	private function attachEventHandlers(): void
	{
		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function(ElementEvent $event) {
			$element = $event->element;
		
			if (!$element instanceof Asset || $element->kind !== 'image' || !$event->isNew) {
				return;
			}
			
			/*$user = Craft::$app->getUser()->getIdentity();		
			Craft::info("ImageQualityChecker event, user id: " . $user->id);
			if($user->id != 1) {
				return;
			}*/
			
			// Push to a job
			Craft::$app->queue->delay(10)->push(new AnalyzeImageJob([
				'assetId' => $element->id,
			]));
		});
	}
	
}
