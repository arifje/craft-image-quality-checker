<?php

namespace arjanbrinkman\craftimagequalitychecker\models;

use Craft;
use craft\base\Model;

/**
 * Image Quality Checker settings
 */
class Settings extends Model
{
	public string $chatGptApiKey = '';
	public string $slackWebhookUrl = '';
 	public string $slackChannel = '';
	 
	public function rules(): array
	{
		return [
			[['chatGptApiKey', 'slackWebhookUrl', 'slackChannel'], 'string'],
		];
	}
	
	public function toArray($fields = [], $expand = true, $recursive = true): array
	{
		return [
			'chatGptApiKey' => $this->chatGptApiKey,
			'slackWebhookUrl' => $this->slackWebhookUrl,
			'slackChannel' => $this->slackChannel,
		];
	}
 }