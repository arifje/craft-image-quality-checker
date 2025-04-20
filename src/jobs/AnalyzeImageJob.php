<?php

namespace arjanbrinkman\craftimagequalitychecker\jobs;
use Craft;
use craft\queue\BaseJob;
use craft\elements\Asset;
use GuzzleHttp\Client;
use yii\base\Exception;
use craft\elements\Entry;
use craft\elements\User;
use craft\base\ElementInterface;
use craft\db\Query;
use craft\db\Table;
use craft\elements\db\EntryQuery;

class AnalyzeImageJob extends BaseJob
{
	public int $assetId;

	public function execute($queue): void
	{
		$asset = Craft::$app->assets->getAssetById($this->assetId);
		if (!$asset || $asset->kind !== 'image') {
			Craft::warning("AnalyzeImageJob: Asset not found or not an image.", __METHOD__);
			return;
		}
		
		$localPath = $this->getFullAssetPathById($asset->id);
		
		if (!$localPath || !file_exists($localPath)) {
			Craft::warning("AnalyzeImageJob: File not found for asset ID {$asset->id}", __METHOD__);
			return;
		}
		
		$imageBase64 = base64_encode(file_get_contents($localPath));

		$settings = \arjanbrinkman\craftimagequalitychecker\ImageQualityChecker::getInstance()->getSettings();
		$apiKey = $settings->chatGptApiKey;
		$webhook = $settings->slackWebhookUrl;

		if (!$apiKey || !$webhook) {
			Craft::warning("AnalyzeImageJob: API key or Slack webhook missing in settings.", __METHOD__);
			return;
		}

		$client = Craft::createGuzzleClient();
		$mime = $asset->mimeType;
		$response = $client->post('https://api.openai.com/v1/chat/completions', [
			'headers' => [
				'Authorization' => 'Bearer ' . $apiKey,
				'Content-Type'  => 'application/json',
			],
			'json' => [
				'model' => 'gpt-4-turbo',
				'messages' => [[
					'role' => 'user',
					'content' => [
						['type' => 'text', 'text' => $settings->chatGptPrompt . '. Return a JSON object without any other data, markup or styling. Example: {"score": X, "reason": "..."}. Translate the value of reason to ' . $settings->chatGptResultLanguage . '.'],
						['type' => 'image_url', 'image_url' => ['url' => 'data:' . $mime . ';base64,' . $imageBase64]],
					]
				]],
				'max_tokens' => 500,
			],
		]);

		$json = json_decode((string) $response->getBody(), true);
		$content = $json['choices'][0]['message']['content'] ?? null;

		if (!$content) {
			Craft::error("AnalyzeImageJob: No response from ChatGPT.", __METHOD__);
			return;
		}

		// Attempt to extract JSON
		$matches = [];
		preg_match('/\\{.*\\}/s', $content, $matches);
		$data = isset($matches[0]) ? json_decode($matches[0], true) : null;

		$score = $data['score'] ?? 'Onbekend';
		$reason = $data['reason'] ?? $content;

		// Get absolute URL to the image
		$imageUrl = $asset->getUrl(); // Make sure your volumes are public or generate signed URLs
		
		// Try to find related entry (e.g. via asset field)
		$relatedEntry = $this->getParentEntryForAsset($asset->id);

		$entryTitle = $relatedEntry?->title ?? null;
		$entryLink = $relatedEntry?->getCpEditUrl() ?? null;
		
		$author = $relatedEntry?->getAuthor()?->username
		?? ($asset->uploaderId ? Craft::$app->users->getUserById($asset->uploaderId)?->username : 'Onbekend');
		   	
		// Send to Slack
		// Score evaluation
		$scoreEmoji = '❓';
		$scoreLabel = 'Onbekend';
		$scoreNum = (int) $score;
		
		if ($scoreNum <= 39) {
			$scoreEmoji = '🔴';
			$scoreLabel = 'Slecht';
		} elseif ($scoreNum <= 59) {
			$scoreEmoji = '🟠';
			$scoreLabel = 'Matig';
		} elseif ($scoreNum <= 79) {
			$scoreEmoji = '🟡';
			$scoreLabel = 'Goed';
		} elseif ($scoreNum <= 100) {
			$scoreEmoji = '🟢';
			$scoreLabel = 'Uitstekend';
		}
		
		$blocks = [
			[
				'type' => 'header',
				'text' => [
					'type' => 'plain_text',
					'text' => '📸 Beeldkwaliteit geanalyseerd van afbeelding',
					'emoji' => false
				]
			],
			[
				'type' => 'section',
				'fields' => array_filter([
					[
						'type' => 'mrkdwn',
						'text' => "*Score:*\n{$scoreEmoji} *{$scoreNum}/100* ({$scoreLabel})"
					],
					[
						'type' => 'mrkdwn',
						'text' => "*Auteur:*\n{$author}"
					],
					[
						'type' => 'mrkdwn',
						'text' => "*Afbeelding:*\n<{$imageUrl}|Bekijken>"
					],
					$entryLink ? [
						'type' => 'mrkdwn',
						'text' => "*Artikel:*\n<{$entryLink}|{$entryTitle}>"
					] : null,
				])
			],
			[
				'type' => 'context',
				'elements' => [[
					'type' => 'mrkdwn',
					'text' => $reason
				]]
			]
		];
	
		$client->post('https://slack.com/api/chat.postMessage', [
			'headers' => [
				'Authorization' => 'Bearer ' . $settings->slackBotToken,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'channel' => $settings->slackChannel, 
				'text' => 'Beeldkwaliteit analyse',
				'blocks' => $blocks,
			],
		]);
	}
	
	private function getParentEntryForAsset(int $assetId): ?Entry
	{
		// Step 1: Find the element that references the asset
		$sourceId = (new Query())
			->select(['sourceId'])
			->from(Table::RELATIONS)
			->where(['targetId' => $assetId])
			->scalar();
	
		if (!$sourceId) {
			return null;
		}
	
		// Step 2: Get the related element (could be Entry or Matrix block subclass)
		/** @var ElementInterface|null $element */
		$element = Craft::$app->elements->getElementById($sourceId, null, '*');
	
		if (!$element) {
			return null;
		}
	
		// Step 3: If it's an Entry, return it
		if ($element instanceof Entry) {
			return $element;
		}
	
		// Step 4: If it's a Matrix block (Craft 5 uses custom classes), check if it has an ownerId
		if (property_exists($element, 'ownerId') && $element->ownerId) {
			return Entry::find()
				->id($element->ownerId)
				->status(null)
				->one();
		}
	
		return null;
	}

	public function getFullAssetPathById(int $id): ?string
	{
		$asset = Asset::find()->id($id)->one();
	
		if (!$asset || $asset->kind !== 'image' || !in_array($asset->mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
			return null;
		}
	
		$fsPath = Craft::getAlias($asset->getFs()->path);
		return $fsPath . DIRECTORY_SEPARATOR . $asset->folderPath . $asset->filename;
	}
	
	protected function defaultDescription(): string
	{
		return 'Beeldkwaliteit analyseren met ChatGPT';
	}
}