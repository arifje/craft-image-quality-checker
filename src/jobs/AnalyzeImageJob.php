<?php

namespace arjanbrinkman\craftimagequalitychecker\jobs;

use arjanbrinkman\craftimagequalitychecker\ImageQualityChecker;

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

	/**
	 * Executes the image quality analysis job using ChatGPT.
	 * Checks if the asset should be analyzed, sends it to ChatGPT,
	 * parses the result, and sends notifications via Slack and/or email.
	 *
	 * @param \craft\queue\QueueInterface $queue
	 */
	public function execute($queue): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
		
		$asset = Craft::$app->assets->getAssetById($this->assetId);
		if (!$asset || $asset->kind !== 'image') {
			Craft::info("ImageQualityChecker/AnalyzeImageJob: Asset not found or not an image.", __METHOD__);
			return;
		}
				
		$volume = $asset->getVolume();
		$volumeHandle = $volume->handle ?? null;		
		$allowedHandles = $settings->allowedAssetFieldHandles;
		
		if (empty($allowedHandles)) {
			Craft::info("ImageQualityChecker/AnalyzeImageJob: No asset fields selected in settings — skipping.", __METHOD__);
			return;
		} 
		
		if (!in_array($volumeHandle, $allowedHandles, true)) {
			Craft::info("ImageQualityChecker/AnalyzeImageJob: Asset uploaded via non-selected volume '{$volumeHandle}' — skipping.", __METHOD__);
			return;
		} 
		
		$localPath = $this->getFullAssetPathById($asset->id);
		
		if (!$localPath || !file_exists($localPath)) {
			Craft::warning("ImageQualityChecker/AnalyzeImageJob: File not found for asset ID {$asset->id}", __METHOD__);
			return;
		}
		
		$imageBase64 = base64_encode(file_get_contents($localPath));
		
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
				'model' => $settings->chatGptModel,
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

		$matches = [];
		preg_match('/\\{.*\\}/s', $content, $matches);
		$data = isset($matches[0]) ? json_decode($matches[0], true) : null;

		$score = $data['score'] ?? 'Onbekend';
		$reason = $data['reason'] ?? $content;

		$imageUrl = $asset->getUrl();
		$relatedEntry = $this->getParentEntryForAsset($asset->id);

		$entryTitle = $relatedEntry?->title ?? null;
		$entryLink = $relatedEntry?->getCpEditUrl() ?? null;
		
		$author = $relatedEntry?->getAuthor()?->username
		?? ($asset->uploaderId ? Craft::$app->users->getUserById($asset->uploaderId)?->username : 'Onbekend');
			   
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
		
		$data = [
			'scoreNum' => $score,
			'scoreEmoji' => $scoreEmoji,
			'scoreLabel' => $scoreLabel,
			'author' => $author,
			'imageUrl' => $imageUrl,
			'entryLink' => $entryLink,
			'entryTitle' => $entryTitle,
			'reason' => $reason,
		];
		
		// Send notification if score of below treshhold
		if($score && $score <= $settings->notificationThreshold) {
			$this->sendSlackNotification($data);
			$this->sendEmailNotification($data);
		} 
	}

	/**
	 * Sends a Slack message with the image quality analysis results.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendSlackNotification(array $data): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
	
		if (!$settings->slackNotification || !$settings->slackBotToken || !$settings->slackChannel) {
			return;
		}
	
		$blocks = [
			[
				'type' => 'header',
				'text' => [
					'type' => 'plain_text',
					'text' => '📸 Beeldkwaliteit geanalyseerd',
					'emoji' => false
				]
			],
			[
				'type' => 'section',
				'fields' => array_filter([
					[
						'type' => 'mrkdwn',
						'text' => "*Score:*\n{$data['scoreEmoji']} *{$data['scoreNum']}/100* ({$data['scoreLabel']})"
					],
					[
						'type' => 'mrkdwn',
						'text' => "*Auteur:*\n{$data['author']}"
					],
					[
						'type' => 'mrkdwn',
						'text' => "*Afbeelding:*\n<{$data['imageUrl']}|Bekijken>"
					],
					$data['entryLink'] ? [
						'type' => 'mrkdwn',
						'text' => "*Artikel:*\n<{$data['entryLink']}|{$data['entryTitle']}>"
					] : null,
				])
			],
			/*[
				'type' => 'context',
				'elements' => [[
					'type' => 'mrkdwn',
					'text' => $data['reason']
				]]
			]*/
		];
	
		Craft::createGuzzleClient()->post('https://slack.com/api/chat.postMessage', [
			'headers' => [
				'Authorization' => 'Bearer ' . $settings->slackBotToken,
				'Content-Type' => 'application/json',
			],
			'json' => [
				'channel' => $settings->slackChannel,
				'text' => 'Beeldkwaliteit analyse',
				'blocks' => $blocks,
				'unfurl_links' => false,
				'unfurl_media' => true,
			],
		]);
	}

	/**
	 * Sends an HTML email with image quality analysis results to the author.
	 * CCs the configured recipient if set in plugin settings.
	 *
	 * @param array $data The formatted result data from the ChatGPT analysis.
	 */
	private function sendEmailNotification(array $data): void
	{
		$settings = ImageQualityChecker::getInstance()->getSettings();
	
		if (!$settings->emailNotification) {
			return;
		}
	
		$author = $data['author'] ?? null;
		$authorUser = $author ? Craft::$app->users->getUserByUsernameOrEmail($author) : null;
		$authorEmail = $authorUser?->email ?? null;
	
		if (!$authorEmail) {
			Craft::warning("ImageQualityChecker: Auteur heeft geen geldig e-mailadres, e-mail wordt niet verzonden.", __METHOD__);
			return;
		}
	
		$htmlBody = "<h2>📸 Beeldkwaliteit analyse</h2>
			<p><strong>Score:</strong> {$data['scoreEmoji']} {$data['scoreNum']}/100 ({$data['scoreLabel']})<br>
			<strong>Auteur:</strong> {$data['author']}<br>" .
			($data['entryLink'] ? "<strong>Artikel:</strong> <a href=\"{$data['entryLink']}\">{$data['entryTitle']}</a><br>" : '') .
			"</p>" .
			"<p><strong>Afbeelding:</strong><br>
				<a href=\"{$data['imageUrl']}\" target=\"_blank\">
					<img src=\"{$data['imageUrl']}\" alt=\"Geanalyseerde afbeelding\" style=\"max-width:400px; height:auto; border:1px solid #ddd;\">
				</a>
			</p>
			<p><strong>Toelichting:</strong><br>{$data['reason']}</p>";
	
		$mail = Craft::$app->getMailer()->compose()
			->setTo($authorEmail)
			->setSubject('Beeldkwaliteit analyse')
			->setHtmlBody($htmlBody);
	
		if (!empty($settings->emailNotificationRecipient)) {
			$mail->setCc($settings->emailNotificationRecipient);
		}
	
		$mail->send();
	}

	/**
	 * Attempts to find the parent entry related to the given asset ID.
	 *
	 * @param int $assetId The asset ID to search a parent entry for.
	 * @return Entry|null The related entry if found.
	 */
	private function getParentEntryForAsset(int $assetId): ?Entry
	{
		$sourceId = (new Query())
			->select(['sourceId'])
			->from(Table::RELATIONS)
			->where(['targetId' => $assetId])
			->scalar();
	
		if (!$sourceId) {
			return null;
		}
	
		$element = Craft::$app->elements->getElementById($sourceId, null, '*');
	
		if (!$element) {
			return null;
		}
	
		if ($element instanceof Entry) {
			return $element;
		}
	
		if (property_exists($element, 'ownerId') && $element->ownerId) {
			return Entry::find()
				->id($element->ownerId)
				->status(null)
				->one();
		}
	
		return null;
	}

	/**
	 * Returns the full file system path of an asset by ID.
	 *
	 * @param int $id The asset ID.
	 * @return string|null The full path or null if invalid.
	 */
	public function getFullAssetPathById(int $id): ?string
	{
		$asset = Asset::find()->id($id)->one();
	
		if (!$asset || $asset->kind !== 'image' || !in_array($asset->mimeType, ['image/jpeg', 'image/png', 'image/jpg'])) {
			return null;
		}
	
		$fsPath = Craft::getAlias($asset->getFs()->path);
		return $fsPath . DIRECTORY_SEPARATOR . $asset->folderPath . $asset->filename;
	}

	/**
	 * Returns the default description for this job.
	 *
	 * @return string
	 */
	protected function defaultDescription(): string
	{
		return 'Analyse image quality with ChatGPT';
	}
}
