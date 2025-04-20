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
	public string $chatGptPrompt = 'You are an expert in image quality. Evaluate this image from 1 (very bad) to 100 (excellent), considering sharpness, blur, noise, and motion blur.';
	public string $chatGptResultLanguage = 'Dutch';
	public string $slackWebhookUrl = '';
 	public string $slackChannel = '';
	public array $allowedAssetFieldHandles = [];

	public function rules(): array
	{
		return [
			[['chatGptApiKey', 'slackWebhookUrl', 'slackChannel','chatGptResultLanguage'], 'string'],
			[['allowedAssetFieldHandles'], 'safe'],
		];
	}
	
 }