<?php

namespace arjanbrinkman\craftimagequalitychecker\models;

use Craft;
use craft\base\Model;

/**
 * Image Quality Checker settings
 */
class Settings extends Model
{
	// ChatGPT
	public string $chatGptApiKey = '';
	public string $chatGptPrompt = 'You are an expert in image quality. Evaluate this image from 1 (very bad) to 100 (excellent), considering sharpness, blur, noise, and motion blur.';
	public string $chatGptResultLanguage = 'Dutch';
	public string $chatGptModel = 'gpt-4-turbo';
	
	// Slack notification
	public bool $slackNotification = true;
	public string $slackWebhookUrl = '';
	public string $slackBotToken = ''; // Required for postMessage method
 	public string $slackChannel = '';
	
	// Email notification
	public bool $emailNotification = false;
	public string $emailNotificationRecipient = '';
	 
	public int $notificationThreshold = 50;
	
	// Enabled volume handles
	public array $allowedAssetFieldHandles = [];

	public function rules(): array
	{
		return [
			[['chatGptApiKey', 'slackWebhookUrl', 'slackChannel','chatGptResultLanguage','slackBotToken'], 'string'],
			[['allowedAssetFieldHandles'], 'safe'],
		];
	}
	
 }